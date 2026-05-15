<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
$currentTerm = current_term();
$rows = [];
if ($student !== null && $currentTerm !== null) {
    $rows = fetch_all(
        'SELECT sub.subject_code, sub.subject_description, ss.units, o.day_of_week, o.time_range, o.room, o.syllabus_path,
                st.full_name AS instructor_name
         FROM student_subjects ss
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         LEFT JOIN staff st ON st.staff_id = o.instructor_id
         WHERE ss.student_id = :student_id AND ss.term_id = :term_id AND ss.enrollment_status = "enrolled"
         ORDER BY sub.subject_code',
        ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
    );
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Current Enrolled Subjects</h1>
        <p>Present academic year and semester subjects, schedules, assigned instructor, and syllabus links.</p>
    </div>
</div>
<div class="card">
    <div class="dt" data-dt-page-size="10">
<div class="table-wrap">
        <table>
            <thead><tr><th>Subject</th><th>Description</th><th>Units</th><th>Schedule</th><th>Instructor</th><th data-dt-no-sort>Syllabus</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td><?= h($row['units']) ?></td>
                    <td><?= h(trim(($row['day_of_week'] ?: 'TBA') . ' ' . ($row['time_range'] ?: ''))) ?><br><span class="helper">Room: <?= h($row['room'] ?: 'TBA') ?></span></td>
                    <td><?= h($row['instructor_name'] ?: 'TBA') ?></td>
                    <td>
                        <?php if ($row['syllabus_path']): ?>
                            <a class="btn small secondary" target="_blank" href="<?= h(app_url($row['syllabus_path'])) ?>">View Syllabus</a>
                        <?php else: ?>
                            <span class="helper">Not uploaded yet</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php
render_page('Current Subjects', 'Current Subjects', (string) ob_get_clean());
