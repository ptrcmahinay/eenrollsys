<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$studentId = (int) ($_GET['student_id'] ?? $_POST['student_id'] ?? 0);
$student = fetch_one(
    'SELECT s.*, p.program_code, p.program_name, sec.section_name
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     WHERE s.id = :id',
    ['id' => $studentId]
);
if ($student === null) {
    flash('error', 'Student not found.');
    redirect('registrar/students.php');
}

if (is_post() && ($_POST['action'] ?? '') === 'save_grade') {
    $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
    $grade = trim($_POST['final_grade'] ?? '');
    $registrarStaff = current_staff();
    save_grade($studentSubjectId, $grade, (int) ($registrarStaff['staff_id'] ?? 0));
    flash('success', 'Grade updated by registrar.');
    redirect('registrar/student_detail.php?student_id=' . $studentId);
}

$financial = financial_profile($student);
$terms = student_terms_with_enrollment($studentId);
$requests = fetch_all(
    'SELECT er.*, ay.year_label, t.semester
     FROM enrollment_requests er
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE er.student_id = :student_id
     ORDER BY er.created_at DESC',
    ['student_id' => $studentId]
);

$gradeRows = fetch_all(
    'SELECT ss.id AS student_subject_id, ss.final_grade, ss.units, sub.subject_code, sub.subject_description,
            ay.year_label, t.semester
     FROM student_subjects ss
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.student_id = :student_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
    ['student_id' => $studentId]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= h($student['full_name']) ?></h1>
        <p><?= h($student['student_number']) ?> · <?= h($student['program_code']) ?> · <?= h($student['year_level'] . ($student['section_name'] ? $student['section_name'] : '')) ?></p>
    </div>
    <div class="actions-row">
        <?php foreach ($terms as $term): ?>
            <a class="btn secondary small" href="<?= h(app_url('student/registration_form.php?student_id=' . $studentId . '&term_id=' . $term['id'])) ?>">Reg Form <?= h($term['year_label']) ?></a>
            <a class="btn secondary small" href="<?= h(app_url('student/download_grades.php?student_id=' . $studentId . '&term_id=' . $term['id'])) ?>">COG <?= h($term['year_label']) ?></a>
        <?php endforeach; ?>
        <a class="btn" href="<?= h(app_url('checklist.php?student_id=' . $studentId)) ?>">Checklist</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/curriculum.php?program_id=' . $student['program_id'] . '&student_id=' . $studentId)) ?>">Curriculum View</a>
    </div>
</div>

<div class="grid cols-3">
    <div class="card">
        <h3>Student profile</h3>
        <div class="kv-list">
            <div class="item"><div class="k">Address</div><div class="v"><?= h($student['address']) ?></div></div>
            <div class="item"><div class="k">Entry Year</div><div class="v"><?= h($student['entry_year']) ?></div></div>
            <div class="item"><div class="k">Section</div><div class="v"><?= h($student['section_name'] ?: '-') ?></div></div>
            <div class="item"><div class="k">Status</div><div class="v"><?= h($student['status']) ?></div></div>
        </div>
    </div>
    <div class="card">
        <h3>Financial / RA 10931</h3>
        <div class="kv-list">
            <div class="item"><div class="k">Detected Status</div><div class="v"><?= h($financial['label']) ?></div></div>
            <div class="item"><div class="k">Years in College</div><div class="v"><?= h($financial['years_in_college']) ?></div></div>
            <div class="item"><div class="k">Tuition Per Unit</div><div class="v">₱<?= h(format_money($financial['tuition_per_unit'])) ?></div></div>
            <div class="item"><div class="k">Override</div><div class="v"><?= h($student['ra10931_override']) ?></div></div>
        </div>
    </div>
    <div class="card">
        <h3>Quick actions</h3>
        <div class="actions-row">
            <a class="btn secondary" href="<?= h(app_url('registrar/enrollment.php?student_id=' . $studentId)) ?>">Open Requests</a>
            <a class="btn secondary" href="<?= h(app_url('student/registration_form.php?student_id=' . $studentId)) ?>">Latest Reg Form</a>
            <a class="btn secondary" href="<?= h(app_url('student/download_grades.php?student_id=' . $studentId)) ?>">Latest COG</a>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>Enrollment requests</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Academic Year</th><th>Semester</th><th>Requested Status</th><th>Workflow</th><th>Units</th><th>Amount</th></tr></thead>
            <tbody>
            <?php foreach ($requests as $request): ?>
                <tr>
                    <td><?= h($request['year_label']) ?></td>
                    <td><?= h(semester_label((string) $request['semester'])) ?></td>
                    <td><?= h($request['requested_status']) ?></td>
                    <td><span class="badge <?= h(workflow_badge_class((string) $request['workflow_status'])) ?>"><?= h(request_workflow_label((string) $request['workflow_status'])) ?></span></td>
                    <td><?= h($request['total_units']) ?></td>
                    <td>₱<?= h(format_money($request['total_amount'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>Registrar grade editor</h3>
    <p class="helper">The registrar can view and edit student grades here. Updates also sync to the grades table for the COG and checklist.</p>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Academic Year</th><th>Semester</th><th>Code</th><th>Description</th><th>Units</th><th>Final Grade</th><th>Save</th></tr></thead>
            <tbody>
            <?php foreach ($gradeRows as $row): ?>
                <tr>
                    <td><?= h($row['year_label']) ?></td>
                    <td><?= h(semester_label((string) $row['semester'])) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td><?= h($row['units']) ?></td>
                    <td>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="action" value="save_grade">
                            <input type="hidden" name="student_id" value="<?= h($studentId) ?>">
                            <input type="hidden" name="student_subject_id" value="<?= h($row['student_subject_id']) ?>">
                            <input type="text" name="final_grade" value="<?= h($row['final_grade']) ?>" style="max-width: 110px;">
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
<?php
render_page('Student Detail', 'Students', (string) ob_get_clean());
