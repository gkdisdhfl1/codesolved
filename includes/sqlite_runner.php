<?php
    // Docker 샌드박스 내부에서만 실행되는 격리된 스크립트

    // CLI 파라미터로 실행할 쿼리 파일명을 받음
    $queryFile = isset($argv[1]) ? $argv[1] : '';
    if (empty($queryFile)) {
        fwrite(STDERR, "실행할 쿼리 파일이 저장되지 않음.");
        exit(1);
    }

    $initSql = file_get_contents('/sandbox/init.sql');
    $sql = file_get_contents('/sandbox/' . $queryFile);

    try {
        // 1. 파일 I/O가 없는 초고속 인메모리 DB 생성
        $pdo = new PDO('sqlite::memory', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // 2. 초기 스키마 및 데이터 세팅 (테이블 생성 및 INSERT)
        $pdo->exec($initSql);

        // 3. 사용자 및 정답 쿼리 실행
        $stmt = $pdo->query($sql);

        if ($stmt) {
            echo json_encode($stmt->fetchAll());
        } else {
            echo json_encode([]);
        }
    } catch (Exception $e) {
        // 런타임 에러 발생 시 STDERR로 출력하여 외부에서 감지하도록 함
        fwrite(STDERR, $e->getMessage());
        exit(1);
    }
?>