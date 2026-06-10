CREATE TABLE IF NOT EXISTS employees (
    id INT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    department VARCHAR(50) NOT NULL,
    salary INT NOT NULL,
    hire_date DATE NOT NULL
);

INSERT INTO employees (id, name, department, salary, hire_date) VALUES 
(1, 'Alice', 'Sales', 5000, '2020-01-15'),
(2, 'Bob', 'Engineering', 8000, '2019-03-22'),
(3, 'Charlie', 'Sales', 6000, '2021-07-01'),
(4, 'David', 'Engineering', 9500, '2018-11-10'),
(5, 'Eve', 'HR', 4500, '2022-02-28'),
(6, 'Frank', 'Marketing', 5500, '2020-05-12'),
(7, 'Grace', 'Engineering', 9000, '2021-01-05');
