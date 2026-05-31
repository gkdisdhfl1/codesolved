-- 1. 기존 데이터베이스 초기화
DROP DATABASE IF EXISTS codesolved;
CREATE DATABASE codesolved CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE codesolved;

-- 2. 유저 테이블 생성
CREATE Table users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    rating INT DEFAULT 0, -- 레이팅 점수 (롤 티어 같이)
    streak INT DEFAULT 0, -- 연속 공부 스트릭
    last_solved_date DATE DEFAULT NULL, -- 마지막으로 문제 푼 날짜
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. 문제 테이블 생성
CREATE TABLE problems (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(100) NOT NULL,
    description TEXT NOT NULL,
    input_desc TEXT NOT NULL,
    output_desc TEXT NOT NULL,
    difficulty INT NOT NULL, -- 1(아이언 IV) ~ 25(챌린저)
    type ENUM('python', 'sql') NOT NULL,
    time_limit FLOAT DEFAULT 2.0, -- 초 단위 제한
    memory_limit INT DEFAULT 128, -- MB 단위 제한
    create_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. 테스트 케이스 테이블 생성
CREATE TABLE test_cases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_id INT NOT NULL,
    input_data TEXT, -- 표준 입력값
    output_data TEXT, -- 표준 정답 출력값
    is_sample TINYINT(1) DEFAULT 0, -- 1이면 유저에게 예제로 공개
    Foreign Key (problem_id) REFERENCES problems(id) ON DELETE CASCADE
);

-- 5. 제출 현황 테이블 생성
CREATE TABLE submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    problem_id INT NOT NULL,
    code TEXT NOT NULL,
    language VARCHAR(20) NOT NULL,
    status VARCHAR(30) DEFAULT '대기 중', -- 대기 중, 채점 중, 정답, 오답, 런타임 에러, 시간초과
    execution_time INT DEFAULT 0, -- ms 단위
    error_message TEXT DEFAULT NULL,
    create_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Foreign Key (user_id) REFERENCES users(id) ON DELETE CASCADE,
    Foreign Key (problem_id) REFERENCES problems(id) ON DELETE CASCADE
);

-- 6. 질문 및 토론 테이블 생성
CREATE TABLE discussions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    problem_id INT NOT NULL,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Foreign Key (problem_id) REFERENCES problems(id) ON DELETE CASCADE,
    Foreign Key (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 7. 댓글 테이블 생성
CREATE TABLE comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    discussion_id INT NOT NULL,
    user_id INT NOT NULL,
    content TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    Foreign Key (discussion_id) REFERENCES discussions(id) ON DELETE CASCADE,
    Foreign Key (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 8. [SQL 샌드박스용 테이블] 사원 테이블
CREATE TABLE employees (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    department VARCHAR(50) NOT NULL,
    salary INT NOT NULL,
    hire_date DATE NOT NULL
);