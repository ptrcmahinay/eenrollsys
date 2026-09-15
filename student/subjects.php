<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
$currentTerm = current_term();
$rows = [];
if ($student !== null && $currentTerm !== null) {
    $rows = fetch_all(
        'SELECT sub.subject_code, sub.subject_description, ss.units,
                COALESCE(new_cs.day, cs.day, o.day_of_week) AS day_of_week,
                COALESCE(new_cs.time_range, cs.time_range, o.time_range) AS time_range,
                COALESCE(new_cs.room, cs.room, o.room) AS room,
                o.syllabus_path,
                COALESCE(new_cs.schedule_code, sc.sched_code, o.sched_code) AS sched_code,
                COALESCE(new_st.full_name, st.full_name, "TBA") AS instructor_name
         FROM student_subjects ss
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         LEFT JOIN schedule_codes sc ON sc.offering_id = o.id
         LEFT JOIN class_schedules cs ON cs.schedule_code_id = sc.id
         LEFT JOIN class_schedules new_cs ON new_cs.id = ss.schedule_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         LEFT JOIN staff st ON st.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
         LEFT JOIN staff new_st ON new_st.staff_id = new_cs.instructor_id
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
            <thead><tr><th>Sched Code</th><th>Subject</th><th>Description</th><th>Units</th><th>Schedule</th><th>Instructor</th><th data-dt-no-sort>Syllabus</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="badge" style="font-family:monospace;"><?= h($row['sched_code'] ?? '—') ?></span></td>
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
