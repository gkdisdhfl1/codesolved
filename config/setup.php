<?php
// config/setup.php
header('Content-Type: text/html; charset=utf-8');

$host = '127.0.0.1';
$user = 'root';
$pass = '';

// SQL 파일의 내용을 읽어 주석을 제거하고 개별 쿼리문 단위로 쪼개어 실행시켜주는 헬퍼 함수
function executeSqlFile($pdo, $filePath) {
    if (!file_exists($filePath)) {
        throw new Exception("❌ '$filePath' 파일을 찾을 수 없습니다.");
    }

    // 1. 파일 내용 읽기
    $sqlContent = file_get_contents($filePath);

    // 2. 한 줄 주석 제거 (정규식 사용)
    $sqlContent = preg_replace('/--.*$/m', '', $sqlContent);

    // 3. 세미콜론 기준으로 쿼리 분할
    $queries = explode(';', $sqlContent);

    $executedCount = 0;
    foreach ($queries as $query) {
        $query = trim($query);

        // 빈 줄이나 빈 쿼리는 실행하지 않음
        if ($query !== '') {
            $pdo->exec($query);
            $executedCount++;
        }
    }
    return $executedCount;
}

try {
    // 1. MySQL 서버 초기 연결
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);

    echo "⚙️ <b>데이터베이스 빌드 프로세스 시작...</b><br><br>";

    // 2. schema.sql (DDL) 로드 및 실행
    $schemaPath = __DIR__ . '/schema.sql';
    $schemaQueriesCount = executeSqlFile($pdo, $schemaPath);
    echo "✔️[1/2] schema.sql 실행 완료 (총 {$schemaQueriesCount}개 테이블 스키마 쿼리 성공)<br>";

    // 3. seed.sql (DML) 로드 및 실행
    $seedPath = __DIR__ . '/seed.sql';
    $seedQueriesCount = executeSqlFile($pdo, $seedPath);
    echo "✔️[1/2] seed.sql 실행 완료 (총 {$seedQueriesCount}개 테이블 스키마 쿼리 성공)<br>";

    echo "<br><b>데이터베이스 및 초기화 빌드에 최종 성공함</b><br>";
    echo "<br>👉 <a href='../index.php'>메인 대시보드로 이동하기</a>";
} catch (Exception $e) {
    die("<br>❌ <b>데이터베이스 빌드 실패:</b> " . $e->getMessage());
}
?>
