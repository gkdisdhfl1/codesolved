<?php
require_once __DIR__ . '/GraderInterface.php';

class SqlGrader implements GraderInterface
{
    #[Override]
    public function grade(array $submission, array $testCases = []): array
    {
        $submission_id = $submission['id'];
        $code = $submission['code'];
        $correctSql = trim($submission['answer_query']);
        $problem_id = $submission['problem_id'];
        $tempDirs = [];

        // 1. 해당 문제용 초기화 SQL 파일 경로 확인 (예: data/sql_init/4.sql)
        $initSqlSourcePath = dirname(__DIR__, 2) . '/data/sql_init/' . $problem_id . '.sql';
        if (empty($correctSql))
            return [
                'status' => '런타임 에러',
                'execution_time' => 0,
                'error' => '정답 쿼리가 없음',
                'temp_files' => [],
                'temp_dirs' => []
            ];
        if (!file_exists($initSqlSourcePath))
            return [
                'status' => '런타임 에러',
                'execution_time' => 0,
                'error' => '초기화 파일 없음',
                'temp_files' => [],
                'temp_dirs' => []
            ];

        // 2. 임시 작업 디렉토리 생성
        $tempSqlDir = dirname(__DIR__, 2) . '/scratch/sql_' . $submission_id;
        if (!is_dir($tempSqlDir)) {
            mkdir($tempSqlDir, 0777, true);
        }
        $tempDirs[] = $tempSqlDir;

        // 3. 서버에 저장된 초기화 파일을 임시 디렉토리의 init.sql로 복사
        copy($initSqlSourcePath, $tempSqlDir . '/init.sql');

        // 4. 유저 쿼리와 정답 쿼리를 각각 파일로 저장 (JSON 포맷으로 출력하도록 설정)
        file_put_contents($tempSqlDir . '/user_query.sql', $code);
        file_put_contents($tempSqlDir . '/correct_query.sql', $correctSql);

        // Docker 내부에서 PDO로 SQL을 안전하게 채점할 PHP Runner 파일 임시 폴더로 복사
        copy(dirname(__DIR__) . '/sqlite_runner.php', $tempSqlDir . '/sqlite_runner.php');

        // Windows 경로 슬래시 보정
        $tempSqlDirDocker = str_replace('\\', '/', $tempSqlDir);
        $max_exec_time = 0;

        // 5. 안전한 Docker SQLite3 실행 헬퍼 함수
        $executeInDocker = function ($queryFile, $timeLimit) use ($tempSqlDirDocker, $submission_id, $submission) {
            $descriptorspec = [
                1 => ["pipe", "w"], // stdout
                2 => ["pipe", "w"]  // stderr
            ];

            // SQL 컨테이너에도 고유한 이름 부여
            $containerName = "sandbox_sql_" . $submission_id . "_" . bin2hex(random_bytes(4));

            $memLimit = $submission['memory_limit'] ? max(16, (int)$submission['memory_limit']) : 128;

            $dockerCmd = sprintf(
                'docker run --rm -i ' .
                    '--name "%s" ' .
                    '--net none ' .
                    '--user nobody ' .
                    '--memory="%dm" ' .
                    '--cpus="1.0" ' .
                    '-v "%s:/sandbox:ro" ' .
                    'php:8-cli-alpine ' .
                    'php /sandbox/sqlite_runner.php "%s"',
                $containerName,
                $memLimit,
                $tempSqlDirDocker,
                $queryFile
            );

            $process = proc_open($dockerCmd, $descriptorspec, $pipes);
            if (!is_resource($process))
                return ['output' => '', 'error' => 'Docker 실행 실패'];

            $startTime = microtime(true);
            $timeout = ($timeLimit ? (float)$timeLimit : 2.0) + 1.0;
            $isTimeout = false;

            // 논블로킹 모드 전환
            stream_set_blocking($pipes[1], 0);
            stream_set_blocking($pipes[2], 0);

            $output = '';
            $error = '';
            $maxBytes = 1048576;

            while (true) {
                $outChunk = stream_get_contents($pipes[1]);
                if ($outChunk !== false) {
                    $output .= $outChunk;
                    if (strlen($output) > $maxBytes)
                        $output = substr($output, 0, $maxBytes);
                }

                $errChunk = stream_get_contents($pipes[2]);
                if ($errChunk !== false) {
                    $error .= $errChunk;
                    if (strlen($error) > $maxBytes)
                        $error = substr($error, 0, $maxBytes);
                }

                $statusArr = proc_get_status($process);
                $runningTime = microtime(true) - $startTime;

                if (!$statusArr['running'])
                    break;

                if ($runningTime > $timeout) {
                    proc_terminate($process);
                    // SQL Docker 컨테이너 실제 kill
                    shell_exec("docker kill " . escapeshellarg($containerName) . " >/dev/null 2>&1");
                    $isTimeout = true;
                    break;
                }
                usleep(10000);
            }

            // 잔여 버퍼 최종 수거
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

            if ($isTimeout)
                return ['output' => '', 'error' => '시간 초과'];


            $exitCode = isset($statusArr['exitcode']) ? $statusArr['exitcode'] : 0;
            if ($exitCode !== 0 && empty($error)) {
                $error = "Exit code: " . $exitCode;
            }

            return ['output' => $output, 'error' => $error];
        };

        $startTime = microtime(true);

        // 6. 정답 쿼리 및 유저 쿼리 각각 격리 환경에서 실행 (스칼라 값은 clone 없이 복사 전달)
        $correctRes = $executeInDocker('correct_query.sql', $submission['time_limit']);
        $userRes = $executeInDocker('user_query.sql', $submission['time_limit']);

        $exec_duration = (int)((microtime(true) - $startTime) * 1000);
        $max_exec_time = max($max_exec_time, $exec_duration);

        // 7. 에러 및 결과 대조
        if (!empty($correctRes['error']))
            return [
                'status' => '런타임 에러',
                'execution_time' => $max_exec_time,
                'error' => "정답 쿼리 실행 실패: " . trim($correctRes['error']),
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];
        if (!empty($userRes['error']))
            return [
                'status' => '런타임 에러',
                'execution_time' => $max_exec_time,
                'error' => trim($userRes['error']),
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];

        $correctArray = json_decode($correctRes['output'], true);
        $correctJsonError = json_last_error();
        $userArray = json_decode($userRes['output'], true);
        $userJsonError = json_last_error();

        if ($correctJsonError !== JSON_ERROR_NONE)
            return [
                'status' => '런타임 에러',
                'execution_time' => $max_exec_time,
                'error' => "정답 쿼리 포맷 에러",
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];
        if ($userJsonError !== JSON_ERROR_NONE && trim($userRes['output']) !== '')
            return [
                'status' => '런타임 에러',
                'execution_time' => $max_exec_time,
                'error' => "JSON Parsing Error",
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];
        if ($userArray !== $correctArray)
            return [
                'status' => '틀렸습니다',
                'execution_time' => $max_exec_time,
                'error' => null,
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];

        return [
                'status' => '정답',
                'execution_time' => $max_exec_time,
                'error' => null,
                'temp_files' => [],
                'temp_dirs' => $tempDirs
            ];
    }
}
