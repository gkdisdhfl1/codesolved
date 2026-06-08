<?php
    // submit.php
    header('Content-Type: application/json; charset=utf-8');
    session_start();

    require_once __DIR__ . '/config/db.php';

    // 로그인 세션 검증 및 환경 변수 기반 테스트 백도어 제어
    if (!isset($_SESSION['user_id'])) {
        http_response_code(401); // 401 Unauthorized 상태 코드
        echo json_encode([
            'success' => false,
            'message' => '로그인이 필요한 서비스입니다.'
        ]);
        exit;
    }

    // 오직 인증된 세션에서만 유저 ID를 가져옴
    $user_id = $_SESSION['user_id'];

    // POST 데이터 받기
    $input = json_decode(file_get_contents('php://input'), true);

    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => '올바르지 않은 요청 형식입니다.']);
        exit;
    }

    $problem_id = isset($input['problem_id']) ? (int)$input['problem_id'] : 0;
    $code = isset($input['code']) ? trim($input['code']) : '';
    $language = isset($input['language']) ? trim($input['language']) : '';

    if ($problem_id <= 0 || empty($code) || empty($language)) {
        echo json_encode(['success' => false, 'message' => '필수 제출 데이터가 누락되었습니다.']);
        exit;
    }

    try {
        // 문제 존재 여부 및 유형 검증
        $probStmt = $pdo->prepare("SELECT type FROM problems WHERE id = :id");
        $probStmt->execute(['id' => $problem_id]);
        $problem = $probStmt->fetch();

        if (!$problem) {
            echo json_encode(['success' => false, 'message' => '존재하지 않는 문제입니다.']);
            exit;
        }

        // 1. 제출 상태를 '대기 중'으로 DB에 삽입
        $stmt = $pdo->prepare("
            INSERT INTO submissions (user_id, problem_id, code, language, status)
            VALUES (:user_id, :problem_id, :code, :language, '대기 중')
        ");
        $stmt->execute([
            'user_id' => $user_id,
            'problem_id' => $problem_id,
            'code' => $code,
            'language' => $language
        ]);

        $submission_id = $pdo->lastInsertId();

        // 2. 생성된 제출 ID를 반환 (이후 프론트엔드에서 grade.php를 호출하여 실제 채점 시작)
        echo json_encode([
            'success' => true,
            'submission_id' => $submission_id
        ]);
    } catch (Exception $e) {
        error_log("Submission error: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => '제출 도중 에러가 발생했습니다.']);
    }
?>