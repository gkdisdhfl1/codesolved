-- ==========================================
-- 1. 초기 테스트/채점용 문제 데이터 시드 삽입
-- ==========================================

-- 문제 1: A + B (파이썬, Bronze IV 난이도 / 난이도 지수 5)
INSERT INTO problems (id, title, description, input_desc, output_desc, difficulty, type) VALUES
(1, 'A + B', '두 정수 A와 B를 입력받은 다음, A+B를 출력하는 프로그램을 작성하시오.',
'첫째 줄에 A와 B가 빈칸을 사이에 두고 주어진다.','첫재 줄에 A+B를 출력한다.', 5, 'python');

INSERT INTO test_cases (problem_id, input_data, output_data, is_sample) VALUES
(1, '1 2', '3', 1),
(1, '10 20', '30', 0),
(1, '100 200', '300', 0);

-- 문제 2: 짝수와 홀수 (파이썬, Iron II 난이도 / 난이도 지수 2)
INSERT INTO problems (id, title, description, input_desc, output_desc, difficulty, `type`) VALUES
(2, '짝수와 홀수', '정수 N이 주어졌을 때, 홀수이면 "Odd", 짝수이면 "Even"을 출력하시오.', 
'첫째 줄에 정수 N이 주어진다', '첫째 줄에 Odd 혹은 Even을 출력한다.', 2, 'python');

INSERT INTO test_cases (problem_id, input_data, output_data, is_sample) VALUES
(2, '3', 'Odd', 1),
(2, '4', 'Even', 1),
(2, '0', 'Even', 0),
(2, '-5', 'Odd', 0);

-- 문제 3: 팩토리얼 (파이썬, Godl IV 난이도 / 난이도 지수 13)
INSERT INTO problems (id, title, description, input_desc, output_desc, difficulty, `type`) VALUES
(3, '팩토리얼', 'N!을 계산하여 출력하는 프로그램을 작성하시오, (N은 0보다 크거나 같고 12보다 작거나 같은 정수)', 
'첫째 줄에 정수 N이 주어진다.', '첫째 줄에 N! 값을 출력한다.', 13, 'python');

INSERT INTO test_cases (problem_id, input_data, output_data, is_sample) VALUES
(3, '5', '120', 1),
(3, '0', '1', 0),
(3, '10', '3628800', 0);

-- 문제 4: 영업 부서 사원 조회 (SQL, Silver IV 난이도 / 난이도 지수 9)
INSERT INTO problems (id, title, description, input_desc, output_desc, difficulty, `type`) VALUES
(4, '영업 부서 사원 조회 (SQL)', '부서(department)가 "Sales"인 사원들의 모든 컬럼(id, name, department, salary, hire_date)을 선택하되,  
  급여(salary)가 높은 사원부터 내림차순으로 정렬하여 조회하는 SQL 문을 작성하시오.',
'SQL 문제이므로 입력값은 없으며, 사원 테이블(employees)을 조회해야 합니다.', '정답 쿼리와 조회 결과가 완벽하게 일치해야 정답 처리됩니다.', 9, 'sql');   

-- 문제 5: 최고 연봉 사원의 정보 (SQL, Gold II 난이도 / 난이도 지수 14)                                                                 
INSERT INTO problems (id, title, description, input_desc, output_desc, difficulty, `type`) VALUES
(5, '최고 연봉 사원의 정보 (SQL)', '회사에서 가장 높은 급여(salary)를 받는 사원의 이름(name)과 급여(salary)를 조회하는 SQL 문을 작성하시오.',
'사원 테이블(employees)을 대상으로 서브쿼리나 정렬 제한(LIMIT)을 사용하여 해결해 보세요.', 'name, salary 컬럼의 출력 결과가 일치해야 합니다.', 14, 'sql');

-- ==========================================
-- 2. SQL 실습용 사원 테이블 (employees) 초기 데이터
-- ==========================================
INSERT INTO employees (id, name, department, salary, hire_date) VALUES
(1, 'Faker', 'Sales', 5000000, '2013-03-01'),                                                                                           
(2, 'ShowMaker', 'Marketing', 3500000, '2018-06-01'),                                                                                   
(3, 'Chovy', 'Sales', 4800000, '2018-03-15'),                                                                                           
(4, 'Gumayusi', 'Sales', 3000000, '2020-09-01'),                                                                                        
(5, 'Keria', 'Design', 3200000, '2020-11-01'),                                                                                          
(6, 'Deft', 'Marketing', 4000000, '2014-02-01'); 

-- ==========================================
-- 3. 시뮬레이션용 유저 데이터 (비밀번호: 1234의 해시값)
-- ==========================================
INSERT INTO users (username, password_hash, rating, streak) VALUES
('whiteduck', '$2y$10$wU0c9lqI32F4kQ3N0L0bEu99h6Bih3Rj/4iXU.h186Q1Gg6hD0Y6K', 2800, 32), -- 챌린저급
('PyKing', '$2y$10$wU0c9lqI32F4kQ3N0L0bEu99h6Bih3Rj/4iXU.h186Q1Gg6hD0Y6K', 2250, 15),   -- 다이아몬드급                                 
('SQLMaster', '$2y$10$wU0c9lqI32F4kQ3N0L0bEu99h6Bih3Rj/4iXU.h186Q1Gg6hD0Y6K', 1850, 8), -- 플래티넘급                                   
('Newbie', '$2y$10$wU0c9lqI32F4kQ3N0L0bEu99h6Bih3Rj/4iXU.h186Q1Gg6hD0Y6K', 350, 2);    -- 아이언급 