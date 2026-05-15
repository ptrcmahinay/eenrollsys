<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('instructor');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$termId = (int) (current_term()['id'] ?? 0);
$offerings = fetch_all(
    'SELECT o.id, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name, COUNT(ss.id) AS student_count
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
     WHERE o.instructor_id = :instructor_id AND o.term_id = :term_id
     GROUP BY o.id
     ORDER BY sub.subject_code',
    ['instructor_id' => (int) $staff['staff_id'], 'term_id' => $termId]
);

$totalStudents = 0;
foreach ($offerings as $offering) {
    $totalStudents += (int) $offering['student_count'];
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Instructor Dashboard</h1>
        <p>View handled subjects, student lists, input final grades, and upload syllabi for students to access.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('instructor/subjects.php')) ?>">My Subjects</a>
        <a class="btn secondary" href="<?= h(app_url('instructor/students.php')) ?>">Student Lists</a>
    </div>
</div>

<div class="grid cols-3">
    <div class="card slim"><div class="metric-label">Handled Offerings</div><div class="metric"><?= h((string) count($offerings)) ?></div></div>
    <div class="card slim"><div class="metric-label">Enrolled Students</div><div class="metric"><?= h((string) $totalStudents) ?></div></div>
    <div class="card slim"><div class="metric-label">Instructor</div><div class="metric" style="font-size:20px;"><?= h($staff['full_name']) ?></div></div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Handled subjects this term</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Subject</th><th>Description</th><th>Section</th><th>Students</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($offerings as $offering): ?>
                <tr>
                    <td><?= h($offering['subject_code']) ?></td>
                    <td><?= h($offering['subject_description']) ?></td>
                    <td><?= h($offering['program_code'] . ' ' . $offering['year_level'] . $offering['section_name']) ?></td>
                    <td><?= h($offering['student_count']) ?></td>
                    <td><a class="btn small secondary" href="<?= h(app_url('instructor/students.php?offering_id=' . $offering['id'])) ?>">Open Students</a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Instructor Dashboard', 'Dashboard', (string) ob_get_clean());
