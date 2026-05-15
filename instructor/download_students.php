<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('instructor');

$staff = current_staff();
$offeringId = (int) ($_GET['offering_id'] ?? 0);

header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="students_offering_' . $offeringId . '.csv"');
$output = fopen('php://output', 'w');
fputcsv($output, ['student_number', 'full_name', 'address', 'final_grade']);

if ($staff !== null && $offeringId > 0) {
    $rows = fetch_all(
        'SELECT s.student_number, s.full_name, s.address, ss.final_grade
         FROM student_subjects ss
         INNER JOIN students s ON s.id = ss.student_id
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         WHERE ss.offering_id = :offering_id AND o.instructor_id = :instructor_id
         ORDER BY s.student_number',
        ['offering_id' => $offeringId, 'instructor_id' => (int) $staff['staff_id']]
    );
    foreach ($rows as $row) {
        fputcsv($output, [$row['student_number'], $row['full_name'], $row['address'], $row['final_grade']]);
    }
}

fclose($output);
exit;
