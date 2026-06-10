<?php
session_start();
require_once __DIR__ . '/config/db.php';
$is_logged_in = isset($_SESSION['user_id']);

// 게시글 목록 조회
$stmt = $pdo->query("
    SELECT d.id, d.title, d.created_at, u.username, p.title as problem_title
    FROM discussions d
    JOIN users u ON d.user_id = u.id
    JOIN problems p ON d.problem_id = p.id
    ORDER BY d.created_at DESC
");
$discussions = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>게시판 - CodeSolved</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .board-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .board-table th, .board-table td { padding: 15px; border-bottom: 1px solid var(--border-color); text-align: left; }
        .board-table th { color: var(--text-muted); font-weight: 600; font-size: 0.9rem; }
        .board-table tr { transition: var(--transition); }
        .board-table tr:hover { background-color: rgba(255, 255, 255, 0.02); }
    </style>
</head>
<body>
    <header>
        <div class="logo"><span>&lt;/&gt;</span> CodeSolved</div>
        <div class="nav-links">
            <a href="index.php">대시보드</a>
            <a href="problem.php">문제 풀이</a>
            <a href="board.php" class="active">게시판</a>
            <?php if ($is_logged_in): ?>
                <a href="auth/logout.php" style="color: var(--error);">로그아웃</a>
            <?php else: ?>
                <a href="auth/login.php" style="color: var(--accent-color);">로그인</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">
        <div class="card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <h2 style="font-size: 1.5rem;">질문 및 토론</h2>
                <?php if ($is_logged_in): ?>
                    <a href="board_write.php" class="btn btn-primary">글쓰기</a>
                <?php endif; ?>
            </div>
            
            <table class="board-table">
                <thead>
                    <tr>
                        <th width="8%">No</th>
                        <th width="15%">관련 문제</th>
                        <th width="45%">제목</th>
                        <th width="12%">작성자</th>
                        <th width="12%">작성일</th>
                        <th width="8%"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($discussions as $d): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($d['id']); ?></td>
                        <td style="color: var(--text-muted); font-size: 0.9rem;">[<?php echo htmlspecialchars($d['problem_title']); ?>]</td>
                        <td style="font-weight: 500;">
                            <a href="board_view.php?id=<?php echo htmlspecialchars($d['id']); ?>" style="color: var(--text-primary);">
                                <?php echo htmlspecialchars($d['title']); ?>
                            </a>
                        </td>
                        <td><?php echo htmlspecialchars($d['username']); ?></td>
                        <td style="color: var(--text-secondary); font-size: 0.9rem;">
                            <?php echo htmlspecialchars(date('Y-m-d', strtotime($d['created_at']))); ?>
                        </td>
                        <td>
                            <a href="board_view.php?id=<?php echo htmlspecialchars($d['id']); ?>" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.85rem; white-space: nowrap;">보기</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>

                    <?php if (empty($discussions)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                아직 작성된 게시글이 없습니다. 첫 번째 질문을 남겨보세요!
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>