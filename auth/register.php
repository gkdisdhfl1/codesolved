<?php
// auth/register.php
session_start();
require_once __DIR__ . '/../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    $password_confirm = $_POST['password_confirm'];

    if (empty($username) || empty($password)) {
        $error = '모든 필드를 입력해주세요.';
    } elseif (mb_strlen($username) > 50) {
        $error = '아이디는 최대 50자 이하이어야 합니다.';
    } elseif ($password !== $password_confirm) {
        $error = '비밀번호가 일치하지 않습니다.';
    } elseif (strlen($password) < 8) {
        $error = '비밀번호는 최소 8자 이상이어야 합니다.';
    } else {
        try {
            // 중복 검사
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = :username");
            $stmt->execute(['username' => $username]);
            if ($stmt->fetch()) {
                $error = '이미 존재하는 아이디입니다.';
            } else {
                // 회원가입 처리
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $insertStmt = $pdo->prepare("INSERT INTO users (username, password_hash) VALUES (:username, :hash)");
                if ($insertStmt->execute(['username' => $username, 'hash' => $hash])) {
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $pdo->lastInsertId();
                    $_SESSION['username'] = $username;
                    header("Location: ../index.php");
                    exit;
                } else {
                    $error = '회원가입 중 서버 에러가 발생했습니다.';
                }
            }
        } catch (\PDOException $e) {
            if ($e->getCode() == 23000) {
                $error = '이미 존재하는 아이디입니다.';
            } else {
                $error = '회원가입 중 데이터베이스 에러가 발생했습니다.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>회원가입 - CodeSolved</title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <style>
        body {
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
        }

        .auth-card {
            width: 100%;
            max-width: 400px;
            text-align: center;
        }

        .form-group {
            margin-bottom: 15px;
            text-align: left;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            color: white;
            border-radius: 8px;
            margin-top: 5px;
            outline: none;
        }

        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 8px rgba(88, 166, 255, 0.3);
        }

        .btn-full {
            width: 100%;
            padding: 12px;
            font-size: 1rem;
            margin-top: 10px;
        }
    </style>
</head>

<body>
    <div class="card auth-card">
        <h2 style="margin-bottom: 20px;">계정 생성하기</h2>

        <?php if ($error): ?>
            <div style="background: rgba(248,81,73,0.1); border: 1px solid var(--error); color: #ff7b72; padding: 10px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="register.php">
            <div class="form-group">
                <label>아이디</label>
                <input type="text" name="username" class="form-control" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>비밀번호</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <div class="form-group">
                <label>비밀번호 확인</label>
                <input type="password" name="password_confirm" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">가입하기</button>
        </form>

        <div style="margin-top: 20px; font-size: 0.9rem;">
            이미 계정이 있으신가요? <a href="login.php">로그인</a>
        </div>
    </div>
</body>

</html>