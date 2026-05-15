<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('adviser');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$currentTerm = current_term();
if ($currentTerm === null) {
    flash('error', 'No active academic term.');
    redirect('adviser/dashboard.php');
}

$filterStatus = trim($_GET['status'] ?? 'all');

$sql = 'SELECT s.id, s.student_number, s.full_name, s.year_level,
               sec.section_name, p.program_code,
               MAX(er.workflow_status) AS latest_status
        FROM students s
        INNER JOIN sections sec ON sec.id = s.section_id
        INNER JOIN programs p ON p.programs_id = s.program_id
        LEFT JOIN enrollment_requests er ON er.student_id = s.id AND er.term_id = :term_id
        WHERE sec.adviser_id = :adviser_id';
$params = ['adviser_id' => (int) $staff['staff_id'], 'term_id' => (int) $currentTerm['id']];

$sql .= ' GROUP BY s.id, s.student_number, s.full_name, s.year_level, sec.section_name, p.program_code';

if ($filterStatus === 'not_submitted') {
    $sql .= ' HAVING latest_status IS NULL OR latest_status IN ("rejected", "cancelled")';
} elseif ($filterStatus === 'pending') {
    $sql .= ' HAVING latest_status IN ("submitted", "adviser_approved", "chair_approved")';
} elseif ($filterStatus === 'enrolled') {
    $sql .= ' HAVING latest_status = "registrar_approved"';
}

$sql .= ' ORDER BY sec.section_name, s.full_name';

$students = fetch_all($sql, $params);

$totalStudents = count(fetch_all(
    'SELECT s.id FROM students s INNER JOIN sections sec ON sec.id = s.section_id WHERE sec.adviser_id = :aid',
    ['aid' => (int) $staff['staff_id']]
));

$notSubmitted = 0; $pending = 0; $enrolled = 0;
foreach (fetch_all(
    'SELECT s.id, MAX(er.workflow_status) AS latest_status
     FROM students s
     INNER JOIN sections sec ON sec.id = s.section_id
     LEFT JOIN enrollment_requests er ON er.student_id = s.id AND er.term_id = :tid
     WHERE sec.adviser_id = :aid
     GROUP BY s.id',
    ['aid' => (int) $staff['staff_id'], 'tid' => (int) $currentTerm['id']]
) as $row) {
    $ws = $row['latest_status'];
    if ($ws === null || in_array($ws, ['rejected', 'cancelled'])) $notSubmitted++;
    elseif (in_array($ws, ['submitted', 'adviser_approved', 'chair_approved'])) $pending++;
    elseif ($ws === 'registrar_approved') $enrolled++;
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Advisory Class View</h1>
        <p>All students in your advisory sections and their enrollment status for the current term.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('adviser/requests.php')) ?>">Enrollment Requests</a>
    </div>
</div>

<div class="grid cols-4">
    <div class="card slim"><div class="metric-label">Total Advisees</div><div class="metric" style="font-size:22px;"><?= h((string) $totalStudents) ?></div></div>
    <div class="card slim"><div class="metric-label">Not Submitted</div><div class="metric" style="font-size:22px;color:#ef4444;"><?= h((string) $notSubmitted) ?></div></div>
    <div class="card slim"><div class="metric-label">Pending</div><div class="metric" style="font-size:22px;color:#f59e0b;"><?= h((string) $pending) ?></div></div>
    <div class="card slim"><div class="metric-label">Enrolled</div><div class="metric" style="font-size:22px;color:#0f5132;"><?= h((string) $enrolled) ?></div></div>
</div>

<div style="display:flex;gap:6px;margin:16px 0;">
    <?php
    $tabs = [
        'all' => ['label' => 'All Students', 'count' => $totalStudents],
        'not_submitted' => ['label' => 'Not Submitted', 'count' => $notSubmitted],
        'pending' => ['label' => 'Pending', 'count' => $pending],
        'enrolled' => ['label' => 'Enrolled', 'count' => $enrolled],
    ];
    foreach ($tabs as $key => $tab):
        $active = $filterStatus === $key;
    ?>
        <a href="?status=<?= h($key) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
           background:<?= $active ? '#6366f1' : 'var(--panel)' ?>;
           color:<?= $active ? '#fff' : 'var(--ink)' ?>;
           border:1px solid <?= $active ? '#6366f1' : 'var(--line)' ?>;">
            <?= h($tab['label']) ?>
            <span style="background:<?= $active ? 'rgba(255,255,255,.25)' : 'var(--line)' ?>;border-radius:999px;padding:1px 7px;font-size:11px;"><?= $tab['count'] ?></span>
        </a>
    <?php endforeach; ?>
</div>

<?php if (empty($students)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">people</span>
        <p class="helper">No students found for this filter.</p>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student Number</th><th>Full Name</th><th>Section</th><th>Year</th><th>Enrollment Status</th></tr></thead>
            <tbody>
            <?php foreach ($students as $stu): ?>
                <tr>
                    <td><?= h($stu['student_number']) ?></td>
                    <td><?= h($stu['full_name']) ?></td>
                    <td><?= h($stu['program_code'] . ' ' . $stu['year_level'] . '-' . $stu['section_name']) ?></td>
                    <td><?= h($stu['year_level']) ?></td>
                    <td>
                        <?php if ($stu['latest_status'] === null): ?>
                            <span class="badge danger">Not Submitted</span>
                        <?php else: ?>
                            <span class="badge <?= h(workflow_badge_class((string) $stu['latest_status'])) ?>"><?= h(request_workflow_label((string) $stu['latest_status'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>
<?php
render_page('Advisory Class View', 'Class View', (string) ob_get_clean());
