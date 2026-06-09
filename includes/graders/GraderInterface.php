<?php
    interface GraderInterface {
        /*
        * 채점을 수행하고 결과를 배열로 반환함.
        * @param array $submission 유저 제출 데이터 (code, memory_limit 등)
        * @param array $problem 문제 데이터
        * @param array $testCases 테스트 케이스 (필요 시)
        * @return array [ 'status', 'execution_time', 'error', 'temp_files', 'temp_dirs' ]
        */
        public function grade(array $submission, array $testCases = []): array;
    }
?>