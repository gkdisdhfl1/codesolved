<?php
    // config/db.php

    // 현재 실행 환경 설정
    define('APP_ENV', 'local');

    $host = '127.0.0.1';
    $db = 'codesolved';
    $user = 'root';
    $pass = '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $option = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $option);
    } catch (\PDOException $e) {
        die("<br>❌ <b>데이터베이스 빌드 실패:</b> " . $e->getMessage());
    }
?>