<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('instructor');
require_once __DIR__ . '/../includes/grade_upload_helpers.php';

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

if (isset($_GET['template_only'])) {
    $rows = fetch_all(
        'SELECT s.student_number, s.full_name, sub.subject_code, COALESCE(ss.final_grade, "") AS final_grade
         FROM student_subjects ss
         INNER JOIN students s ON s.id = ss.student_id
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         WHERE o.instructor_id = :instructor_id
         ORDER BY s.student_number, sub.subject_code',
        ['instructor_id' => (int) $staff['staff_id']]
    );
    $csvRows = array_map(static fn($row) => [$row['student_number'], $row['full_name'], $row['subject_code'], $row['final_grade']], $rows);
    output_grade_template_csv($csvRows);
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Upload Grades</h1>
        <p>For this PHP build, manual grade entry is the main workflow. You can still download a CSV template for offline encoding.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('instructor/students.php')) ?>">Open Manual Grade Entry</a>
        <a class="btn secondary" href="<?= h(app_url('instructor/upload_grades.php?template_only=1')) ?>">Download CSV Template</a>
    </div>
</div>
<div class="card">
    <p class="helper">After filling the CSV offline, you can manually encode the grades using the Student Lists / Grades page. This keeps the local build simple and transparent.</p>
</div>
<?php
render_page('Upload Grades', 'Upload Grades', (string) ob_get_clean());
