<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'chair', 'instructor']);

$programId = (int) ($_GET['program_id'] ?? 0);
if ($programId === 0) {
    flash('error', 'Select a program first.');
    redirect('registrar/curriculum.php');
}

$program = fetch_one('SELECT program_code, program_name FROM programs WHERE programs_id = :id', ['id' => $programId]);
if ($program === null) {
    flash('error', 'Program not found.');
    redirect('registrar/curriculum.php');
}

$curriculum = fetch_all(
    'SELECT pc.subject_id, pc.year_level, pc.semester, pc.curriculum_label, pc.standing,
            sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
            pre1.subject_code AS prereq1, pre2.subject_code AS prereq2, pre3.subject_code AS prereq3
     FROM program_curriculum pc
     INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
     LEFT JOIN subjects pre1 ON pre1.subject_id = pc.prerequisite_subject_id
     LEFT JOIN subjects pre2 ON pre2.subject_id = pc.prerequisite_subject_2_id
     LEFT JOIN subjects pre3 ON pre3.subject_id = pc.prerequisite_subject_3_id
     WHERE pc.program_id = :pid
     ORDER BY CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code',
    ['pid' => $programId]
);

$format = trim($_GET['format'] ?? 'csv');

if ($format === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="curriculum_' . $program['program_code'] . '_' . date('Ymd') . '.csv"');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Subject Code', 'Description', 'Year Level', 'Semester', 'Prerequisites', 'Standing', 'Curriculum Label']);
    foreach ($curriculum as $line) {
        $prereqs = [];
        if ($line['prereq1']) $prereqs[] = $line['prereq1'];
        if ($line['prereq2']) $prereqs[] = $line['prereq2'];
        if ($line['prereq3']) $prereqs[] = $line['prereq3'];
        fputcsv($out, [
            $line['subject_code'],
            $line['subject_description'],
            $line['year_level'],
            $line['semester'],
            implode(', ', $prereqs),
            $line['standing'] ?? '',
            $line['curriculum_label'],
        ]);
    }
    fclose($out);
    exit;
}

flash('error', 'Unsupported export format.');
redirect('registrar/curriculum.php?program_id=' . $programId);
