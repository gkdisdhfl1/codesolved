<?php
// auth/login.php
session_start();
require_once __DIR__ . '/../config/db.php';

if (isset($_SESSION['user_id'])) {
    header("Location: ../index.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];

    if (empty($username) || empty($password)) {
        $error = '아이디와 비밀번호를 모두 입력해주세요.';
    } else {
        $stmt = $pdo->prepare("
                SELECT * FROM users
                WHERE username = :username
            ");
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            header("Location: ../index.php");
            exit;
        } else {
            $error = '아이디 또는 비밀번호가 일치하지 않습니다.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>로그인 - CodeSolved</title>
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
            margin-bottom: 20px;
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
        <h1 style="margin-bottom: 5px;"><span style="color: var(--accent-color);">&lt;/&gt;</span> CodeSolved</h1>
        <p style="color: var(--text-muted); margin-bottom: 25px;">개발자의 진짜 실력을 증명하세요</p>

        <?php if ($error): ?>
            <div style="background: rgba(248,81,73,0.1); border: 1px solid var(--error); color: #ff7b72; padding: 10px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label>아이디 (Username)</label>
                <input type="text" name="username" class="form-control" required autocomplete="off">
            </div>
            <div class="form-group">
                <label>비밀번호 (Password)</label>
                <input type="password" name="password" class="form-control" required>
            </div>
            <button type="submit" class="btn btn-primary btn-full">로그인</button>
        </form>

        <div style="margin-top: 20px; font-size: 0.9rem;">
            계정이 없으신가요? <a href="register.php">회원가입</a>
        </div>
    </div>
</body>

</html>