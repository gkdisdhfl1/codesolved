<?php
// problem.php
session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/tier_helper.php';

// 문제 ID 파라미터 받기 (기본값 1)
$problem_id = isset($_GET['id']) ? (int)$_GET['id'] : 1;

// 문제 정보 조회
$stmt = $pdo->prepare("SELECT * FROM problems WHERE id = :id");
$stmt->execute(['id' => $problem_id]);
$problem = $stmt->fetch();

if (!$problem) {
    $_SESSION['error_message'] = '존재하지 않는 문제입니다.';
    header('Location: index.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ko">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($problem['title']); ?> - CodeSolved</title>

    <!-- 공통 디자인 시스템 CSS -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css">

    <!-- 본 페이지 전용 IDE 레이아웃 CSS -->
    <style>
        .ide-container {
            display: flex;
            height: calc(100vh - 70px);
            /* 헤더 공간 제외 꽉 찬 화면 */
            background-color: var(--bg-primary);
        }

        .problem-panel {
            width: 45%;
            padding: 30px 40px;
            overflow-y: auto;
            border-right: 1px solid var(--border-color);
        }

        .editor-panel {
            width: 55%;
            display: flex;
            flex-direction: column;
            background-color: #1e1e1e;
            /* Monaco 다크 테마 배경색 매칭 */
        }

        .editor-toolbar {
            height: 55px;
            background: var(--bg-secondary);
            border-bottom: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            padding: 0 20px;
            justify-content: space-between;
        }

        #monaco-editor {
            flex-grow: 1;
            /* 남은 공간 모두 차지 */
        }

        .console-panel {
            height: 200px;
            background-color: #0d1117;
            border-top: 1px solid var(--border-color);
            padding: 15px 20px;
            font-family: 'Consolas', 'Courier New', monospace;
            color: #a5d6ff;
            overflow-y: auto;
            font-size: 0.95rem;
            line-height: 1.5;
            box-shadow: inset 0 5px 15px rgba(0, 0, 0, 0.5);
        }

        /* 채점 상태별 텍스트 색상 및 애니메이션 */
        .status-pending {
            color: var(--warning);
            animation: blink 1.5s infinite;
        }

        .status-running {
            color: var(--accent-color);
            animation: blink 1s infinite;
        }

        .status-accepted {
            color: var(--success);
            font-weight: bold;
        }

        .status-wrong {
            color: var(--error);
            font-weight: bold;
        }

        @keyframes blink {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .btn-submit {
            background-color: var(--success);
            color: white;
            border: none;
            padding: 8px 24px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 6px rgba(46, 160, 67, 0.2);
        }

        .btn-submit:hover {
            background-color: #3fb950;
            transform: translateY(-2px);
            box-shadow: 0 6px 12px rgba(46, 160, 67, 0.3);
        }

        .btn-submit:disabled {
            background-color: var(--bg-tertiary);
            color: var(--text-muted);
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }

        /* 문제 본문 스타일링 */
        .problem-title {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .problem-meta {
            display: flex;
            gap: 15px;
            margin-bottom: 30px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            color: var(--text-muted);
        }

        .problem-meta span {
            background: var(--bg-tertiary);
            padding: 4px 10px;
            border-radius: 4px;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--accent-color);
            margin: 25px 0 10px 0;
        }

        .section-content {
            background: var(--bg-secondary);
            padding: 15px 20px;
            border-radius: 8px;
            line-height: 1.7;
            font-size: 0.95rem;
            border: 1px solid var(--border-color);
        }
    </style>
</head>

<body>

    <!-- 글로벌 헤더 -->
    <header>
        <div class="logo">
            <span>&lt;/&gt;</span> CodeSolved
        </div>
        <div class="nav-links">
            <a href="index.php">대시보드</a>
            <a href="problem.php?id=1" class="active">문제 풀이</a>
        </div>
    </header>

    <div class="ide-container">
        <!-- 📝 좌측: 문제 설명 패널 -->
        <div class="problem-panel">
            <h1 class="problem-title">
                <?php echo $problem['id'] . ". " . htmlspecialchars($problem['title']); ?>
            </h1>
            <div class="problem-meta">
                <span>⏱️ 시간 제한: <?php echo htmlspecialchars($problem['time_limit']); ?>초</span>
                <span>💾 메모리 제한: <?php echo htmlspecialchars($problem['memory_limit']); ?>MB</span>
                <span>🔥 난이도: <?php echo htmlspecialchars($problem['difficulty']); ?></span>
            </div>

            <div class="section-title">문제</div>
            <div class="section-content">
                <?php echo nl2br(htmlspecialchars($problem['description'])); ?>
            </div>

            <div class="section-title">입력</div>
            <div class="section-content">
                <?php echo nl2br(htmlspecialchars($problem['input_desc'])); ?>
            </div>

            <div class="section-title">출력</div>
            <div class="section-content">
                <?php echo nl2br(htmlspecialchars($problem['output_desc'])); ?>
            </div>
        </div>

        <!-- 💻 우측: 웹 IDE 패널 -->
        <div class="editor-panel">
            <div class="editor-toolbar">
                <select id="lang-select" style="padding: 6px 12px; border-radius: 4px; background: var(--bg-tertiary); color: white; border: 1px solid var(--border-color); font-weight: 500; font-size: 0.9rem; outline: none;">
                    <?php if ($problem['type'] === 'python'): ?>
                        <option value="python">Python 3</option>
                    <?php elseif ($problem['type'] === 'sql'): ?>
                        <option value="sql">MySQL</option>
                    <?php endif; ?>
                </select>
                <button id="submit-btn" class="btn-submit" onclick="submitCode()">제출하기 (Submit)</button>
            </div>

            <!-- Monaco 에디터가 마운트될 DOM -->
            <div id="monaco-editor"></div>

            <!-- 채점 결과 라이브 콘솔 -->
            <div class="console-panel" id="console-output">
                > CodeSolved 터미널 초기화 완료.<br>
                > 준비 완료. 코드를 작성하고 우측 상단의 '제출하기' 버튼을 눌러주세요.
            </div>
        </div>
    </div>

    <!-- Monaco Editor CDN 로드 -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs/loader.min.js"></script>
    <script>
        // 1. Monaco Editor 초기화 로직
        require.config({
            paths: {
                'vs': 'https://cdnjs.cloudflare.com/ajax/libs/monaco-editor/0.39.0/min/vs'
            }
        });

        let editor;
        require(['vs/editor/editor.main'], function() {
            const langMap = {
                'python': 'python',
                'sql': 'mysql'
            };
            const currentLang = document.getElementById('lang-select').value;

            // 기본 코드 스니펫
            let defaultCode = "";
            if (currentLang === 'python') {
                defaultCode = "# 여기에 코드를 작성하세요\n\nimport sys\n# input = sys.stdin.readline\n";
            } else if (currentLang === 'sql') {
                defaultCode = "-- 여기에 쿼리를 작성하세요\n\nSELECT *\nFROM 테이블명;\n";
            }

            const editorLang = langMap[currentLang] || 'plaintext';

            editor = monaco.editor.create(document.getElementById('monaco-editor'), {
                value: defaultCode,
                language: editorLang,
                theme: 'vs-dark', // 고급스러운 VS Code 다크 테마 적용
                fontSize: 16,
                fontFamily: "'Consolas', 'Courier New', monospace",
                minimap: {
                    enabled: false
                }, // 미니맵 가리기
                automaticLayout: true,
                padding: {
                    top: 20
                },
                scrollBeyondLastLine: false,
                roundedSelection: true
            });
        });

        // 2. 비동기 코드 제출 및 라이브 채점 UX 로직
        async function submitCode() {
            const submitBtn = document.getElementById('submit-btn');
            const consoleOut = document.getElementById('console-output');

            // Monaco Editor가 아직 로드되지 않은 상태에서 제출을 막음
            if (typeof editor === 'undefined' || !editor) {
                alert("에디터 모듈을 불러오는 중입니다. 잠시만 기다려주세요.");
                return;
            }

            const code = editor.getValue();
            const language = document.getElementById('lang-select').value;
            const problemId = <?php echo $problem_id; ?>;

            if (!code.trim()) {
                alert("코드를 작성해주세요!");
                return;
            }

            // 버튼 비활성화 및 로딩 표시
            submitBtn.disabled = true;
            submitBtn.innerText = "채점 중...";

            // 콘솔 초기화
            consoleOut.innerHTML = `> 서버에 코드 전송 중...<br>`;

            try {
                // A. submit.php 호출 (큐에 '대기 중' 상태로 등록)
                const submitRes = await fetch('submit.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({
                        problem_id: problemId,
                        code: code,
                        language: language
                    })
                });

                if (!submitRes.ok) {
                    if (submitRes.status === 401) {
                        consoleOut.innerHTML += `<span class="status-wrong">❌ 로그인이 필요한 서비스입니다. 대시보드에서 로그인해주세요.</span><br>`;
                    } else {
                        consoleOut.innerHTML += `<span class="status-wrong">❌ 서버 오류가 발생했습니다. (상태 코드: ${submitRes.status}).</span><br>`;
                    }

                    submitBtn.disabled = false;
                    submitBtn.innerText = "제출하기 (Submit)";
                    return;
                }

                const submitData = await submitRes.json();

                if (!submitData.success) {
                    const span = document.createElement('span');
                    span.className = 'status-wrong';
                    span.textContent = `❌ 제출 실패: ${submitData.message}`;  
                    consoleOut.appendChild(span);
                    consoleOut.appendChild(document.createElement('br'));
                    submitBtn.disabled = false;
                    submitBtn.innerText = "제출하기 (Submit)";
                    return;
                }

                const submissionId = parseInt(submitData.submission_id, 10);
                consoleOut.innerHTML += `> 제출 완료! (ID: ${submissionId})<br>`;
                consoleOut.innerHTML += `> <span class="status-pending">대기 중...</span><br>`;

                // UX 연출을 위한 인위적인 0.5초 대기 (서버가 준비하는 느낌 연출)
                await new Promise(r => setTimeout(r, 500));
                consoleOut.innerHTML += `> <span class="status-running">채점 중 (Running)...</span><br>`;

                // B. grade.php 백그라운드 비동기 호출 (실제 채점 수행)
                const gradeRes = await fetch('grade.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json'
                    },
                    body: JSON.stringify({ id: submissionId })
                });

                if (!gradeRes.ok) {
                    consoleOut.innerHTML += `<span class="status-wrong"> ❌ 채점 중 서버 오류가 발생했습니다. (상태 코드: ${gradeRes.status})</span><br>`;
                    submitBtn.disabled = false;
                    submitBtn.innerText = "제출하기 (Submit)";
                    return;
                }
                const gradeData = await gradeRes.json();

                if (!gradeData.success) {
                    const span = document.createElement('span');
                    span.className = 'status-wrong';
                    span.textContent = `❌ 시스템 에러: ${gradeData.message}`;  
                    consoleOut.appendChild(span);
                    consoleOut.appendChild(document.createElement('br'));
                } else {
                    const isAccepted = gradeData.status === '맞았습니다' || gradeData.status === '맞았습니다!!' || gradeData.status === '정답';
                    const statusClass = isAccepted ? 'status-accepted' : 'status-wrong';

                    const execTime = parseInt(gradeData.execution_time, 10) || 0;
                    consoleOut.innerHTML += `채점 완료! [실행 시간: ${execTime}ms]<br>`;

                    const resultText = document.createTextNode('> 최종 결과: ');
                    const statusSpan = document.createElement('span');
                    statusSpan.className = statusClass;
                    statusSpan.textContent = gradeData.status;

                    consoleOut.appendChild(resultText);
                    consoleOut.appendChild(statusSpan);
                    consoleOut.appendChild(document.createElement('br'));

                    if (gradeData.error) {
                        consoleOut.appendChild(document.createElement('br'));
                        const errorLabel = document.createTextNode('[상세 오류 로그]');
                        consoleOut.appendChild(errorLabel);

                        const pre = document.createElement('pre');
                        pre.style.color = 'var(--error)';
                        pre.style.marginTop = '5px';
                        pre.style.fontSize = '0.9rem';
                        pre.textContent = gradeData.error; // 태그를 문자로 치환
                        consoleOut.appendChild(pre);
                    }
                }
            } catch (err) {
                consoleOut.innerHTML += `<span class="status-wrong">❌ 네트워크 통신 오류가 발생했습니다.</span><br>`;
            }

            // 버튼 상태 원상복구
            submitBtn.disabled = false;
            submitBtn.innerText = "제출하기 (Submit)";

            // 스크롤 맨 아래로 이동
            consoleOut.scrollTop = consoleOut.scrollHeight;
        }
    </script>
</body>

</html>