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

$summaryRows = fetch_all(
    'SELECT workflow_status, COUNT(*) AS cnt FROM enrollment_requests WHERE term_id = :tid GROUP BY workflow_status',
    ['tid' => $termId]
);
$summary = [];
foreach ($summaryRows as $r) $summary[$r['workflow_status']] = (int) $r['cnt'];
$total = array_sum($summary);
$pending = ($summary['submitted'] ?? 0) + ($summary['adviser_approved'] ?? 0) + ($summary['chair_approved'] ?? 0);

$filterStatus  = trim($_GET['status']  ?? '');
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$search        = trim($_GET['q'] ?? '');

$sql = 'SELECT er.id AS request_id,
               er.workflow_status, er.requested_status, er.total_units,
               er.total_amount, er.ra10931_status,
               er.adviser_remark, er.chair_remark, er.registrar_remark,
               er.created_at AS submitted_at, er.updated_at,
               s.id AS student_id, s.student_number, s.full_name, s.year_level,
               p.program_code, p.program_name,
               sec.section_name,
               ay.year_label, t.semester
        FROM enrollment_requests er
        INNER JOIN students s ON s.id = er.student_id
        INNER JOIN programs p ON p.programs_id = s.program_id
        INNER JOIN academic_terms t ON t.id = er.term_id
        INNER JOIN academic_years ay ON ay.id = t.academic_year_id
        LEFT JOIN sections sec ON sec.id = COALESCE(er.registrar_section_id, er.requested_section_id)
        WHERE er.term_id = :tid';
$params = ['tid' => $termId];

if ($filterStatus !== '') {
    $sql .= ' AND er.workflow_status = :ws';
    $params['ws'] = $filterStatus;
}
if ($filterProgram > 0) {
    $sql .= ' AND p.programs_id = :prog';
    $params['prog'] = $filterProgram;
}
if ($search !== '') {
    $sql .= ' AND (s.full_name LIKE :q OR s.student_number LIKE :q2)';
    $params['q']  = '%' . $search . '%';
    $params['q2'] = '%' . $search . '%';
}

$sql .= ' ORDER BY FIELD(er.workflow_status, "submitted","adviser_approved","chair_approved","registrar_approved","rejected","cancelled"), er.updated_at DESC';
$requests = fetch_all($sql, $params);

$programs = fetch_all('SELECT programs_id, program_code FROM programs ORDER BY program_code');

$statusOptions = [
    ''                   => 'All Statuses',
    'submitted'          => 'Submitted',
    'adviser_approved'   => 'Adviser Approved',
    'chair_approved'     => 'Chair Approved',
    'registrar_approved' => 'Enrolled',
    'rejected'           => 'Rejected',
    'cancelled'          => 'Cancelled',
];

$exportQs = http_build_query(array_filter([
    'export'     => 'csv',
    'term_id'    => $termId ?: null,
    'status'     => $filterStatus ?: null,
    'program_id' => $filterProgram ?: null,
    'q'          => $search ?: null,
]));

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Enrollment Dashboard</h1>
        <p>Monitor and manage enrollment requests. Click a row to view the full workflow or take action.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/enrollment.php')) ?>">Action Queue &rarr;</a>
    </div>
</div>

<!-- Term selector + summary row -->
<div style="display:flex;gap:16px;margin-bottom:16px;align-items:stretch;flex-wrap:wrap;">
    <div style="display:flex;align-items:center;">
        <form method="get">
            <select name="term_id" onchange="this.form.submit()" style="padding:8px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;font-weight:600;">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= h((string)$t['id']) ?>" <?= $termId === (int)$t['id'] ? 'selected' : '' ?>>
                        <?= h($t['year_label'] . ' · ' . semester_label((string)$t['semester'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <div class="card slim" style="display:flex;align-items:center;gap:16px;padding:8px 20px;flex-wrap:wrap;">
        <div style="text-align:center;min-width:60px;">
            <div style="font-size:20px;font-weight:700;"><?= $total ?></div>
            <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Total</div>
        </div>
        <div style="width:1px;height:30px;background:var(--line);"></div>
        <div style="text-align:center;min-width:60px;">
            <div style="font-size:20px;font-weight:700;color:#f59e0b;"><?= $pending ?></div>
            <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Pending</div>
        </div>
        <div style="width:1px;height:30px;background:var(--line);"></div>
        <div style="text-align:center;min-width:60px;">
            <div style="font-size:20px;font-weight:700;color:#10b981;"><?= $summary['registrar_approved'] ?? 0 ?></div>
            <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Enrolled</div>
        </div>
        <div style="width:1px;height:30px;background:var(--line);"></div>
        <div style="text-align:center;min-width:60px;">
            <div style="font-size:20px;font-weight:700;color:#ef4444;"><?= $summary['rejected'] ?? 0 ?></div>
            <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Rejected</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:12px;">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <input type="hidden" name="term_id" value="<?= h((string)$termId) ?>">
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:2px;">Status</label>
            <select name="status" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
                <?php foreach ($statusOptions as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $val === $filterStatus ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:2px;">Program</label>
            <select name="program_id" style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:13px;">
                <option value="0">All Programs</option>
                <?php foreach ($programs as $prog): ?>
                    <option value="<?= h($prog['programs_id']) ?>" <?= (int) $prog['programs_id'] === $filterProgram ? 'selected' : '' ?>><?= h($prog['program_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:0.3px;display:block;margin-bottom:2px;">Search</label>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or student no." style="padding:6px 10px;border:1px solid var(--line);border-radius:6px;font-size:13px;width:180px;">
        </div>
        <button class="btn" type="submit" style="padding:6px 16px;">Filter</button>
        <a class="btn secondary" href="?term_id=<?= h((string)$termId) ?>" style="padding:6px 16px;">Reset</a>
        <a class="btn secondary" href="<?= h(app_url('admin/enrollment_dashboard.php?' . $exportQs)) ?>" style="padding:6px 16px;margin-left:auto;">
            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">download</span> CSV
        </a>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h3 style="margin:0;font-size:15px;">Enrollment Requests <span style="font-size:13px;font-weight:400;color:var(--muted);">(<?= count($requests) ?> records)</span></h3>
        <span id="selectedCount" style="font-size:12px;color:var(--muted);"></span>
    </div>
    <?php if (count($requests) === 0): ?>
        <p class="helper">No enrollment requests match the current filters.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="clickable-rows">
            <thead>
                <tr>
                    <th style="width:32px;"><input type="checkbox" id="selectAll" style="width:16px;height:16px;accent-color:#22c55e;cursor:pointer;"></th>
                    <th>Student</th>
                    <th>Program / Year</th>
                    <th>Status</th>
                    <th>Units</th>
                    <th>Amount</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
                <tr onclick="window.location='<?= h(app_url('admin/enrollment_detail.php?id=' . $req['request_id'])) ?>'" style="cursor:pointer;">
                    <td onclick="event.stopPropagation();">
                        <input type="checkbox" class="row-select" value="<?= h($req['request_id']) ?>" style="width:16px;height:16px;accent-color:#22c55e;cursor:pointer;">
                    </td>
                    <td>
                        <strong><?= h($req['full_name']) ?></strong><br>
                        <span style="font-size:11px;color:var(--muted);"><?= h($req['student_number']) ?></span>
                    </td>
                    <td><?= h($req['program_code'] . ' · Year ' . $req['year_level']) ?></td>
                    <td>
                        <span class="badge <?= h(workflow_badge_class((string) $req['workflow_status'])) ?>">
                            <?= h(request_workflow_label((string) $req['workflow_status'])) ?>
                        </span>
                    </td>
                    <td><?= h($req['total_units']) ?></td>
                    <td>₱<?= h(format_money($req['total_amount'])) ?></td>
                    <td style="font-size:11px;white-space:nowrap;"><?= h(date('M j, Y g:i A', strtotime($req['submitted_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<style>
table.clickable-rows tbody tr:hover { background: #f0fdf4; }
table.clickable-rows tbody tr td { padding: 10px 8px; vertical-align: middle; }
</style>
<script>
document.getElementById('selectAll')?.addEventListener('change', function() {
    document.querySelectorAll('.row-select').forEach(function(c) { c.checked = this.checked; }, this);
    updateSelectedCount();
});
document.querySelectorAll('.row-select').forEach(function(c) {
    c.addEventListener('change', updateSelectedCount);
});
function updateSelectedCount() {
    var n = document.querySelectorAll('.row-select:checked').length;
    var el = document.getElementById('selectedCount');
    if (el) el.textContent = n > 0 ? n + ' selected' : '';
}
</script>
<?php
render_page('Enrollment Dashboard', 'Enrollment Dashboard', (string) ob_get_clean());
