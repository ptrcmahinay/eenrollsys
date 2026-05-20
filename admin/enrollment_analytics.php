<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$term = current_term();
$termId = (int) ($_GET['term_id'] ?? ($term ? $term['id'] : 0));

$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester,"1","2","mid")'
);

$statusCounts = fetch_all(
    'SELECT workflow_status, COUNT(*) AS cnt FROM enrollment_requests WHERE term_id = :tid GROUP BY workflow_status',
    ['tid' => $termId]
);
$statusMap = [];
foreach ($statusCounts as $sc) $statusMap[$sc['workflow_status']] = (int) $sc['cnt'];
$totalRequests = array_sum($statusMap);

$byProgram = fetch_all(
    'SELECT p.program_code, COUNT(*) AS cnt
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     WHERE er.term_id = :tid
     GROUP BY p.program_code ORDER BY cnt DESC',
    ['tid' => $termId]
);

$bySection = fetch_all(
    'SELECT p.program_code, sec.year_level, sec.section_name, COUNT(*) AS cnt
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = er.requested_section_id
     WHERE er.term_id = :tid
     GROUP BY p.program_code, sec.year_level, sec.section_name
     ORDER BY cnt DESC LIMIT 15',
    ['tid' => $termId]
);

$overTime = fetch_all(
    'SELECT DATE(er.created_at) AS day, COUNT(*) AS cnt
     FROM enrollment_requests er
     WHERE er.term_id = :tid
     GROUP BY DATE(er.created_at)
     ORDER BY day',
    ['tid' => $termId]
);

$rejectionByStage = [
    'adviser' => (int) (fetch_one('SELECT COUNT(*) AS cnt FROM enrollment_requests WHERE term_id = :tid AND adviser_status = "rejected"', ['tid' => $termId])['cnt'] ?? 0),
    'chair' => (int) (fetch_one('SELECT COUNT(*) AS cnt FROM enrollment_requests WHERE term_id = :tid AND chair_status = "rejected"', ['tid' => $termId])['cnt'] ?? 0),
    'registrar' => (int) (fetch_one('SELECT COUNT(*) AS cnt FROM enrollment_requests WHERE term_id = :tid AND registrar_status = "rejected"', ['tid' => $termId])['cnt'] ?? 0),
];

$avgProcessing = fetch_one(
    'SELECT
        AVG(TIMESTAMPDIFF(HOUR, er.created_at, er.adviser_processed_at)) AS avg_adviser,
        AVG(TIMESTAMPDIFF(HOUR, er.adviser_processed_at, er.chair_processed_at)) AS avg_chair,
        AVG(TIMESTAMPDIFF(HOUR, er.chair_processed_at, er.registrar_processed_at)) AS avg_registrar
     FROM enrollment_requests er
     WHERE er.term_id = :tid',
    ['tid' => $termId]
);

$chartStatusLabels = [];
$chartStatusData = [];
$statusColors = ['draft' => '#6b7280', 'submitted' => '#3b82f6', 'adviser_approved' => '#f59e0b', 'chair_approved' => '#8b5cf6', 'registrar_approved' => '#0f5132', 'rejected' => '#ef4444', 'cancelled' => '#9ca3af'];
$statusDisplay = ['draft' => 'Draft', 'submitted' => 'Submitted', 'adviser_approved' => 'Adviser Approved', 'chair_approved' => 'Chair Approved', 'registrar_approved' => 'Enrolled', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
foreach ($statusDisplay as $key => $label) {
    if (isset($statusMap[$key])) {
        $chartStatusLabels[] = $label;
        $chartStatusData[] = $statusMap[$key];
    }
}

$chartProgramLabels = array_map(fn($r) => $r['program_code'], $byProgram);
$chartProgramData = array_map(fn($r) => (int) $r['cnt'], $byProgram);

$chartTimeLabels = array_map(fn($r) => $r['day'], $overTime);
$chartTimeData = array_map(fn($r) => (int) $r['cnt'], $overTime);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Enrollment Analytics</h1>
        <p>Overview of enrollment statistics, trends, and processing metrics.</p>
    </div>
</div>

<div style="display:flex;gap:10px;margin-bottom:16px;">
    <form method="get" style="display:flex;gap:8px;align-items:center;">
        <select name="term_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <?php foreach ($terms as $t): ?>
                <option value="<?= h((string)$t['id']) ?>" <?= $termId === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['year_label'] . ' · ' . semester_label((string)$t['semester'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </form>
</div>

<div class="grid cols-4">
    <div class="card slim"><div class="metric-label">Total Requests</div><div class="metric" style="font-size:22px;"><?= h((string) $totalRequests) ?></div></div>
    <div class="card slim"><div class="metric-label">Enrolled</div><div class="metric" style="font-size:22px;color:#0f5132;"><?= h((string) ($statusMap['registrar_approved'] ?? 0)) ?></div></div>
    <div class="card slim"><div class="metric-label">Pending</div><div class="metric" style="font-size:22px;color:#f59e0b;"><?= h((string) (($statusMap['submitted'] ?? 0) + ($statusMap['adviser_approved'] ?? 0) + ($statusMap['chair_approved'] ?? 0))) ?></div></div>
    <div class="card slim"><div class="metric-label">Rejected</div><div class="metric" style="font-size:22px;color:#ef4444;"><?= h((string) ($statusMap['rejected'] ?? 0)) ?></div></div>
</div>

<div class="grid cols-2" style="margin-top:16px;">
    <div class="card">
        <h3>Requests by Status</h3>
        <div style="position:relative;height:260px;"><canvas id="statusChart"></canvas></div>
    </div>
    <div class="card">
        <h3>Requests Over Time</h3>
        <div style="position:relative;height:260px;"><canvas id="timeChart"></canvas></div>
    </div>
</div>

<div class="grid cols-2" style="margin-top:16px;">
    <div class="card">
        <h3>Requests by Program</h3>
        <div style="position:relative;height:260px;"><canvas id="programChart"></canvas></div>
    </div>
    <div class="card">
        <h3>Rejection Rate by Stage</h3>
        <div style="position:relative;height:260px;"><canvas id="rejectionChart"></canvas></div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Processing Metrics</h3>
    <div class="grid cols-3">
        <div class="card slim">
            <h4>Avg. Adviser Processing</h4>
            <div class="metric" style="font-size:24px;"><?= $avgProcessing && $avgProcessing['avg_adviser'] !== null ? h(number_format((float) $avgProcessing['avg_adviser'], 1) . ' hrs') : 'N/A' ?></div>
        </div>
        <div class="card slim">
            <h4>Avg. Chair Processing</h4>
            <div class="metric" style="font-size:24px;"><?= $avgProcessing && $avgProcessing['avg_chair'] !== null ? h(number_format((float) $avgProcessing['avg_chair'], 1) . ' hrs') : 'N/A' ?></div>
        </div>
        <div class="card slim">
            <h4>Avg. Registrar Processing</h4>
            <div class="metric" style="font-size:24px;"><?= $avgProcessing && $avgProcessing['avg_registrar'] !== null ? h(number_format((float) $avgProcessing['avg_registrar'], 1) . ' hrs') : 'N/A' ?></div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Top Sections by Enrollment</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Program</th><th>Year</th><th>Section</th><th>Requests</th></tr></thead>
            <tbody>
            <?php foreach ($bySection as $row): ?>
                <tr>
                    <td><?= h($row['program_code']) ?></td>
                    <td><?= h($row['year_level']) ?></td>
                    <td><?= h($row['section_name'] ?: '-') ?></td>
                    <td><?= h((string) $row['cnt']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    if (typeof Chart === 'undefined') return;

    new Chart(document.getElementById('statusChart'), {
        type: 'doughnut',
        data: {
            labels: <?= json_encode($chartStatusLabels) ?>,
            datasets: [{ data: <?= json_encode($chartStatusData) ?>, backgroundColor: <?= json_encode(array_values(array_intersect_key($statusColors, array_flip(array_keys(array_combine($chartStatusLabels, $chartStatusData))))) ?: array_values($statusColors)) ?>, borderWidth: 0 }]
        },
        options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'bottom' } } }
    });

    new Chart(document.getElementById('timeChart'), {
        type: 'line',
        data: {
            labels: <?= json_encode($chartTimeLabels) ?>,
            datasets: [{ label: 'Requests', data: <?= json_encode($chartTimeData) ?>, borderColor: '#6366f1', backgroundColor: 'rgba(99,102,241,0.1)', tension: 0.3, fill: true, pointRadius: 4 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('programChart'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($chartProgramLabels) ?>,
            datasets: [{ label: 'Requests', data: <?= json_encode($chartProgramData) ?>, backgroundColor: '#0f5132', borderRadius: 6 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });

    new Chart(document.getElementById('rejectionChart'), {
        type: 'bar',
        data: {
            labels: ['Adviser', 'Chair', 'Registrar'],
            datasets: [{ label: 'Rejections', data: <?= json_encode([$rejectionByStage['adviser'], $rejectionByStage['chair'], $rejectionByStage['registrar']]) ?>, backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6'], borderRadius: 6 }]
        },
        options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
    });
});
</script>
<?php
render_page('Enrollment Analytics', 'Enrollment Analytics', (string) ob_get_clean());
