<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('adviser');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

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
    'SELECT er.id, s.student_number, s.full_name, p.program_code, sec.section_name, er.requested_status
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

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Adviser Dashboard</h1>
        <p>View advisory classes, student lists, grades, and manually approve or reject enrollment requests with remarks.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('adviser/requests.php')) ?>">Open Enrollment Requests</a>
    </div>
</div>

<div class="grid cols-3">
    <div class="card slim">
        <div class="metric-label">Advisory Sections</div>
        <div class="metric"><?= h((string) count($advisorySections)) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Pending Requests</div>
        <div class="metric"><?= h((string) count($pendingRequests)) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Adviser Name</div>
        <div class="metric" style="font-size:20px;"><?= h($staff['full_name']) ?></div>
    </div>
</div>

<div class="grid cols-2" style="margin-top: 16px;">
    <div class="card">
        <h3>Advisory classes</h3>
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
        <h3>Pending requests</h3>
        <ul class="list-clean">
            <?php foreach ($pendingRequests as $request): ?>
                <li>
                    <strong><?= h($request['student_number'] . ' - ' . $request['full_name']) ?></strong><br>
                    <?= h($request['program_code'] . ' ' . $request['section_name']) ?> · <?= h($request['requested_status']) ?>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>
<?php
render_page('Adviser Dashboard', 'Dashboard', (string) ob_get_clean());
