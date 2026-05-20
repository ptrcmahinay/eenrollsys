<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

/* ── CSV Export ── */
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // Reuse same filters as main query — just build the full result set
    $eSql = 'SELECT s.student_number, s.full_name, s.year_level, p.program_code, sec.section_name,
                    ay.year_label, t.semester, er.requested_status, er.workflow_status,
                    er.total_units, er.total_amount, er.ra10931_status, er.created_at
             FROM enrollment_requests er
             INNER JOIN students s ON s.id = er.student_id
             INNER JOIN programs p ON p.programs_id = s.program_id
             INNER JOIN academic_terms t ON t.id = er.term_id
             INNER JOIN academic_years ay ON ay.id = t.academic_year_id
             LEFT JOIN sections sec ON sec.id = COALESCE(er.registrar_section_id, er.requested_section_id)
             WHERE 1=1';
    $eParams = [];
    $eTerm = (int) ($_GET['term_id'] ?? 0);
    $eStatus = trim($_GET['status'] ?? '');
    $eSearch = trim($_GET['q'] ?? '');
    if ($eTerm > 0)      { $eSql .= ' AND er.term_id = :tid';      $eParams['tid'] = $eTerm; }
    if ($eStatus !== '') { $eSql .= ' AND er.workflow_status = :ws'; $eParams['ws'] = $eStatus; }
    if ($eSearch !== '') { $eSql .= ' AND (s.full_name LIKE :q OR s.student_number LIKE :q2)'; $eParams['q'] = '%'.$eSearch.'%'; $eParams['q2'] = '%'.$eSearch.'%'; }
    $eSql .= ' ORDER BY s.full_name';
    $eRows = fetch_all($eSql, $eParams);

    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="enrollment_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student No.', 'Full Name', 'Year Level', 'Program', 'Section', 'Academic Year', 'Semester', 'Status', 'Workflow', 'Units', 'Amount', 'RA10931', 'Submitted At']);
    foreach ($eRows as $r) {
        fputcsv($out, [
            $r['student_number'], $r['full_name'], $r['year_level'], $r['program_code'],
            $r['section_name'], $r['year_label'], semester_label((string)$r['semester']),
            $r['requested_status'], request_workflow_label($r['workflow_status']),
            $r['total_units'], $r['total_amount'], $r['ra10931_status'], $r['created_at'],
        ]);
    }
    fclose($out);
    exit;
}

/* ── Filters ── */
$filterStatus  = trim($_GET['status']  ?? '');
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterTerm    = (int) ($_GET['term_id']    ?? 0);
$search        = trim($_GET['q'] ?? '');

$currentTerm = current_term();
if ($filterTerm === 0 && $currentTerm !== null) {
    $filterTerm = (int) $currentTerm['id'];
}

/* ── Build query ── */
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
        WHERE 1=1';
$params = [];

if ($filterTerm > 0) {
    $sql .= ' AND er.term_id = :term_id';
    $params['term_id'] = $filterTerm;
}
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

/* ── Summary counts for current term ── */
$summaryRows = fetch_all(
    'SELECT workflow_status, COUNT(*) AS cnt
     FROM enrollment_requests
     WHERE term_id = :tid
     GROUP BY workflow_status',
    ['tid' => $filterTerm > 0 ? $filterTerm : 0]
);
$summary = [];
foreach ($summaryRows as $r) { $summary[$r['workflow_status']] = (int) $r['cnt']; }
$total = array_sum($summary);

$terms    = fetch_all('SELECT t.id, ay.year_label, t.semester, t.is_active FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.start_year DESC, t.semester');
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

ob_start();
?>
<!-- Export button: placed before page-header wrapper -->
<?php
$exportQs = http_build_query(array_filter([
    'export'     => 'csv',
    'term_id'    => $filterTerm    ?: null,
    'status'     => $filterStatus  ?: null,
    'program_id' => $filterProgram ?: null,
    'q'          => $search        ?: null,
]));
?>
<div style="display:flex;justify-content:flex-end;margin-bottom:12px;">
    <a class="btn secondary" href="<?= h(app_url('admin/enrollment_list.php?' . $exportQs)) ?>">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;">download</span>
        Export CSV
    </a>
</div>
<div class="page-header">
    <div>
        <h1>Enrollment Masterlist</h1>
        <p>All enrollment requests across all students. Filter by term, status, or program.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/enrollment.php')) ?>">Registrar Queue</a>
    </div>
</div>

<!-- Summary cards -->
<div class="grid" style="grid-template-columns: repeat(auto-fill, minmax(130px,1fr)); gap:10px; margin-bottom:16px;">
    <?php
    $cardDefs = [
        ['label'=>'Total',          'key'=>null,                  'color'=>'var(--color-primary)'],
        ['label'=>'Submitted',      'key'=>'submitted',           'color'=>'#3b82f6'],
        ['label'=>'Adviser ✓',      'key'=>'adviser_approved',    'color'=>'#8b5cf6'],
        ['label'=>'Chair ✓',        'key'=>'chair_approved',      'color'=>'#f59e0b'],
        ['label'=>'Enrolled',       'key'=>'registrar_approved',  'color'=>'#10b981'],
        ['label'=>'Rejected',       'key'=>'rejected',            'color'=>'#ef4444'],
        ['label'=>'Cancelled',      'key'=>'cancelled',           'color'=>'#6b7280'],
    ];
    foreach ($cardDefs as $c):
        $val = $c['key'] === null ? $total : ($summary[$c['key']] ?? 0);
    ?>
    <div class="card slim" style="text-align:center;border-top:3px solid <?= $c['color'] ?>;">
        <div style="font-size:22px;font-weight:700;color:<?= $c['color'] ?>;"><?= $val ?></div>
        <div style="font-size:11px;color:var(--color-text-secondary);margin-top:2px;"><?= $c['label'] ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:12px;">
    <form method="get" style="display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;">
        <div>
            <label style="font-size:12px;">Term</label>
            <select name="term_id">
                <?php foreach ($terms as $t): ?>
                    <option value="<?= h($t['id']) ?>" <?= (int) $t['id'] === $filterTerm ? 'selected' : '' ?>>
                        <?= h($t['year_label'] . ' / ' . semester_label((string) $t['semester'])) ?>
                        <?= (int) $t['is_active'] ? ' ✓' : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;">Status</label>
            <select name="status">
                <?php foreach ($statusOptions as $val => $label): ?>
                    <option value="<?= h($val) ?>" <?= $val === $filterStatus ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;">Program</label>
            <select name="program_id">
                <option value="0">All Programs</option>
                <?php foreach ($programs as $prog): ?>
                    <option value="<?= h($prog['programs_id']) ?>" <?= (int) $prog['programs_id'] === $filterProgram ? 'selected' : '' ?>><?= h($prog['program_code']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label style="font-size:12px;">Search</label>
            <input type="text" name="q" value="<?= h($search) ?>" placeholder="Name or student number" style="width:180px;">
        </div>
        <button class="btn" type="submit">Filter</button>
        <a class="btn secondary" href="?">Reset</a>
    </form>
</div>

<!-- Table -->
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
        <h3 style="margin:0;">Results <span style="font-size:13px;font-weight:400;color:var(--color-text-secondary);">(<?= count($requests) ?> records)</span></h3>
    </div>
    <?php if (count($requests) === 0): ?>
        <p class="helper">No enrollment requests match the current filters.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Student</th>
                    <th>Program / Year</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th>Type</th>
                    <th>Units</th>
                    <th>Amount</th>
                    <th>Submitted</th>
                    <th>Last Update</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($requests as $req): ?>
                <tr>
                    <td><?= h($req['request_id']) ?></td>
                    <td>
                        <strong><?= h($req['full_name']) ?></strong><br>
                        <span style="font-size:11px;color:var(--color-text-secondary);"><?= h($req['student_number']) ?></span>
                    </td>
                    <td><?= h($req['program_code'] . ' · Year ' . $req['year_level']) ?></td>
                    <td><?= h($req['section_name'] ?: '—') ?></td>
                    <td>
                        <span class="badge <?= h(workflow_badge_class((string) $req['workflow_status'])) ?>">
                            <?= h(request_workflow_label((string) $req['workflow_status'])) ?>
                        </span>
                    </td>
                    <td><?= h(ucfirst($req['requested_status'])) ?></td>
                    <td><?= h($req['total_units']) ?></td>
                    <td>₱<?= h(format_money($req['total_amount'])) ?></td>
                    <td style="font-size:11px;"><?= h(date('M j, Y g:i A', strtotime($req['submitted_at']))) ?></td>
                    <td style="font-size:11px;"><?= h(date('M j, Y g:i A', strtotime($req['updated_at']))) ?></td>
                    <td style="font-size:11px;max-width:160px;">
                        <?php
                        $remarks = array_filter([
                            $req['adviser_remark']   ? 'Adviser: '   . $req['adviser_remark']   : '',
                            $req['chair_remark']     ? 'Chair: '     . $req['chair_remark']     : '',
                            $req['registrar_remark'] ? 'Registrar: ' . $req['registrar_remark'] : '',
                        ]);
                        echo h(implode(' | ', $remarks) ?: '—');
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php
render_page('Enrollment Masterlist', 'Enrollment List', (string) ob_get_clean());
