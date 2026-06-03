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
        // 파이썬 채점 로직
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

            foreach ($testCases as $tc) {
                $input = $tc['input_data'];
                $expectedOutput = trim($tc['output_data']);

                $descriptorspec = [
                    0 => ["pipe", "r"], // stdin
                    1 => ["pipe", "w"], // stdout
                    2 => ["pipe", "w"], // stderr
                ];

                // 윈도우 환경 파이썬 실행
                $process = proc_open("python \"$tempFile\"", $descriptorspec, $pipes);
    
                if (is_resource($process)) {
                    fwrite($pipes[0], $input);
                    fclose($pipes[0]);

                    $startTime = microtime(true);
                    $timeout = $submission['time_limit'] ? (float)$submission['time_limit'] : 2.0;
                    $isTimeout = false;

                    while (true) {
                        $statusArr = proc_get_status($process);
                        $runningTime = microtime(true) - $startTime;

                        if (!$statusArr['running']) {
                            break;
                        }
                        if ($runningTime > $timeout) {
                            proc_terminate($process);
                            $isTimeout = true;
                            break;
                        }
                        usleep(10000);
                    }

                    $exec_duration = (int)((microtime(true) - $startTime) * 1000);
                    $max_exec_time = max($max_exec_time, $exec_duration);

                    if ($isTimeout) {
                        $status = '시간 초과';
                        break;
                    }

                    $output = trim(stream_get_contents($pipes[1]));
                    fclose($pipes[1]);

                    $error = trim(stream_get_contents($pipes[2]));
                    fclose($pipes[2]);

                    proc_close($process);

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

            if (file_exists($tempFile)) {
                unlink($tempFile);
            }

        // ==========================================
        // SQL 채점 로직
        // ==========================================
        } elseif ($submission['problem_type'] === 'sql') {
            $correctSql = trim($submission['answer_query']);

            if(empty($correctSql)) {
                $status = '런타임 에러';
                $error_msg = "이 SQL 문제의 정답 쿼리가 데이터베이스에 정의되지 않았음.";
            } else {
                try {
                    $pdo->beginTransaction();

                    $userStmt = $pdo->query($code);
                    $userResult = $userStmt->fetchAll(PDO::FETCH_ASSOC);

                    $correctStmt = $pdo->query($correctSql);
                    $correctResult = $correctStmt->fetchAll(PDO::FETCH_ASSOC);

                    $pdo->rollBack();

                    if (count($userResult) !== count($correctResult)) {
                        $status = '틀렸습니다';
                    } else {
                        foreach ($userResult as $index => $row) {
                            if ($row !== $correctResult[$index]) {
                                $status = '틀렸습니다';
                                break;
                            }
                        }
                    }
                } catch (PDOException $e) {
                    if (isset($pdo) && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $status = '런타임 에러';
                    $error_msg = "SQL 문법 오류 또는 권한 문제: " . $e->getMessage();
                }
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