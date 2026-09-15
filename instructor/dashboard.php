<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['instructor', 'adviser']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$user = current_user();
$allRoles = $user['roles'] ?? [];
$isInstructor = in_array('instructor', $allRoles, true);
$isAdviser = in_array('adviser', $allRoles, true);
$hasBoth = $isInstructor && $isAdviser;

$termId = (int) (current_term()['id'] ?? 0);

$offerings = [];
$totalStudents = 0;
$advisorySections = [];
$pendingRequests = [];

if ($isInstructor) {
    $offerings = fetch_all(
        'SELECT o.id, COALESCE(sc.sched_code, o.sched_code) AS sched_code, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name, COUNT(ss.id) AS student_count
         FROM section_subject_offerings o
         LEFT JOIN schedule_codes sc ON sc.offering_id = o.id
         LEFT JOIN class_schedules cs ON cs.schedule_code_id = sc.id
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
         WHERE (o.instructor_id = :instructor_id1 OR cs.instructor_id = :instructor_id2) AND o.term_id = :term_id
         GROUP BY o.id
         ORDER BY sub.subject_code',
        ['instructor_id1' => (int) $staff['staff_id'], 'instructor_id2' => (int) $staff['staff_id'], 'term_id' => $termId]
    );
    foreach ($offerings as $offering) {
        $totalStudents += (int) $offering['student_count'];
    }
}

if ($isAdviser) {
    $advisorySections = fetch_all(
        'SELECT sec.id, p.program_code, sec.year_level, sec.section_name, COUNT(stu.id) AS student_count
         FROM sections sec
         INNER JOIN programs p ON p.programs_id = sec.program_id
         LEFT JOIN students stu ON stu.section_id = sec.id
         WHERE sec.adviser_id = :adviser_id
         GROUP BY sec.id
         ORDER BY p.program_code, sec.year_level, sec.section_name',
        ['adviser_id' => (int) $staff['staff_id']]
    );

    $pendingRequests = fetch_all(
        'SELECT er.id, s.student_number, CONCAT(s.first_name, \' \', IFNULL(s.middle_name, \'\'), \' \', s.last_name) AS full_name, p.program_code, sec.section_name, er.requested_status
         FROM enrollment_requests er
         INNER JOIN students s ON s.id = er.student_id
         INNER JOIN programs p ON p.programs_id = s.program_id
         LEFT JOIN sections sec ON sec.id = er.requested_section_id
         WHERE er.workflow_status = "submitted" AND er.requested_section_id IN (
             SELECT id FROM sections WHERE adviser_id = :adviser_id
         )
         ORDER BY er.created_at DESC',
        ['adviser_id' => (int) $staff['staff_id']]
    );
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= $hasBoth ? 'Instructor & Adviser Dashboard' : ($isAdviser ? 'Adviser Dashboard' : 'Instructor Dashboard') ?></h1>
        <p><?= $hasBoth
            ? 'View handled subjects, student lists, input grades, manage advisory sections, and review enrollment requests.'
            : ($isAdviser
                ? 'View advisory classes, student lists, grades, and approve or reject enrollment requests.'
                : 'View handled subjects, student lists, input final grades, and upload syllabi for students to access.')
        ?></p>
    </div>
    <div class="actions-row">
        <?php if ($isInstructor): ?>
            <a class="btn" href="<?= h(app_url('instructor/subjects.php')) ?>">My Subjects</a>
            <a class="btn secondary" href="<?= h(app_url('instructor/students.php')) ?>">Student Lists</a>
        <?php endif; ?>
        <?php if ($isAdviser): ?>
            <a class="btn<?= $isInstructor ? ' secondary' : '' ?>" href="<?= h(app_url('adviser/requests.php')) ?>">Enrollment Requests</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($isInstructor): ?>
<div class="grid cols-3">
    <div class="card slim"><div class="metric-label">Handled Offerings</div><div class="metric"><?= h((string) count($offerings)) ?></div></div>
    <div class="card slim"><div class="metric-label">Enrolled Students</div><div class="metric"><?= h((string) $totalStudents) ?></div></div>
    <div class="card slim"><div class="metric-label">Name</div><div class="metric" style="font-size:20px;"><?= h($staff['full_name']) ?></div></div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Handled subjects this term</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Sched Code</th><th>Subject</th><th>Description</th><th>Section</th><th>Students</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($offerings as $offering): ?>
                <tr>
                    <td><span class="badge" style="font-family:monospace;"><?= h($offering['sched_code'] ?? '—') ?></span></td>
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
<?php endif; ?>

<?php if ($isAdviser): ?>
<div class="grid cols-2" style="margin-top:16px;">
    <div class="card">
        <h3>Advisory sections (<?= count($advisorySections) ?>)</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Program</th><th>Year</th><th>Section</th><th>Students</th></tr></thead>
                <tbody>
                <?php foreach ($advisorySections as $section): ?>
                    <tr>
                        <td><?= h($section['program_code']) ?></td>
                        <td><?= h($section['year_level']) ?></td>
                        <td><?= h($section['section_name']) ?></td>
                        <td><?= h($section['student_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>Pending enrollment requests (<?= count($pendingRequests) ?>)</h3>
        <?php if ($pendingRequests === []): ?>
            <p style="text-align:center;color:#94a3b8;padding:20px 0;">No pending requests.</p>
        <?php else: ?>
            <ul class="list-clean">
                <?php foreach ($pendingRequests as $request): ?>
                    <li>
                        <strong><?= h($request['student_number'] . ' - ' . $request['full_name']) ?></strong><br>
                        <?= h($request['program_code'] . ' ' . $request['section_name']) ?> · <?= h($request['requested_status']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?php
$pageTitle = $hasBoth ? 'Instructor & Adviser Dashboard' : ($isAdviser ? 'Adviser Dashboard' : 'Instructor Dashboard');
render_page($pageTitle, 'Dashboard', (string) ob_get_clean());
