<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

header('Content-Type: application/json');

$studentNo = trim($_GET['student_no'] ?? '');
if ($studentNo === '') {
    echo json_encode(['error' => 'No student number provided']);
    exit;
}

$student = fetch_one(
    'SELECT s.id, s.student_number,
            CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
            p.programs_id AS program_id, p.program_name,
            d.dept_id AS department_id, d.department_name
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN departments d ON d.dept_id = p.department_id
     WHERE s.student_number = :sno LIMIT 1',
    ['sno' => $studentNo]
);

if (!$student) {
    echo json_encode(['error' => 'Student not found']);
    exit;
}

echo json_encode([
    'id'             => (int) $student['id'],
    'student_number' => $student['student_number'],
    'full_name'      => trim($student['full_name']),
    'program_id'     => $student['program_id'],
    'program_name'   => $student['program_name'],
    'department_id'  => $student['department_id'],
    'department_name'=> $student['department_name'],
]);
