<?php
    // grade.php
    header('Content-Type: application/json; charset=utf-8');
    session_start();

    require_once __DIR__ . '/config/db.php';

    $submission_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($submission_id <= 0) {
        echo json_encode(['success' => false, 'message' => '올바르지 않은 제출 ID입니다.']);
        exit;
    }

    try {
        // 1. 제출 정보 및 문제 정보 조회
        $stmt = $pdo->prepare("
            SELECT s.*, p.type as problem_type, p.difficulty, p.time_limit, p.answer_query
            FROM submissions s
            JOIN problems p ON s.problem_id = p.id
            WHERE s.id = :id
        ");
        $stmt->execute(['id' => $submission_id]);
        $submission = $stmt->fetch();

        if (!$submission) {
            throw new Exception("제출 정보를 찾을 수 없습니다.");
        }

        // 상태를 채점 중으로 갱신
        $updateStmt = $pdo->prepare("UPDATE submissions SET status = '채점 중' WHERE id = :id");
        $updateStmt->execute(['id' => $submission_id]);

        $problem_id = $submission['problem_id'];
        $code = $submission['code'];
        $user_id = $submission['user_id'];
        $difficulty = (int)$submission['difficulty'];

        $status = '정답';
        $error_msg = null;
        $max_exec_time = 0;

        // ==========================================
        // 파이썬 채점 로직 (Docker Sandbox 격리 환경)
        // ==========================================
        if ($submission['problem_type'] === 'python') {
            $tempDir = __DIR__ . '/scratch';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0777, true);
            }
            $tempFile = $tempDir . "/temp_" . $submission_id . ".py";
            file_put_contents($tempFile, $code);

            $tcStmt = $pdo->prepare("SELECT * FROM test_cases WHERE problem_id = :pid");
            $tcStmt->execute(['pid' => $problem_id]);
            $testCases = $tcStmt->fetchAll();

            if (empty($testCases)) {
                $status = '틀렸습니다';
                $error_msg = '서버에 테스트 케이스가 등록되지 않음.';
            }

            // 윈도우 XAMPP 환경의 경로를 Docker가 마운트하기 쉽게 슬래시로 변경
            $tempDirDocker = str_replace('\\', '/', $tempDir);

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
                $memLimit = $submission['memory_limit'] ? (int)$submission['memory_limit'] : 128;

                $dockerCmd = sprintf(
                    'docker run --rm -i --net none --memory="%dm" --cpus="1.0" -v "%s:/sandbox:ro" python:3.9-alpine python /sandbox/%s',
                    $memLimit,
                    $tempDirDocker,
                    "temp_" . $submission_id . ".py"
                );

                // proc_open으로 도커 프로세스 실행
                $process = proc_open($dockerCmd, $descriptorspec, $pipes);
    
                if (is_resource($process)) {
                    // 입력값(표준 입력) 주입
                    fwrite($pipes[0], $input);
                    fclose($pipes[0]);

                    $startTime = microtime(true);
                    // 도커 컨테이너 실행에 약간의 오버헤드가 있으므로 기본 타임아웃에 1초 여유를 더해줌
                    $timeout = ($submission['time_limit'] ? (float)$submission['time_limit'] : 2.0) + 1.0;
                    $isTimeout = false;

                    while (true) {
                        $statusArr = proc_get_status($process);
                        $runningTime = microtime(true) - $startTime;

                        if (!$statusArr['running']) {
                            break;
                        }
                        if ($runningTime > $timeout) {
                            // 타임아웃 시 도커 프로세스 강제 종료
                            proc_terminate($process);
                            $isTimeout = true;
                            break;
                        }
                        usleep(10000);
                    }

                    // 실행 시간 계산
                    $exec_duration = (int)((microtime(true) - $startTime) * 1000);
                    $max_exec_time = max($max_exec_time, $exec_duration);

                    $output = trim(stream_get_contents($pipes[1]));
                    fclose($pipes[1]);
                    
                    $error = trim(stream_get_contents($pipes[2]));
                    fclose($pipes[2]);
                    
                    proc_close($process);

                    // 리소스 회수를 먼저 완료한 뒤에 타임아웃 예외를 처리
                    if ($isTimeout) {
                        $status = '시간 초과';
                        break;
                    }

                    if (!empty($error)) {
                        $status = '런타임 에러';
                        $error_msg = $error;
                        break;
                    }

                    if (str_replace("\r", "", $output) !== str_replace("\r", "", $expectedOutput)) {
                        $status = '틀렸습니다.';
                        break;
                    }
                } else {
                    $status = '런타임 에러';
                    $error_msg = "채점 엔진이 파이썬을 실행 할 수 없음.";
                    break;
                }
            }

            // 임시 파일 삭제
            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

        // ==========================================
        // SQL 채점 로직 (Docker 일회용 DB 샌드박스 격리)
        // ==========================================
        } elseif ($submission['problem_type'] === 'sql') {
            $correctSql = trim($submission['answer_query']);

            // 1. 해당 문제용 초기화 SQL 파일 경로 확인 (예: data/sql_init/4.sql)
            $initSqlSourcePath = __DIR__ . '/data/sql_init' . $problem_id . '.sql';

            if(empty($correctSql)) {
                $status = '런타임 에러';
                $error_msg = "이 SQL 문제의 정답 쿼리가 데이터베이스에 정의되지 않았음.";
            } elseif (!file_exists($initSqlSourcePath)) {
                $status = '런타임 에러';
                $error_msg = "문제 번호 {$problem_id}에 해당하는 초기화 SQL 파일이 서버에 존재하지 않습니다.";
            } else {
                // 2. 임시 작업 디렉토리 생성
                $tempSqlDir = __DIR__ . '/scratch/sql_' . $submission_id;
                if (!is_dir($tempSqlDir)) {
                    mkdir($tempSqlDir, 0777, true);
                }

                // 3. 서버에 저장된 초기화 파일을 임시 디렉토리의 init.sql로 복사
                copy($initSqlSourcePath, $tempSqlDir . '/init.sql');

                // 4. 유저 쿼리와 정답 쿼리를 각각 파일로 저장 (JSON 포맷으로 출력하도록 설정)
                file_put_contents($tempSqlDir . '/user_query.sql', ".mode json\n" . $code . ";");
                file_put_contents($tempSqlDir . '/correct_query.sql', ".mode json\n" . $correctSql . ";");

                // Windows 경로 슬래시 보정
                $tempSqlDirDocker = str_replace('\\', '/', $tempSqlDir);

                // 5. 안전한 Docker SQLite3 실행 헬퍼 함수
                $executeInDocker = function($queryFile) use ($tempSqlDirDocker) {
                    $descriptorspec = [
                        1 => ["pipe", "w"], // stdout
                        2 => ["pipe", "w"]  // stderr
                    ];

                    $dockerCmd = sprintf(
                        'docker run --rm -i --net none -v "%s:/sandbox:ro" keinos/sqlite3 sh -c "cat /sandbox/init.sql /sandbox/%s | sqlite3"',
                        $tempSqlDirDocker,
                        $queryFile
                    );

                    $process = proc_open($dockerCmd, $descriptorspec, $pipes);

                    if (is_resource($process)) {
                        $output = stream_get_contents($pipes[1]);
                        $error = stream_get_contents($pipes[2]);
                        fclose($pipes[1]);
                        fclose($pipes[2]);
                        proc_close($process);

                        return ['output' => $output, 'error' => $error];
                    }
                    return ['output' => '', 'error' => 'Docker 실행 실패'];
                };

                $startTime = microtime(true);

                // 6. 정답 쿼리 및 유저 쿼리 각각 격리 환경에서 실행
                $correctRes = $executeInDocker('correct_query.sql');
                $userRes = $executeInDocker('user_query.sql');

                $exec_duration = (int)((microtime(true) - $startTime) * 1000);
                $max_exec_time = max($max_exec_time, $exec_duration);

                // 7. 에러 및 결과 대조
                if (!empty($userRes['error'])) {
                    $status = '런타임 에러';
                    $error_msg = trim($userRes['error']);
                } else {
                    $correctArray = json_decode($correctRes['output'], true);
                    $userArray = json_decode($userRes['output'], true);

                    if ($userArray === null && !empty($userRes['output'])) {
                        $status = '런타임 에러';
                        $error_msg = "결과 포맷이 올바르지 않습니다.";
                    } elseif ($userArray !== $correctArray) {
                        $status = '틀렸습니다';
                    }
                }

                // 8. 임시 디렉토리 정리
                array_map('unlink', glob("$tempSqlDir/*.*"));
                rmdir($tempSqlDir);
            }
        }

        // ==========================================
        // 최종 판정 및 경험치 정산
        // ==========================================
        $finalStatus = '틀렸습니다';
        if ($status === '정답')
                $finalStatus = '맞았습니다';
        elseif ($status === '시간 초과')
                $finalStatus = '시간 초과';
        elseif ($status === '런타임 에러')
                $finalStatus = '런타임 에러';

        $saveStmt = $pdo->prepare("
            UPDATE submissions
            SET status = :status, execution_time = :time, error_message = :err
            WHERE id = :id
        ");
        $saveStmt->execute([
            'status' => $finalStatus,
            'time' => $max_exec_time,
            'err' => $error_msg,
            'id' => $submission_id
        ]);

        // 경험치(레이팅) 및 스트릭 정산
        if ($finalStatus === '맞았습니다') {
            $dupStmt = $pdo->prepare("
                SELECT COUNT(*) FROM submissions
                WHERE user_id = :uid AND problem_id = :pid AND status = '맞았습니다' AND id < :sid
            ");
            $dupStmt->execute(['uid' => $user_id, 'pid' => $problem_id, 'sid' => $submission_id]);

            if ($dupStmt->fetchColumn() == 0) {
                $ratingGain = $difficulty * 20;

                $userStmt = $pdo->prepare("
                    SELECT rating, streak, last_solved_date FROM users WHERE id = :uid
                ");
                $userStmt->execute(['uid' => $user_id]);
                $user = $userStmt->fetch();

                $newRating = $user['rating'] + $ratingGain;
                $currentDate = date('Y-m-d');
                $newStreak = $user['streak'];

                if ($user['last_solved_date'] === null) {
                    $newStreak = 1;
                } else {
                    $datetime1 = new DateTime($user['last_solved_date']);
                    $datetime2 = new DateTime($currentDate);
                    $interval = $datetime1->diff($datetime2);

                    if ($interval->days === 1) {
                        $newStreak++;
                    } elseif ($interval->days > 1) {
                        $newStreak = 1;
                    }
                }

                $upUserStmt = $pdo->prepare("
                    UPDATE users
                    SET rating = :rating, streak =:streak, last_solved_date = :today
                    WHERE id = :uid
                ");
                $upUserStmt->execute(['rating' => $newRating, 'streak' => $newStreak, 'today' => $currentDate, 'uid' => $user_id]);
            }
        }

        echo json_encode([
            'success' => true,
            'status' => $finalStatus,
            'execution_time' => $max_exec_time,
            'error' => $error_msg
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' =>$e->getMessage()]);
    }
?>