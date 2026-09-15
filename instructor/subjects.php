<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['instructor', 'adviser']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    $offeringId = (int) ($_POST['offering_id'] ?? 0);
    if ($offeringId > 0) {
        $path = upload_syllabus($_FILES['syllabus'] ?? [], $offeringId);
        if ($path !== null) {
            flash('success', 'Syllabus uploaded successfully.');
        } else {
            flash('error', 'Syllabus upload failed. Use PDF, DOC, or DOCX.');
        }
    }
    redirect('instructor/subjects.php');
}

$currentTerm = current_term();
$termFilter = '';
$termParams = ['instructor_id1' => (int) $staff['staff_id'], 'instructor_id2' => (int) $staff['staff_id']];
if ($currentTerm !== null) {
    $termFilter = ' AND o.term_id = :term_id';
    $termParams['term_id'] = (int) $currentTerm['id'];
}

$rows = fetch_all(
    'SELECT o.id,
            o.sched_code,
            o.syllabus_path,
            COALESCE(cs.day, o.day_of_week) AS day_of_week,
            COALESCE(cs.time_range, o.time_range) AS time_range,
            COALESCE(cs.room, o.room) AS room,
            sub.subject_code, sub.subject_description,
            p.program_code, sec.year_level, sec.section_name,
            COUNT(ss.id) AS student_count
     FROM section_subject_offerings o
     LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
     WHERE (o.instructor_id = :instructor_id1 OR cs.instructor_id = :instructor_id2)' . $termFilter . '
     GROUP BY o.id
     ORDER BY sub.subject_code',
    $termParams
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>My Subjects</h1>
        <p>Upload syllabi and review your handled subjects and student counts.</p>
    </div>
</div>
<div class="card">
    <div class="dt" data-dt-page-size="10">
<div class="table-wrap">
        <table>
            <thead><tr><th>Sched Code</th><th>Subject</th><th>Description</th><th>Section</th><th>Schedule</th><th>Students</th><th>Syllabus</th><th data-dt-no-sort>Upload</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><span class="badge" style="font-family:monospace;"><?= h($row['sched_code'] ?? '—') ?></span></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['subject_description']) ?></td>
                    <td><?= h($row['program_code'] . ' ' . $row['year_level'] . $row['section_name']) ?></td>
                    <td><?= h(trim(($row['day_of_week'] ?: 'TBA') . ' ' . ($row['time_range'] ?: ''))) ?><br><span class="helper">Room: <?= h($row['room'] ?: 'TBA') ?></span></td>
                    <td><?= h($row['student_count']) ?></td>
                    <td>
                        <?php if ($row['syllabus_path']): ?>
                            <a class="btn small secondary" target="_blank" href="<?= h(app_url($row['syllabus_path'])) ?>">Open Syllabus</a>
                        <?php else: ?>
                            <span class="helper">No syllabus yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form class="inline-form" method="post" enctype="multipart/form-data">
                            <input type="hidden" name="offering_id" value="<?= h($row['id']) ?>">
                            <input type="file" name="syllabus" required>
                            <button class="btn small" type="submit">Upload</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php
render_page('My Subjects', 'My Subjects', (string) ob_get_clean());
