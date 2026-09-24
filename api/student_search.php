<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

header('Content-Type: application/json');

$q = trim($_GET['q'] ?? $_GET['student_no'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

$like = '%' . $q . '%';

$students = fetch_all(
    'SELECT s.id, s.student_number,
            CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
            p.programs_id AS program_id, p.program_name,
            d.dept_id AS department_id, d.department_name
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN departments d ON d.dept_id = p.department_id
     WHERE s.student_number LIKE :q1 OR s.first_name LIKE :q2 OR s.last_name LIKE :q3
     ORDER BY s.student_number ASC
     LIMIT 20',
    ['q1' => $like, 'q2' => $like, 'q3' => $like]
);

$results = [];
foreach ($students as $s) {
    $results[] = [
        'id'             => (int) $s['id'],
        'student_number' => $s['student_number'],
        'full_name'      => trim($s['full_name']),
        'program_id'     => $s['program_id'],
        'program_name'   => $s['program_name'],
        'department_id'  => $s['department_id'],
        'department_name'=> $s['department_name'],
    ];
}

echo json_encode($results);
