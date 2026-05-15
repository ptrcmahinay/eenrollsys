<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function grade_template_headers(): array
{
    return ['student_number', 'student_name', 'subject_code', 'final_grade'];
}

function output_grade_template_csv(array $rows = []): never
{
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="grade_template.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, grade_template_headers());
    foreach ($rows as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}
