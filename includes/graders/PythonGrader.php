<?php
require_once __DIR__ . '/GraderInterface.php';

class PythonGrader implements GraderInterface
{
    #[Override]
    public function grade(array $submission, array $testCases = []): array
    {
        $submission_id = $submission['id'];
        $code = $submission['code'];
        $tempFiles = [];

        try {
            if (empty($testCases)) {
                return [
                    'status' => '틀렸습니다',
                    'execution_time' => 0,
                    'error' => '서버에 테스트 케이스가 등록되지 않음.'
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

                // 파이프 데드락 방지: stdout과 stderr를 임시 파일로 직접 저장
                $outFile = $tempDir . "/out_" . $submission_id . "_" . bin2hex(random_bytes(4)) . ".log";
                $errFile = $tempDir . "/err_" . $submission_id . "_" . bin2hex(random_bytes(4)) . ".log";
                $tempFiles[] = $outFile;
                $tempFiles[] = $errFile;

                $descriptorspec = [
                    0 => ["pipe", "r"], // stdin만 파이프로 유지
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
                        'sh -c "python /sandbox/%s 2>&1 | head -c 1048576" > "%s"',
                    $containerName,
                    $memLimit,
                    $tempDirDocker,
                    "temp_" . $submission_id . ".py",
                    $outFile
                );

                // proc_open으로 도커 프로세스 실행
                $process = proc_open($dockerCmd, $descriptorspec, $pipes);

                if (!is_resource($process)) {
                    return [
                        'status' => '런타임 에러',
                        'execution_time' => 0,
                        'error' => '도커 실행 실패.',
                    ];
                }

                // 입력값(표준 입력) 주입
                fwrite($pipes[0], $input);
                fclose($pipes[0]);

                $startTime = microtime(true);
                // 도커 컨테이너 실행에 약간의 오버헤드가 있으므로 기본 타임아웃에 1초 여유를 더해줌
                $timeout = ($submission['time_limit'] ? (float)$submission['time_limit'] : 2.0) + 1.0;
                $isTimeout = false;
                $isOLE = false;
                $maxBytes = 1048576;

                while (true) {
                    clearstatcache();
                    // 파일의 실시간 용량을 확인하여 출력 크기 초과 모니터링
                    $outSize = file_exists($outFile) ? filesize($outFile) : 0;
                    $errSize = file_exists($errFile) ? filesize($errFile) : 0;

                    if ($outSize > $maxBytes || $errSize > $maxBytes) {
                        $isOLE = true;
                        proc_terminate($process);
                        shell_exec("docker kill " . escapeshellarg($containerName) . " >/dev/null 2>&1");
                        break;
                    }

                    $statusArr = proc_get_status($process);
                    $runningTime = microtime(true) - $startTime;

                    if (!$statusArr['running']) {
                        break;
                    }
                    if ($runningTime > $timeout) {
                        $isTimeout = true;
                        proc_terminate($process);
                        shell_exec("docker kill " . escapeshellarg($containerName) . " >/dev/null 2>&1");
                        break;
                    }
                    usleep(10000);
                }

                proc_close($process);

                // OS 파일에서 결과를 안전하게 읽어오기
                $output = file_exists($outFile) ? file_get_contents($outFile) : '';
                $error = file_exists($errFile) ? file_get_contents($errFile) : '';

                // head -c 1048576 에 의해 정확히 1MB(또는 그 언저리)에서 잘렸다면 무한 출력으로 간주
                $isOLE = (strlen($output) >= 1000000); 

                // 최종 OOM 방지 및 공백 제거
                if (strlen($output) > $maxBytes)
                    $output = substr($output, 0, $maxBytes);
                if (strlen($error) > $maxBytes)
                    $error = substr($error, 0, $maxBytes);

                $output = trim($output);
                $error = trim($error);

                // 실행 시간 계산
                $exec_duration = (int)((microtime(true) - $startTime) * 1000);
                $max_exec_time = max($max_exec_time, $exec_duration);

                // 리소스 회수를 먼저 완료한 뒤에 타임아웃 예외를 처리
                // 출력 폭주($isOLE)는 무한 루프(시간 초과)로 간주
                if ($isOLE) {
                    $status = '시간 초과';
                    $error_msg = null;
                    break;
                }   
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
            ];
        } finally {
            $scratchDir = dirname(__DIR__, 2) . '/scratch';
            if (is_dir($scratchDir)) {
                $now = time();
                foreach (glob($scratchDir . '/*') as $f) {
                    if (is_file($f) && ($now - filemtime($f)) > 300) @unlink($f);
                    if (is_dir($f) && ($now - filemtime($f)) > 300) {
                        foreach (glob("$f/*") as $sub) @unlink($sub);
                        @rmdir($f);
                    }
                }
            }
            foreach ($tempFiles as $file) {
                if (file_exists($file)) {
                    @unlink($file);
                }
            }
        }
    }
}
