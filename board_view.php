 <?php
    // board_view.php                                                                                                                                                                                          
    session_start();
    require_once __DIR__ . '/config/db.php';

    $is_logged_in = isset($_SESSION['user_id']);
    $user_id = $is_logged_in ? $_SESSION['user_id'] : null;

    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

    if ($id <= 0) {
        die("잘못된 접근입니다.");
    }

    // 1. 댓글 작성 처리 (POST 요청 시)                                                                                                                                                                        
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_logged_in) {
        $content = trim($_POST['content']);
        if (!empty($content)) {
            $stmt = $pdo->prepare("INSERT INTO comments (discussion_id, user_id, content) VALUES (:did, :uid, :content)");
            $stmt->execute(['did' => $id, 'uid' => $user_id, 'content' => $content]);

            // 작성 후 새로고침 시 폼 중복 제출을 막기 위해 리다이렉트 (PRG 패턴)                                                                                                                              
            header("Location: board_view.php?id=" . $id);
            exit;
        }
    }

    // 2. 게시글 상세 및 관련 문제 조회                                                                                                                                                                        
    $stmt = $pdo->prepare("                                                                                                                                                                                    
        SELECT d.*, u.username, p.title as problem_title, p.id as problem_id                                                                                                                                   
        FROM discussions d                                                                                                                                                                                     
        JOIN users u ON d.user_id = u.id                                                                                                                                                                       
        JOIN problems p ON d.problem_id = p.id                                                                                                                                                                 
        WHERE d.id = :id                                                                                                                                                                                       
    ");
    $stmt->execute(['id' => $id]);
    $discussion = $stmt->fetch();

    if (!$discussion) {
        die("존재하지 않거나 삭제된 게시글입니다.");
    }

    // 3. 댓글 목록 조회                                                                                                                                                                                       
    $cStmt = $pdo->prepare("                                                                                                                                                                                   
        SELECT c.*, u.username                                                                                                                                                                                 
        FROM comments c                                                                                                                                                                                        
        JOIN users u ON c.user_id = u.id                                                                                                                                                                       
        WHERE c.discussion_id = :did                                                                                                                                                                           
        ORDER BY c.created_at ASC                                                                                                                                                                              
    ");
    $cStmt->execute(['did' => $id]);
    $comments = $cStmt->fetchAll();
    ?>

 <!DOCTYPE html>
 <html lang="ko">

 <head>
     <meta charset="UTF-8">
     <title><?php echo htmlspecialchars($discussion['title']); ?> - CodeSolved</title>
     <link rel="stylesheet" href="assets/css/style.css">
     <style>
         .post-header {
             margin-bottom: 20px;
             padding-bottom: 15px;
             border-bottom: 1px solid var(--border-color);
         }

         .post-content {
             line-height: 1.7;
             white-space: pre-wrap;
             font-size: 1.05rem;
             min-height: 150px;
         }

         .comment-box {
             padding: 15px;
             background: rgba(0, 0, 0, 0.2);
             border: 1px solid rgba(255, 255, 255, 0.05);
             border-radius: 8px;
             margin-bottom: 15px;
         }

         .comment-textarea {
             width: 100%;
             padding: 15px;
             border-radius: 8px;
             border: 1px solid var(--border-color);
             background: rgba(0, 0, 0, 0.3);
             color: white;
             resize: vertical;
             margin-bottom: 10px;
             font-family: inherit;
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

     <div class="container" style="max-width: 900px;">
         <div class="card" style="margin-bottom: 30px;">
             <div class="post-header">
                 <div style="font-size: 0.85rem; font-weight: 600; color: var(--accent-color); margin-bottom: 8px;">
                     관련 문제: <a href="problem.php?id=<?php echo htmlspecialchars($discussion['problem_id']); ?>"><?php echo htmlspecialchars($discussion['problem_title']); ?></a>
                 </div>
                 <h2><?php echo htmlspecialchars($discussion['title']); ?></h2>
                 <div style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 10px;">
                     작성자: <span style="color: var(--text-primary);"><?php echo htmlspecialchars($discussion['username']); ?></span> &nbsp;|&nbsp;
                     작성일: <?php echo htmlspecialchars($discussion['created_at']); ?>
                 </div>
             </div>

             <div class="post-content"><?php echo htmlspecialchars($discussion['content']); ?></div>
             <div style="margin-top: 30px; text-align: right;">
                 <a href="board.php" class="btn btn-primary" style="background: rgba(255,255,255,0.1); color: white;">목록으로</a>
             </div>
         </div>

         <div class="card">
             <h3 style="margin-bottom: 20px;">댓글 <span style="color: var(--accent-color);"><?php echo count($comments); ?></span></h3>

             <?php foreach ($comments as $c): ?>
                 <div class="comment-box">
                     <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 8px;">
                         <strong style="color: var(--accent-color);"><?php echo htmlspecialchars($c['username']); ?></strong>
                         <span style="margin-left: 10px;"><?php echo htmlspecialchars($c['created_at']); ?></span>
                     </div>
                     <div style="white-space: pre-wrap; font-size: 0.95rem; line-height: 1.5;"><?php echo htmlspecialchars($c['content']); ?></div>
                 </div>
             <?php endforeach; ?>

             <?php if ($is_logged_in): ?>
                 <form method="POST" style="margin-top: 30px;">
                     <textarea name="content" class="comment-textarea" rows="4" placeholder="댓글을 남겨보세요." required></textarea>
                     <div style="text-align: right;"><button type="submit" class="btn btn-primary">댓글 작성</button></div>
                 </form>
             <?php else: ?>
                 <div style="margin-top: 30px; padding: 20px; text-align: center; background: rgba(0,0,0,0.2); border-radius: 8px; border: 1px solid var(--border-color);">
                     댓글을 작성하시려면 <a href="auth/login.php">로그인</a>이 필요합니다.
                 </div>
             <?php endif; ?>
         </div>
     </div>
 </body>

 </html>