<?php
require_once __DIR__ . '/GraderInterface.php';

class PythonGrader implements GraderInterface
{
    #[Override]
    public function grade(array $submission, array $problem, array $testCases = []): array
    {
        $submission_id = $submission['id'];
        $code = $submission['code'];
        $tempFiles = [];

        if (empty($testCases)) {
            return [
                'status' => '틀렸습니다',
                'execution_time' => 0,
                'error' => '서버에 테스트 케이스가 등록되지 않음.',
                'temp_files' => [],
                'temp_dirs' => []
            ];
        }

        $tempDir = dirname(__DIR__, 2) . '/scratch';
        if (!is_dir($tempDir))
            mkdir($tempDir, 0777, true);

        $tempFile = $tempDir . "/temp_" . $submission_id . ".py";
        $tempFiles[] = $tempFile;
        file_put_contents($tempFile, $code);

        // 윈도우 XAMPP 환경의 경로를 Docker가 마운트하기 쉽게 슬래시로 변경
        $tempDirDocker = str_replace('\\', '/', $tempDir);
        $status = '정답';
        $error_msg = null;
        $max_exec_time = 0;

        foreach ($testCases as $tc) {
            $input = $tc['input_data'];
            $expectedOutput = trim($tc['output_data']);

            $descriptorspec = [
                0 => ["pipe", "r"], // stdin
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"], // stderr
            ];

            // Docker 컨테이너로 유저 코드 격리 실행
            // 사용 이미지: python:3.0=alpine
            $containerName = "sandbox_py_" . $submission_id . "_" . bin2hex(random_bytes(4));
            $memLimit = $submission['memory_limit'] ? max(16, (int)$submission['memory_limit']) : 128;

            $dockerCmd = sprintf(
                'docker run --rm -i ' .
                    '--name "%s" ' .
                    '--net none ' .
                    '--user nobody ' .
                    '--memory="%dm" ' .
                    '--cpus="1.0" ' .
                    '-v "%s:/sandbox:ro" ' .
                    'python:3.9-alpine ' .
                    'python /sandbox/%s',
                $containerName,
                $memLimit,
                $tempDirDocker,
                "temp_" . $submission_id . ".py"
            );

            // proc_open으로 도커 프로세스 실행
            $process = proc_open($dockerCmd, $descriptorspec, $pipes);

            if (!is_resource($process)) {
                return [
                    'status' => '런타임 에러',
                    'execution_time' => 0,
                    'error' => '도커 실행 실패.',
                    'temp_files' => $tempFiles,
                    'temp_dirs' => []
                ];
            }

            // 입력값(표준 입력) 주입
            fwrite($pipes[0], $input);
            fclose($pipes[0]);

            $startTime = microtime(true);
            // 도커 컨테이너 실행에 약간의 오버헤드가 있으므로 기본 타임아웃에 1초 여유를 더해줌
            $timeout = ($submission['time_limit'] ? (float)$submission['time_limit'] : 2.0) + 1.0;
            $isTimeout = false;

            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);

            $output = '';
            $error = '';
            $maxBytes = 1048576;

            while (true) {
                // 1. 데드락 방지: 루프를 돌 때마다 파이프 버퍼를 지속적으로 퍼내기
                $outChunk = stream_get_contents($pipes[1]);
                if ($outChunk !== false) {
                    $output .= $outChunk;
                    // OCM 방지: 1MB 초과 시 즉시 잘라내기
                    if (strlen($output) > $maxBytes)
                        $output = substr($output, 0, $maxBytes);
                }

                $errChunk = stream_get_contents($pipes[2]);
                if ($errChunk !== false) {
                    $error .= $errChunk;
                    if (strlen($error) > $maxBytes)
                        $error = substr($error, 0, $maxBytes);
                }

                // 2. 프로세스 상태 확인
                $statusArr = proc_get_status($process);
                $runningTime = microtime(true) - $startTime;

                if (!$statusArr['running']) {
                    break;
                }
                if ($runningTime > $timeout) {
                    // 타임아웃 시 도커 프로세스 강제 종료
                    proc_terminate($process);
                    // 백그라운드의 실제 Docker 데몬 컨테이너를 명시적으로 kill
                    shell_exec("docker kill " . escapeshellarg($containerName) . " >/dev/null 2>&1");

                    $isTimeout = true;
                    break;
                }
                usleep(10000);
            }

            // 3. 루프 종료 후 찰나의 순간에 버퍼에 남은 잔여 데이터 최종 수거
            $outChunk = stream_get_contents($pipes[1]);
            if ($outChunk !== false)
                $output .= $outChunk;
            $errChunk = stream_get_contents($pipes[2]);
            if ($errChunk !== false)
                $error .= $errChunk;

            // 최종 OOM 방지 및 공백 제거
            if (strlen($output) > $maxBytes)
                $output = substr($output, 0, $maxBytes);
            if (strlen($error) > $maxBytes)
                $error = substr($error, 0, $maxBytes);

            $output = trim($output);
            $error = trim($error);

            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            // 실행 시간 계산
            $exec_duration = (int)((microtime(true) - $startTime) * 1000);
            $max_exec_time = max($max_exec_time, $exec_duration);

            // 리소스 회수를 먼저 완료한 뒤에 타임아웃 예외를 처리
            if ($isTimeout) {
                $status = '시간 초과';
                break;
            }

            if (isset($statusArr['exitcode']) && $statusArr['exitcode'] !== 0) {
                $status = '런타임 에러';
                $error_msg = $error ?: "Exit code: " . $statusArr['exitcode'];
                break;
            }

            if (str_replace("\r", "", $output) !== str_replace("\r", "", $expectedOutput)) {
                $status = '틀렸습니다';
                break;
            }
        }

        return [
            'status' => $status,
            'execution_time' => $max_exec_time,
            'error' => $error_msg,
            'temp_files' => $tempFiles,
            'temp_dirs' => []
        ];
    }
}
