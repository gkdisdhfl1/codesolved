<?php
require_once __DIR__ . '/GraderInterface.php';

class SqlGrader implements GraderInterface
{
    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }
    #[Override]
    public function grade(array $submission, array $testCases = []): array
    {
        $submission_id = $submission['id'];
        $code = $submission['code'];
        $correctSql = trim($submission['answer_query']);
        $problem_id = $submission['problem_id'];
        $tempDirs = [];


        try {
            // 1. 해당 문제용 초기화 SQL 파일 경로 확인 (예: data/sql_init/4.sql)
            $initSqlSourcePath = dirname(__DIR__, 2) . '/data/sql_init/' . $problem_id . '.sql';
            if (empty($correctSql))
                return [
                    'status' => '런타임 에러',
                    'execution_time' => 0,
                    'error' => '정답 쿼리가 없음',
                ];
            if (!file_exists($initSqlSourcePath))
                return [
                    'status' => '런타임 에러',
                    'execution_time' => 0,
                    'error' => '초기화 파일 없음',
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
            $executeInDocker = function ($queryFile, $timeLimit) use ($tempSqlDirDocker, $submission_id, $submission, $tempSqlDir) {
                // stdin만 파이프로 넘기고 stdout/stderr는 파일로 리다이렉션
                $descriptorspec = [0 => ["pipe", "r"],];

                // SQL 컨테이너에도 고유한 이름 부여
                $containerName = "sandbox_sql_" . $submission_id . "_" . bin2hex(random_bytes(4));
                $memLimit = $submission['memory_limit'] ? max(16, (int)$submission['memory_limit']) : 128;

                $outFile = $tempSqlDir . "/out_" . bin2hex(random_bytes(4)) . ".log";
                $errFile = $tempSqlDir . "/err_" . bin2hex(random_bytes(4)) . ".log";

                $dockerCmd = sprintf(
                    'docker run --rm -i ' .
                        '--name "%s" ' .
                        '--net none ' .
                        '--user nobody ' .
                        '--memory="%dm" ' .
                        '--cpus="1.0" ' .
                        '-v "%s:/sandbox:ro" ' .
                        'php:8-cli-alpine ' .
                        'sh -c "php /sandbox/sqlite_runner.php %s 2>&1 | head -c 1048576" > "%s"',
                    $containerName,
                    $memLimit,
                    $tempSqlDirDocker,
                    $queryFile,
                    $outFile
                );

                $process = proc_open($dockerCmd, $descriptorspec, $pipes);
                if (!is_resource($process))
                    return ['output' => '', 'error' => 'Docker 실행 실패'];

                fclose($pipes[0]);

                $startTime = microtime(true);
                $timeout = ($timeLimit ? (float)$timeLimit : 2.0) + 1.0;
                $isTimeout = false;
                $isOLE = false;
                $maxBytes = 1048576;

                while (true) {
                    clearstatcache();
                    // 파일 용량 실시간 감시
                    $outSize = file_exists($outFile) ? filesize($outFile) : 0;
                    $errSize = file_exists($errFile) ? filesize($errFile) : 0;

                    if ($outSize > $maxBytes || $errSize > $maxBytes) {
                        proc_terminate($process);
                        shell_exec("docker kill" . escapeshellarg($containerName) . " >/dev/null 2>&1");
                        $isOLE = true;
                        break;
                    }

                    $statusArr = proc_get_status($process);
                    $runningTime = microtime(true) - $startTime;

                    if (!$statusArr['running'])
                        break;
                    if ($runningTime > $timeout) {
                        proc_terminate($process);
                        shell_exec("docker kill" . escapeshellarg($containerName) . " >/dev/null 2>&1");
                        $isOLE = true;
                        break;
                    }
                    usleep(10000);
                }
                proc_close($process);

                $output = file_exists($outFile) ? file_get_contents($outFile) : '';
                $error = file_exists($errFile) ? file_get_contents($errFile) : '';

                $isOLE = (strlen($output) >= 1000000); 

                // 최종 OOM 방지 및 공백 제거
                if (strlen($output) > $maxBytes)
                    $output = substr($output, 0, $maxBytes);
                if (strlen($error) > $maxBytes)
                    $error = substr($error, 0, $maxBytes);

                $output = trim($output);
                $error = trim($error);

                if ($isOLE) {
                    return ['output' => '', 'error' => '출력 초과'];
                }
                if ($isTimeout)
                    return ['output' => '', 'error' => '시간 초과'];


                $exitCode = isset($statusArr['exitcode']) ? $statusArr['exitcode'] : 0;
                if ($exitCode !== 0 && empty($error)) {
                    $error = "Exit code: " . $exitCode;
                }

                return ['output' => $output, 'error' => $error];
            };

            $correctResultJson = $submission['correct_result'] ?? null;
            $startTime = microtime(true);

            // 6. 정답 결과 확인 (캐시가 비어있을 경우에만 Docker 실행하여 생성 후 캐시)
            if ($correctResultJson === null) {
                $correctRes = $executeInDocker('correct_query.sql', $submission['time_limit']);

                if (!empty($correctRes['error']))
                    return [
                        'status' => '런타임 에러',
                        'execution_time' => $max_exec_time,
                        'error' => "정답 쿼리 실행 실패: " . trim($correctRes['error']),
                    ];

                $correctResultJson = $correctRes['output'];
                if ($this->pdo) {
                    try {
                        $stmt = $this->pdo->prepare("
                                UPDATE problems SET correct_result = :res WHERE id = :id
                            ");
                        $stmt->execute(['res' => $correctResultJson, 'id' => $problem_id]);
                    } catch (Throwable $e) {
                        error_log("Sql 캐시 오류: " . $e->getMessage());
                    }
                }
            }

            // 7.  유저 쿼리 격리 환경 실행
            $userRes = $executeInDocker('user_query.sql', $submission['time_limit']);
            $exec_duration = (int)((microtime(true) - $startTime) * 1000);
            $max_exec_time = max($max_exec_time, $exec_duration);

            if (!empty($userRes['error'])) {
                $status = '런타임 에러';
                if (trim($userRes['error']) === '출력 초과') {
                    $status = '출력 초과';
                } elseif (trim($userRes['error']) === '시간 초과') {
                    $status = '시간 초과';
                }
                return [
                    'status' => $status,
                    'execution_time' => $max_exec_time,
                    'error' => trim($userRes['error']),
                ];
            }

            $correctArray = json_decode($correctResultJson, true);
            $correctJsonError = json_last_error();
            $userArray = json_decode($userRes['output'], true);
            $userJsonError = json_last_error();

            if ($correctJsonError !== JSON_ERROR_NONE)
                return [
                    'status' => '런타임 에러',
                    'execution_time' => $max_exec_time,
                    'error' => "정답 쿼리 포맷 에러",
                ];
            if ($userJsonError !== JSON_ERROR_NONE)
                return [
                    'status' => '런타임 에러',
                    'execution_time' => $max_exec_time,
                    'error' => "JSON Parsing Error",
                ];
            if ($userArray !== $correctArray)
                return [
                    'status' => '틀렸습니다',
                    'execution_time' => $max_exec_time,
                    'error' => null,
                ];

            return [
                'status' => '정답',
                'execution_time' => $max_exec_time,
                'error' => null,
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

            foreach ($tempDirs as $dir) {
                if (is_dir($dir)) {
                    $files = glob("$dir/*");
                    if ($files !== false) {
                        foreach ($files as $f) {
                            @unlink($f);
                        }
                    }
                    @rmdir($dir);
                }
            }
        }
    }
}
