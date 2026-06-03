-- data/sql_init/4.sql
-- 4번 문제 (영업 부서 사원 조회) 및 5번 문제 공용 사원 테이블 세팅

CREATE TABLE employees (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    department VARCHAR(50) NOT NULL,
    salary INT NOT NULL,
    hire_date DATE NOT NULL
);

INSERT INTO employees (id, name, department, salary, hire_date) VALUES
(1, 'Faker', 'Sales', 5000000, '2013-03-01'),                                                                                           
(2, 'ShowMaker', 'Marketing', 3500000, '2018-06-01'),                                                                                   
(3, 'Chovy', 'Sales', 4800000, '2018-03-15'),                                                                                           
(4, 'Gumayusi', 'Sales', 3000000, '2020-09-01'),                                                                                        
(5, 'Keria', 'Design', 3200000, '2020-11-01'),                                                                                          
(6, 'Deft', 'Marketing', 4000000, '2014-02-01'); 