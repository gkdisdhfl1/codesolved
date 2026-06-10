<?php
session_start();
require_once __DIR__ . '/config/db.php';

// 비로그인 접근 차단
if (!isset($_SESSION['user_id'])) {
    header("Location: auth/login.php");
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $problem_id = (int)$_POST['problem_id'];
    $title = trim($_POST['title']);
    $content = trim($_POST['content']);

    if (empty($title) || empty($content) || $problem_id <= 0) {
        $error = '모든 필드를 입력해주세요.';
    } else {
        $stmt = $pdo->prepare("INSERT INTO discussions (user_id, problem_id, title, content) VALUES (:uid, :pid, :title, :content)");
        if ($stmt->execute(['uid' => $_SESSION['user_id'], 'pid' => $problem_id, 'title' => $title, 'content' => $content])) {
            header("Location: board.php");
            exit;
        } else {
            $error = '게시글 등록 중 에러가 발생했습니다.';
        }
    }
}

// 문제 목록 불러오기 (셀렉트 박스용)
$probs = $pdo->query("SELECT id, title FROM problems ORDER BY id ASC")->fetchAll();
?>

<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <title>글쓰기 - CodeSolved</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .form-group {
            margin-bottom: 20px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            color: var(--text-secondary);
            font-size: 0.9rem;
        }

        .form-control {
            width: 100%;
            padding: 12px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border-color);
            color: white;
            border-radius: 8px;
            outline: none;
            transition: var(--transition);
            font-family: inherit;
        }

        .form-control:focus {
            border-color: var(--accent-color);
            box-shadow: 0 0 8px rgba(88, 166, 255, 0.3);
        }
    </style>
</head>

<body>
    <header>
        <div class="logo"><span>&lt;/&gt;</span> CodeSolved</div>
        <div class="nav-links">
            <a href="index.php">대시보드</a>
            <a href="board.php" class="active">게시판</a>
        </div>
    </header>

    <div class="container" style="max-width: 800px;">
        <div class="card">
            <h2 style="margin-bottom: 20px;">새 토론 작성</h2>

            <?php if ($error): ?>
                <div style="background: rgba(248,81,73,0.1); border: 1px solid var(--error); color: #ff7b72; padding: 10px; border-radius: 8px; margin-bottom: 20px;">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label class="form-label">관련 문제</label>
                    <select name="problem_id" class="form-control" required>
                        <option value="">문제를 선택하세요</option>
                        <?php foreach ($probs as $p): ?>
                            <option value="<?php echo $p['id']; ?>">
                                [No.<?php echo $p['id']; ?>] <?php echo htmlspecialchars($p['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">제목</label>
                    <input type="text" name="title" class="form-control" placeholder="제목을 입력하세요" required>
                </div>
                <div class="form-group">
                    <label class="form-label">내용</label>
                    <textarea name="content" class="form-control" rows="10" placeholder="자세한 질문이나 토론 내용을 작성해주세요." required></textarea>
                </div>
                <div style="text-align: right; margin-top: 30px;">
                    <a href="board.php" class="btn" style="background: rgba(255,255,255,0.1); margin-right: 10px;">취소</a>
                    <button type="submit" class="btn btn-primary">등록하기</button>
                </div>
            </form>
        </div>
    </div>
</body>

</html>