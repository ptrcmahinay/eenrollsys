<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('registrar');

if (isset($_GET['template_only'])) {
    $rows = fetch_all(
        'SELECT s.student_number, s.full_name, sub.subject_code, COALESCE(ss.final_grade, "") AS final_grade
         FROM student_subjects ss
         INNER JOIN students s ON s.id = ss.student_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         WHERE ss.term_id = :term_id
         ORDER BY s.student_number, sub.subject_code',
        ['term_id' => (int) (current_term()['id'] ?? 0)]
    );
    $csvRows = array_map(static fn($row) => [$row['student_number'], $row['full_name'], $row['subject_code'], $row['final_grade']], $rows);
    require_once __DIR__ . '/../includes/grade_upload_helpers.php';
    output_grade_template_csv($csvRows);
}

if (is_post()) {
    $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
    $grade = trim($_POST['final_grade'] ?? '');
    $registrarStaff = current_staff();
    save_grade($studentSubjectId, $grade, (int) ($registrarStaff['staff_id'] ?? 0));
    flash('success', 'Grade updated.');
    redirect('registrar/upload_grades.php');
}

$rows = fetch_all(
    'SELECT ss.id AS student_subject_id, s.student_number, s.full_name, sub.subject_code, sub.subject_description,
            ss.final_grade, p.program_code, sec.section_name, ay.year_label, t.semester
     FROM student_subjects ss
     INNER JOIN students s ON s.id = ss.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = ss.section_id
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, s.student_number, sub.subject_code'
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Registrar Grade Management</h1>
        <p>View and edit grades across students. This page also provides a CSV template export for local bulk work.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/upload_grades.php?template_only=1')) ?>">Download CSV Template</a>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student</th><th>Program / Section</th><th>Term</th><th>Subject</th><th>Description</th><th>Final Grade</th><th>Save</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['student_number'] . ' - ' . $row['full_name']) ?></td>
                    <td><?= h($row['program_code'] . ' ' . ($row['section_name'] ?: '')) ?></td>
                    <td><?= h($row['year_label'] . ' / ' . semester_label((string) $row['semester'])) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="student_subject_id" value="<?= h($row['student_subject_id']) ?>">
                            <input type="text" name="final_grade" value="<?= h($row['final_grade']) ?>" style="max-width:100px;">
                    </td>
                    <td>
                            <button class="btn small" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Registrar Grade Management', 'Grade Management', (string) ob_get_clean());
