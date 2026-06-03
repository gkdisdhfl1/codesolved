<?php
    // submit.php
    header('Content-Type: application/json; charset=utf-8');
    session_start();

    require_once __DIR__ . '/config/db.php';

    // 임시 유저 세션이 없으면 우선 4번 더미 유저(Newbie)로 간주.
    // (로그인 전 임시 테스트용)

    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 4;

    // POST 데이터 받기
    $rawInput = json_decode(file_get_contents('php://input'), true);
    $input = json_decode($rawInput, true);

    // 디코딩 결과가 배열이 아닐 경우 즉시 차단
    if (!is_array($input)) {
        echo json_encode(['success' => false, 'message' => '잘못된 JSON 입력 포맷입니다.']);
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
        echo json_encode(['success' => false, 'message' => '제출 도중 에러가 발생했습니다: ' . $e->getMessage()]);
    }
?>