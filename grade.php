<?php
// grade.php
header('Content-Type: application/json; charset=utf-8');
session_start();

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/graders/PythonGrader.php';
require_once __DIR__ . '/includes/graders/SqlGrader.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); // 405 Method Not Allowed
    echo json_encode(['success' => false, 'message' => '허용되지 않은 요청입니다. (POST 전용)']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['success' => false, 'message' => '올바르지 않은 요청 형식입니다.']);
    exit;
}

$submission_id = isset($input['id']) ? (int)$input['id'] : 0;

if ($submission_id <= 0) {
    echo json_encode(['success' => false, 'message' => '올바르지 않은 제출 ID입니다.']);
    exit;
}

// 대한민국 시간(Asia/Seoul)으로 기본 시간대 일괄 설정
date_default_timezone_set('Asia/Seoul');

ignore_user_abort(true);

try {
    // 로그인 세션 기반 소유권 검증
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => '로그인이 필요한 서비스입니다.'
        ]);
        exit;
    }

    $current_user_id = $_SESSION['user_id'];

    // 1. 제출 정보 및 문제 정보 조회
    $stmt = $pdo->prepare("
            SELECT s.*, p.type as problem_type, p.difficulty, p.time_limit, p.memory_limit, p.answer_query, p.correct_result
            FROM submissions s
            JOIN problems p ON s.problem_id = p.id
            WHERE s.id = :id
        ");
    $stmt->execute(['id' => $submission_id]);
    $submission = $stmt->fetch();

    if (!$submission) {
        throw new Exception("제출 정보를 찾을 수 없습니다.");
    }

    if ($submission['user_id'] != $current_user_id) {
        http_response_code(403); // forbidden
        echo json_encode([
            'success' => false,
            'message' => '권한이 없습니다. 본인의 제출만 채점할 수 있습니다.'
        ]);
        exit;
    }

    // 단일 쿼리를 통한 상태 갱신으로 레이스 컨디션 방지
    // 상태가 '대기 중'일 때만 '채점 중'으로 변경
    $updateStmt = $pdo->prepare("
            UPDATE submissions
            SET status = '채점 중'
            WHERE id = :id AND status = '대기 중'
        ");
    $updateStmt->execute(['id' => $submission_id]);

    // 업데이트된 행의 개수가 0개라면 -> 다른 요처잉 이미 이 제출건을 '채점 중'으로 바꿨거나, 
    // 이미 '정답/오답'으로 끝난 상태
    if ($updateStmt->rowCount() === 0) {
        echo json_encode([
            'success' => false,
            'message' => '이미 처리 중이거나 완료된 채점 요청임.'
        ]);
        exit;
    }

    $problem_id = $submission['problem_id'];
    // $code = $submission['code'];
    $user_id = $submission['user_id'];
    $difficulty = (int)$submission['difficulty'];
    $grader = null;
    $testCases = [];

    if ($submission['problem_type'] === 'python') {
        $tcStmt = $pdo->prepare("
            SELECT * FROM test_cases WHERE problem_id = :pid
        ");
        $tcStmt->execute(['pid' => $problem_id]);
        $testCases = $tcStmt->fetchAll();
        $grader = new PythonGrader();
    } elseif ($submission['problem_type'] === 'sql') {
        $grader = new SqlGrader($pdo);
    } else {
        echo json_encode(['success' => false, 'message' => '지원하지 않는 언어입니다.']);
        exit;
    }

    $result = $grader->grade($submission, $testCases);

    $status = $result['status'];
    $max_exec_time = $result['execution_time'];
    $error_msg = $result['error'];


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
    elseif ($status === '출력 초과')
        $finalStatus = '출력 초과';

    // 1. 제출 상태 업데이트는 즉시 반영 (트랜잭션 밖에서 실행)
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

    if ($finalStatus === '맞았습니다') {
        $pdo->beginTransaction();

        // 이미 푼 문제인지 확인 및 기록
        $insertSolved = $pdo->prepare("
                INSERT IGNORE INTO solved_problems (user_id, problem_id) VALUES (:uid, :pid)
            ");
        $insertSolved->execute([
            'uid' => $user_id,
            'pid' => $problem_id
        ]);
        $isFirstSolve = ($insertSolved->rowCount() === 1);

        // 스트릭 및 경험치 계산을 위해 기존 데이터 조회가 필요하므로 FOR UPDATE로 해당 유저 행만 잠금
        $userStmt = $pdo->prepare("
                SELECT rating, streak, last_solved_date FROM users WHERE id = :uid FOR UPDATE
            ");
        $userStmt->execute(['uid' => $user_id]);
        $user = $userStmt->fetch();

        if ($user) {
            $currentDate = date('Y-m-d');
            $newStreak = $user['streak'];
            $newRating = $user['rating'];

            // 1. 경험치 정산 (최초 해결 시에만)
            if ($isFirstSolve) {
                $ratingGain = $difficulty * 20;
                $newRating += $ratingGain;
            }

            // 2. 스트릭 정산 (오늘 처음 문제를 맞춘 경우에만 스트릭 갱신)
            if ($user['last_solved_date'] !== $currentDate) {
                if ($user['last_solved_date'] === null) {
                    $newStreak = 1;
                } else {
                    $datetime1 = new DateTime($user['last_solved_date']);
                    $datetime2 = new DateTime($currentDate);
                    $interval = $datetime1->diff($datetime2);

                    if ($interval->days === 1) {
                        $newStreak++;
                    } else {
                        $newStreak = 1;
                    }
                }
            }

            $upUserStmt = $pdo->prepare("
                            UPDATE users
                            SET rating = :rating, streak =:streak, last_solved_date = :today
                            WHERE id = :uid
                        ");
            $upUserStmt->execute([
                'rating' => $newRating,
                'streak' => $newStreak,
                'today' => $currentDate,
                'uid' => $user_id
            ]);
        }
        // 3. 모든 작업이 정상적으로 완료되면 DB 확정
        $pdo->commit();
    }


    echo json_encode([
        'success' => true,
        'status' => $finalStatus,
        'execution_time' => $max_exec_time,
        'error' => $error_msg
    ]);
} catch (Throwable $e) {
    // 에러 발생 시 트랜잭션이 열려있다면 롤백
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Grading error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => '채점 중 내부 서버 에러가 발생했습니다.']);
}
