<?php
// index.php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tier_helper.php';

date_default_timezone_set('Asia/Seoul');

$is_logged_in = isset($_SESSION['user_id']);
$user_id = $is_logged_in ? $_SESSION['user_id'] : null;
$username = $is_logged_in ? $_SESSION['username'] : 'Guest';

// ==========================================
// 1. 유저 개인 데이터 조회 (로그인 시에만)
// ==========================================
$rating = 0;
$streak = 0;
$rank = 0;
$solvedCount = 0;
$tierInfo = get_tier_info(0);
$streakData = array_fill(0, 30, 0);

if ($is_logged_in) {
    // 1. 사용자 정보 및 레이팅 조회
    $userStmt = $pdo->prepare("SELECT rating, streak, last_solved_date FROM users WHERE id = :id");
    $userStmt->execute(['id' => $user_id]);
    $user = $userStmt->fetch();

    if ($user) {
        $rating = (int)$user['rating'];
        $streak = (int)$user['streak'];

        // 랭킹 계산
        $rankStmt = $pdo->prepare("
                SELECT COUNT(*) + 1 as rank_pos
                FROM users
                WHERE rating > :rating
            ");
        $rankStmt->execute(['rating' => $rating]);
        $rank = (int)$rankStmt->fetch()['rank_pos'];

        // 티어 계산
        $tierInfo = get_tier_info($rating, $rank);

        // 해결한 문제 수 조회
        $solvedStmt = $pdo->prepare("
                SELECT COUNT(*) as solved_count
                FROM solved_problems
                WHERE user_id = :uid
            ");
        $solvedStmt->execute(['uid' => $user_id]);
        $solvedCount = $solvedStmt->fetch()['solved_count'];

        // 오늘 기준으로 과거 29일까지 (총 30일) 일별 푼 문제 개수 집계
        $startDate = date('Y-m-d', strtotime('-29 days'));
        $streakStmt = $pdo->prepare("
            SELECT DATE(solved_at) as solve_date, COUNT(*) as daily_count
            FROM solved_problems
            WHERE user_id = :uid
                AND solved_at >= :start_date
            GROUP BY DATE(solved_at)
        ");
        $streakStmt->execute([
            'uid' => $user_id,
            'start_date' => $startDate
        ]);

        // 날짜를 키로 하는 연관 배열 생성
        $solveMap = [];
        foreach ($streakStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $solveMap[$row['solve_date']] = (int)$row['daily_count'];
        }

        // 29일 전부터 오늘 (0)까지 역순화하며 배열에 색상 레벨 할당
        for ($i = 29; $i >= 0; $i--) {
            $dateStr = date('Y-m-d', strtotime("-$i days"));
            $count = isset($solveMap[$dateStr]) ? $solveMap[$dateStr] : 0;

            // 문제 푼 개수에 따른 잔디 진하기 레벨 (0~4)
            $level = 0;
            if ($count > 0) {
                if ($count == 1) $level = 1;
                elseif ($count <= 3) $level = 2;
                elseif ($count <= 5) $level = 3;
                else $level = 4;
            }

            // 배열 인덳는 앞쪽(0)이 과거, 뒤쪽(29)이 최신(오늘)
            $streakData[29 - $i] = $level;
        }
    }
}

// ==========================================
// 2. 문제 목록 조회 (비로그인 시 is_solved는 0)
// ==========================================
$probQuery = "
        SELECT p.*, " . ($is_logged_in ? "IF(sp.problem_id IS NOT NULL, 1, 0)" : "0") . " as is_solved
        FROM problems p
        " . ($is_logged_in ? "LEFT JOIN solved_problems sp ON p.id = sp.problem_id AND sp.user_id = :uid" : "") . "
        ORDER BY p.id ASC";
$probStmt = $pdo->prepare($probQuery);
if ($is_logged_in)
    $probStmt->execute(['uid' => $user_id]);
else
    $probStmt->execute();

$problems = $probStmt->fetchAll();

// ==========================================
// 3. 글로벌 랭킹 조회 (Top 10)
// ==========================================
$topUsersStmt = $pdo->query("SELECT id, username, rating FROM users ORDER BY rating DESC, id ASC LIMIT 10");
$topUsers = $topUsersStmt->fetchAll();
?>

<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>대시보드 - CodeSolved</title>

    <!-- 구글 폰트 병렬 로딩 적용 -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@800&display=swap" rel="stylesheet">

    <link rel="stylesheet" href="assets/css/style.css">

    <style>
        .page-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 20px;
        }

        .user-stats {
            text-align: center;
        }

        .stat-numbers {
            display: flex;
            justify-content: space-around;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid var(--border-color);
        }

        .stat-item {
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--text-primary);
        }

        .stat-label {
            font-size: 0.85rem;
            color: var(--text-muted);
            margin-top: 5px;
        }

        /* 문제 목록 테이블 */
        .problem-list {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
        }

        .problem-list th,
        .problem-list td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .problem-list th {
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.9rem;
        }

        .problem-list tr {
            transition: var(--transition);
        }

        .problem-list tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .badge {
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 0.8rem;
            font-weight: 600;
        }

        .badge-python {
            background: rgba(53, 114, 165, 0.2);
            color: #58a6ff;
        }

        .badge-sql {
            background: rgba(227, 140, 0, 0.2);
            color: #e38c00;
        }

        .solved-icon {
            color: var(--success);
            font-weight: bold;
        }
    </style>
</head>

<body>

    <header>
        <div class="logo">
            <span>&lt;/&gt;</span> CodeSolved
        </div>
        <div class="nav-links">
            <a href="index.php" class="active">대시보드</a>
            <a href="problem.php">문제 풀이</a>
            <a href="board.php">게시판</a>
            <?php if ($is_logged_in): ?>
                <a href="auth/logout.php" style="color: var(--error);">로그아웃</a>
            <?php else: ?>
                <a href="auth/login.php" style="color: var(--accent-color)">로그인</a>
            <?php endif; ?>
        </div>
    </header>

    <div class="container">

        <?php if (isset($_SESSION['error_message'])): ?>
            <div style="background: rgba(248, 81, 73, 0.1); border: 1px solid var(--error); padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #ff7b72;">
                <?php
                echo htmlspecialchars($_SESSION['error_message']);
                unset($_SESSION['error_message']);
                ?>
            </div>
        <?php endif; ?>

        <h1 class="page-title">대시보드</h1>

        <div class="dashboard-grid">
            <!-- 📱 [좌측 기둥]: 프로필 카드 & 순위표 카드 -->
            <div style="display: flex; flex-direction: column; gap: 30px;">
                <?php if ($is_logged_in): ?>
                    <!-- 👤 좌측: 유저 프로필 카드 -->
                    <div class="card user-stats">
                        <div class="profile-avatar">
                            <span class="tier-badge <?php echo htmlspecialchars($tierInfo['class']); ?>">
                                <?php echo htmlspecialchars(substr($tierInfo['short_name'], 0, 1)); ?>
                            </span>
                        </div>
                        <h2><?php echo htmlspecialchars($username); ?></h2>
                        <p style="color: <?php echo htmlspecialchars($tierInfo['color']); ?>; font-weight: 600; margin-top: 5px;">
                            <?php echo htmlspecialchars($tierInfo['name']); ?>
                        </p>

                        <!-- 경험치 바 -->
                        <div class="exp-container">
                            <div class="exp-label">
                                <span>Rating: <?php echo htmlspecialchars($rating); ?></span>
                                <span><?php echo htmlspecialchars($tierInfo['progress']); ?> / 100</span>
                            </div>
                            <div class="exp-bar-bg">
                                <div class="exp-bar-fill" style="width: <?php echo htmlspecialchars($tierInfo['progress']); ?>%; background: <?php echo htmlspecialchars($tierInfo['color']); ?>"></div>
                            </div>
                        </div>

                        <div class="stat-numbers">
                            <div class="stat-item">
                                <div class="stat-value"><?php echo htmlspecialchars($rank); ?></div>
                                <div class="stat-label">전체 순위</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value"><?php echo htmlspecialchars($solvedCount); ?></div>
                                <div class="stat-label">해결한 문제</div>
                            </div>
                            <div class="stat-item">
                                <div class="stat-value">🔥 <?php echo htmlspecialchars($streak); ?></div>
                                <div class="stat-label">현재 스트릭</div>
                            </div>
                        </div>

                        <!-- 스트릭(잔디) 시각화 -->
                        <div style="margin-top: 25px; text-align: left;">
                            <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 5px;">최근 30일 활동 내역</div>
                            <div class="streak-grid">
                                <?php foreach ($streakData as $level): ?>
                                    <div class="streak-cell <?php echo htmlspecialchars($level > 0 ? 'streak-level-' . $level : ''); ?>" title="스트릭 레벨 <?php echo htmlspecialchars($level); ?>"></div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 비로그인 시 보여줄 카드 -->
                    <div class="card user-stats" style="display: flex; flex-direction: column; justify-content: center;">
                        <div style="font-size: 3rem; margin-bottom: 10px;">👋</div>
                        <h2>환영합니다!</h2>
                        <p style="color: var(--text-muted); margin-top: 10px; line-height: 1.5;">
                            코딩 실력을 테스트하고,<br>당신의 티어를 증명하세요.
                        </p>
                        <a href="auth/login.php" class="btn btn-primary" style="margin-top: 20px; padding: 10px 20px;">로그인하고 시작하기</a>
                    </div>
                <?php endif; ?>

                <!-- 2. 순위표 카드 (명예의 전당) -->
                <div class="card">
                    <h3 style="margin-bottom: 15px;">🏆 명예의 전당 (Top 10)</h3>
                    <table class="problem-list">
                        <thead>
                            <tr>
                                <th width="15%">순위</th>
                                <th width="50%">유저명</th>
                                <th width="35%">Rating</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($topUsers as $index => $tu):
                                $tuRank = $index + 1;
                                $tuTier = get_tier_info($tu['rating'], $tuRank);
                            ?>
                                <tr>
                                    <td><strong><?php echo $tuRank; ?></strong></td>
                                    <td>
                                        <span class="tier-badge <?php echo htmlspecialchars($tuTier['class']); ?>" style="padding: 2px 6px; font-size: 0.7rem; margin-right: 5px;">
                                            <?php echo htmlspecialchars(substr($tuTier['short_name'], 0, 1)); ?>
                                        </span>
                                        <?php echo htmlspecialchars($tu['username']); ?>
                                    </td>
                                    <td style="color: <?php echo htmlspecialchars($tuTier['color']); ?>; font-weight: bold;">
                                        <?php echo htmlspecialchars($tu['rating']); ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- 📝 우측: 문제 목록 카드 -->
            <div class="card">
                <h3 style="margin-bottom: 15px; display: flex; justify-content: space-between; align-items: center;">
                    <span>도전 가능한 문제</span>
                </h3>

                <table class="problem-list">
                    <thead>
                        <tr>
                            <th width="8%">상태</th>
                            <th width="10%">ID</th>
                            <th width="43%">제목</th>
                            <th width="15%">유형</th>
                            <th width="12%">난이도</th>
                            <th width="12%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($problems as $p): ?>
                            <tr>
                                <td>
                                    <?php if ($p['is_solved']): ?>
                                        <span class="solved-icon">✔️</span>
                                    <?php else: ?>
                                        <span style="color: var(--text-muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($p['id']); ?></td>
                                <td style="font-weight: 500;">
                                    <a href="problem.php?id=<?php echo htmlspecialchars($p['id']); ?>" style="color: var(--text-primary);">
                                        <?php echo htmlspecialchars($p['title']); ?>
                                    </a>
                                </td>
                                <td>
                                    <?php if ($p['type'] === 'python'): ?>
                                        <span class="badge badge-python">Python 3</span>
                                    <?php else: ?>
                                        <span class="badge badge-sql">MySQL</span>
                                    <?php endif; ?>
                                </td>
                                <td>Lv.<?php echo htmlspecialchars($p['difficulty']); ?></td>
                                <td>
                                    <a href="problem.php?id=<?php echo htmlspecialchars($p['id']); ?>" class="btn btn-primary" style="padding: 6px 14px; font-size: 0.85rem; white-space: nowrap; min-width: 60px; text-align: center;">풀기</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (empty($problems)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 30px;">
                                    등록된 문제가 없습니다. 관리자 패널에서 추가해주세요.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</body>

</html>