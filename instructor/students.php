<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('instructor');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
    $grade = trim($_POST['final_grade'] ?? '');
    save_grade($studentSubjectId, $grade, (int) $staff['staff_id']);
    flash('success', 'Final grade saved.');
    redirect('instructor/students.php?offering_id=' . (int) ($_POST['offering_id'] ?? 0));
}

$offerings = fetch_all(
    'SELECT o.id, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     WHERE o.instructor_id = :instructor_id
     ORDER BY sub.subject_code',
    ['instructor_id' => (int) $staff['staff_id']]
);
$offeringId = (int) ($_GET['offering_id'] ?? ($offerings[0]['id'] ?? 0));
$students = [];
$offeringInfo = null;
if ($offeringId > 0) {
    $offeringInfo = fetch_one(
        'SELECT o.*, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name
         FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         WHERE o.id = :id AND o.instructor_id = :instructor_id',
        ['id' => $offeringId, 'instructor_id' => (int) $staff['staff_id']]
    );
    if ($offeringInfo !== null) {
        $students = fetch_all(
            'SELECT ss.id AS student_subject_id, s.student_number, s.full_name, s.address, ss.final_grade
             FROM student_subjects ss
             INNER JOIN students s ON s.id = ss.student_id
             WHERE ss.offering_id = :offering_id AND ss.enrollment_status = "enrolled"
             ORDER BY s.student_number',
            ['offering_id' => $offeringId]
        );
    }
}

$termDeadline = current_term();
ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Lists and Final Grades</h1>
        <p>View handled subject student lists and input final grades. <?= deadline_badge('grade_deadline', $termDeadline) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('instructor/download_students.php?offering_id=' . $offeringId)) ?>">Download CSV</a>
    </div>
</div>
<div class="card">
    <form method="get" class="filter-bar">
        <div>
            <label>Offering</label>
            <select name="offering_id" onchange="this.form.submit()">
                <?php foreach ($offerings as $offering): ?>
                    <option value="<?= h($offering['id']) ?>" <?= $offeringId === (int) $offering['id'] ? 'selected' : '' ?>><?= h($offering['subject_code'] . ' [' . $offering['program_code'] . ' ' . $offering['year_level'] . $offering['section_name'] . ']') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($offeringInfo !== null): ?>
<div class="card" style="margin-top: 16px;">
    <h3><?= h($offeringInfo['subject_code'] . ' - ' . $offeringInfo['subject_description']) ?> / <?= h($offeringInfo['program_code'] . ' ' . $offeringInfo['year_level'] . $offeringInfo['section_name']) ?></h3>
    <div class="dt" data-dt-page-size="10">
<div class="table-wrap">
        <table>
            <thead><tr><th>Student Number</th><th>Name</th><th>Address</th><th>Final Grade</th><th data-dt-no-sort>Save</th></tr></thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <tr>
                    <td><?= h($student['student_number']) ?></td>
                    <td><?= h($student['full_name']) ?></td>
                    <td><?= h($student['address']) ?></td>
                    <td>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="offering_id" value="<?= h($offeringId) ?>">
                            <input type="hidden" name="student_subject_id" value="<?= h($student['student_subject_id']) ?>">
                            <input type="text" name="final_grade" value="<?= h($student['final_grade']) ?>" style="max-width:100px;">
                    </td>
                    <td>
                            <button class="btn small" type="submit">Save Grade</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php endif; ?>
<?php
render_page('Student Lists', 'Student List / Grades', (string) ob_get_clean());
