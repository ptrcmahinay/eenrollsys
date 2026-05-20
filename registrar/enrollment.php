<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$requestType = trim($_GET['type'] ?? 'enrollment');
if (!in_array($requestType, ['enrollment', 'add_drop'], true)) $requestType = 'enrollment';

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'finalize' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
        if ($req !== null && request_deadline_passed($req, 'registrar')) {
            flash('error', 'The registrar finalization deadline for this request has passed.');
            redirect('registrar/enrollment.php?type=' . $requestType . ($_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''));
        }
        if ($requestType === 'enrollment') {
            if ($requestId > 0 && $sectionId > 0) {
                if (finalize_request_by_registrar($requestId, $sectionId)) {
                    flash('success', 'Student enrolled successfully.');
                } else {
                    flash('error', 'Failed to enroll. Section may be full.');
                }
            }
        } else {
            if ($requestId > 0 && $sectionId > 0) {
                if (finalize_add_drop_as_registrar($requestId, $sectionId)) {
                    flash('success', 'Add/drop request finalized successfully.');
                } else {
                    flash('error', 'Failed to finalize add/drop. Section may be full.');
                }
            }
        }
        redirect('registrar/enrollment.php?type=' . $requestType . ($_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''));
    }

    if ($action === 'reject' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $remark = trim($_POST['registrar_remark'] ?? '');
        if ($requestType === 'enrollment') {
            if ($requestId > 0) {
                reject_request($requestId, 'registrar', $remark);
                flash('warning', 'Request rejected with registrar remark.');
            }
        } else {
            if ($requestId > 0) {
                reject_add_drop_request($requestId, 'registrar', $remark);
                flash('warning', 'Add/drop request rejected with registrar remark.');
            }
        }
        redirect('registrar/enrollment.php?type=' . $requestType . ($_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''));
    }

    if ($action === 'bulk_finalize' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $ids = $_POST['request_ids'] ?? [];
        $sectionId = (int) ($_POST['bulk_section_id'] ?? 0);
        $success = 0; $failed = 0; $skipped = 0;
        if ($requestType === 'enrollment') {
            foreach ((array) $ids as $id) {
                $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => (int) $id]);
                if ($req !== null && request_deadline_passed($req, 'registrar')) {
                    $skipped++;
                    continue;
                }
                if ($sectionId > 0 && finalize_request_by_registrar((int) $id, $sectionId)) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        } else {
            foreach ((array) $ids as $id) {
                if ($sectionId > 0 && finalize_add_drop_as_registrar((int) $id, $sectionId)) {
                    $success++;
                } else {
                    $failed++;
                }
            }
        }
        flash('success', "Bulk finalized: {$success} succeeded, {$failed} failed.");
        redirect('registrar/enrollment.php?type=' . $requestType . ($_SERVER['QUERY_STRING'] ? '&' . $_SERVER['QUERY_STRING'] : ''));
    }
}

$filterStatus = trim($_GET['status'] ?? '');
$filterSearch = trim($_GET['q'] ?? '');
$filterTerm   = (int) ($_GET['term_id'] ?? 0);

if ($requestType === 'enrollment') {
    $validStatuses = ['submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled'];
    if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';

    $terms = fetch_all(
        'SELECT t.id, ay.year_label, t.semester FROM academic_terms t
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         ORDER BY ay.start_year DESC, FIELD(t.semester,"1","2","mid")'
    );
    $currentTerm = current_term();
    if ($filterTerm === 0 && $currentTerm) $filterTerm = (int)$currentTerm['id'];

    $sql = 'SELECT er.*,
                   s.student_number, s.full_name, s.year_level, s.id AS student_id,
                   p.program_code, p.programs_id AS program_id,
                   sec.section_name  AS requested_section_name,
                   rsec.section_name AS registrar_section_name,
                   ay.year_label, t.semester
            FROM enrollment_requests er
            INNER JOIN students s    ON s.id          = er.student_id
            INNER JOIN programs p    ON p.programs_id = s.program_id
            INNER JOIN academic_terms t  ON t.id      = er.term_id
            INNER JOIN academic_years ay ON ay.id     = t.academic_year_id
            LEFT  JOIN sections sec  ON sec.id        = er.requested_section_id
            LEFT  JOIN sections rsec ON rsec.id       = er.registrar_section_id
            WHERE 1=1';
    $params = [];
    if ($filterTerm > 0) { $sql .= ' AND er.term_id = :tid'; $params['tid'] = $filterTerm; }
    if ($filterStatus !== '') { $sql .= ' AND er.workflow_status = :ws'; $params['ws'] = $filterStatus; }
    if ($filterSearch !== '') {
        $sql .= ' AND (s.student_number LIKE :q OR s.full_name LIKE :q2)';
        $params['q']  = '%' . $filterSearch . '%';
        $params['q2'] = '%' . $filterSearch . '%';
    }
    $sql .= ' ORDER BY FIELD(er.workflow_status,"chair_approved","adviser_approved","submitted","registrar_approved","rejected","cancelled"), er.updated_at DESC';
    $requests = fetch_all($sql, $params);

    $countSql = 'SELECT er.workflow_status, COUNT(*) AS cnt FROM enrollment_requests er
                 INNER JOIN students s ON s.id = er.student_id WHERE 1=1';
    $countParams = [];
    if ($filterTerm > 0) { $countSql .= ' AND er.term_id = :tid'; $countParams['tid'] = $filterTerm; }
    $counts = fetch_all($countSql, $countParams);
    $countMap = [];
    foreach ($counts as $c) $countMap[$c['workflow_status']] = (int)$c['cnt'];
    $totalAll = array_sum($countMap);
} else {
    $validStatuses = ['submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled'];
    if (!in_array($filterStatus, $validStatuses, true)) $filterStatus = '';

    $terms = fetch_all(
        'SELECT t.id, ay.year_label, t.semester FROM academic_terms t
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         ORDER BY ay.start_year DESC, FIELD(t.semester,"1","2","mid")'
    );
    $currentTerm = current_term();
    if ($filterTerm === 0 && $currentTerm) $filterTerm = (int)$currentTerm['id'];

    $sql = 'SELECT adr.*,
                   s.student_number, s.full_name, s.year_level, s.id AS student_id,
                   p.program_code, p.programs_id AS program_id,
                   sub.subject_code, sub.subject_description, sub.units AS subject_units,
                   sec.section_name,
                   ay.year_label, t.semester
            FROM add_drop_requests adr
            INNER JOIN students s    ON s.id          = adr.student_id
            INNER JOIN programs p    ON p.programs_id = s.program_id
            LEFT  JOIN subjects sub  ON sub.subject_id = adr.subject_id
            LEFT  JOIN sections sec  ON sec.id        = adr.section_id
            INNER JOIN academic_terms t  ON t.id      = adr.term_id
            INNER JOIN academic_years ay ON ay.id     = t.academic_year_id
            WHERE 1=1';
    $params = [];
    if ($filterTerm > 0) { $sql .= ' AND adr.term_id = :tid'; $params['tid'] = $filterTerm; }
    if ($filterStatus !== '') { $sql .= ' AND adr.workflow_status = :ws'; $params['ws'] = $filterStatus; }
    if ($filterSearch !== '') {
        $sql .= ' AND (s.student_number LIKE :q OR s.full_name LIKE :q2 OR sub.subject_code LIKE :q2)';
        $params['q']  = '%' . $filterSearch . '%';
        $params['q2'] = '%' . $filterSearch . '%';
    }
    $sql .= ' ORDER BY FIELD(adr.workflow_status,"chair_approved","adviser_approved","submitted","registrar_approved","rejected","cancelled"), adr.updated_at DESC';
    $requests = fetch_all($sql, $params);

    $countSql = 'SELECT adr.workflow_status, COUNT(*) AS cnt FROM add_drop_requests adr
                 INNER JOIN students s ON s.id = adr.student_id WHERE 1=1';
    $countParams = [];
    if ($filterTerm > 0) { $countSql .= ' AND adr.term_id = :tid'; $countParams['tid'] = $filterTerm; }
    $counts = fetch_all($countSql, $countParams);
    $countMap = [];
    foreach ($counts as $c) $countMap[$c['workflow_status']] = (int)$c['cnt'];
    $totalAll = array_sum($countMap);
}

$allSections = fetch_all('SELECT id, program_code, year_level, section_name FROM sections INNER JOIN programs ON programs.programs_id = sections.program_id ORDER BY program_code, year_level, section_name');

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= $requestType === 'enrollment' ? 'Enrollment Submissions' : 'Add/Drop Requests' ?></h1>
        <p><?= $requestType === 'enrollment' ? 'View all student enrollment requests, approve or reject, and bulk finalize chair-approved requests.' : 'Finalize approved add/drop requests to update student enrollments.' ?></p>
    </div>
</div>

<!-- Request Type Tabs -->
<div style="display:flex;gap:6px;margin-bottom:12px;border-bottom:1px solid var(--line);padding-bottom:10px;">
    <?php foreach ([['key' => 'enrollment', 'label' => '🎓 Enrollment'], ['key' => 'add_drop', 'label' => '✏️ Add/Drop']] as $rt): ?>
        <a href="?type=<?= h($rt['key']) ?>" style="display:inline-flex;align-items:center;gap:4px;padding:6px 16px;border-radius:8px;font-size:14px;font-weight:600;text-decoration:none;
           background:<?= $requestType === $rt['key'] ? '#6366f1' : 'transparent' ?>;
           color:<?= $requestType === $rt['key'] ? '#fff' : 'var(--muted)' ?>;"><?= h($rt['label']) ?></a>
    <?php endforeach; ?>
</div>

<!-- Term selector + search -->
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;align-items:center;">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="type" value="<?= h($requestType) ?>">
        <select name="term_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <option value="0">All terms</option>
            <?php foreach ($terms as $t): ?>
                <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['year_label'] . ' · ' . semester_label((string)$t['semester'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($filterStatus): ?><input type="hidden" name="status" value="<?= h($filterStatus) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= h($filterSearch) ?>" placeholder="Search name or student no…" style="padding:6px 12px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:210px;">
        <button class="btn small secondary" type="submit">Search</button>
        <?php if ($filterSearch || $filterStatus): ?>
            <a class="btn small secondary" href="<?= h(app_url('registrar/enrollment.php?type=' . $requestType . ($filterTerm ? '&term_id='.$filterTerm : ''))) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Status tabs -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px;">
    <?php
    $tabDefs = [
        ''                   => ['All',             $totalAll],
        'chair_approved'     => ['Chair Approved',  $countMap['chair_approved']     ?? 0],
        'adviser_approved'   => ['Adviser Approved',$countMap['adviser_approved']   ?? 0],
        'submitted'          => ['Submitted',       $countMap['submitted']          ?? 0],
        'registrar_approved' => [$requestType === 'enrollment' ? 'Enrolled' : 'Finalized', $countMap['registrar_approved'] ?? 0],
        'rejected'           => ['Rejected',        $countMap['rejected']           ?? 0],
        'cancelled'          => ['Cancelled',       $countMap['cancelled']          ?? 0],
    ];
    foreach ($tabDefs as $val => [$label, $cnt]):
        $active = $filterStatus === $val;
        $qs = http_build_query(array_filter(['type' => $requestType, 'term_id' => $filterTerm ?: null, 'status' => $val ?: null, 'q' => $filterSearch ?: null]));
        $url = app_url('registrar/enrollment.php' . ($qs ? '?' . $qs : ''));
    ?>
        <a href="<?= h($url) ?>"
           style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:999px;font-size:13px;font-weight:600;text-decoration:none;
                  background:<?= $active ? '#6366f1' : 'var(--panel)' ?>;
                  color:<?= $active ? '#fff' : 'var(--ink)' ?>;
                  border:1px solid <?= $active ? '#6366f1' : 'var(--line)' ?>;">
            <?= h($label) ?>
            <?php if ($cnt > 0): ?>
                <span style="background:<?= $active ? 'rgba(255,255,255,.25)' : 'var(--line)' ?>;border-radius:999px;padding:1px 7px;font-size:11px;"><?= $cnt ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- Bulk finalize form -->
<?php if ($filterStatus === '' || $filterStatus === 'chair_approved'): ?>
    <div class="card slim" style="margin-bottom:14px;">
        <form method="post" id="bulkForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_finalize">
            <input type="hidden" name="type" value="<?= h($requestType) ?>">
            <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <button type="button" class="btn small secondary" onclick="document.querySelectorAll('.bulk-select').forEach(c=>c.checked=true)">Select All Chair-Approved</button>
                <button type="button" class="btn small secondary" onclick="document.querySelectorAll('.bulk-select').forEach(c=>c.checked=false)">Deselect All</button>
                <select name="bulk_section_id" required style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                    <option value="">Select section for bulk enrollment…</option>
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?= h($sec['id']) ?>"><?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn small" id="bulkSubmitBtn" disabled>Finalize Selected (<span id="bulkCount">0</span>)</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php if (empty($requests)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">inbox</span>
        <p class="helper">No requests found for this filter.</p>
    </div>
<?php endif; ?>

<div class="grid" style="gap:14px;">
<?php foreach ($requests as $req):
    $ws     = $req['workflow_status'];
    $isFinal = $ws === 'registrar_approved';

    $advRejected = $req['adviser_status'] === 'rejected';
    $chRejected = $req['chair_status'] === 'rejected';
    $regRejected = $req['registrar_status'] === 'rejected';

    $advState = $req['adviser_status'] === 'approved' ? 'done' : ($advRejected ? 'rejected' : ($ws === 'submitted' ? 'active' : ''));
    $chState  = $advRejected ? 'blocked' : ($req['chair_status'] === 'approved' ? 'done' : ($chRejected ? 'rejected' : ($ws === 'adviser_approved' ? 'active' : '')));
    $regState = ($advRejected || $chRejected) ? 'blocked' : ($req['registrar_status'] === 'approved' ? 'done' : ($regRejected ? 'rejected' : ($ws === 'chair_approved' ? 'active' : '')));
    $steps = [
        ['label'=>'Submitted',   'state'=>'done',     'remark'=>''],
        ['label'=>'Adviser',     'state'=>$advState,  'remark'=>$req['adviser_remark']   ?: ''],
        ['label'=>'Dept. Chair', 'state'=>$chState,   'remark'=>$req['chair_remark']     ?: ''],
        ['label'=>'Registrar',   'state'=>$regState,  'remark'=>$req['registrar_remark'] ?: ''],
        ['label'=>$requestType === 'enrollment' ? 'Enrolled' : 'Finalized',    'state'=>$isFinal ? 'done' : ($advRejected || $chRejected || $regRejected ? 'blocked' : ''), 'remark'=>''],
    ];
?>
    <div class="card enroll-card status-<?= h($ws) ?>">
        <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:8px;">
            <div>
                <h3 style="margin:0;"><?= h($req['student_number'] . ' — ' . $req['full_name']) ?></h3>
                <p class="helper" style="margin:2px 0 0;">
                    <?= h($req['program_code']) ?> · Year <?= h((string)$req['year_level']) ?>
                    · <?= h($req['year_label']) ?> / <?= h(semester_label((string)$req['semester'])) ?>
                    <?php if ($requestType === 'add_drop'): ?>
                        · <?= $req['action_type'] === 'add' ? '➕ Add' : '➖ Drop' ?>: <?= h($req['subject_code'] ?: 'Subject #' . $req['subject_id']) ?>
                        · <?= h($req['subject_units']) ?> units
                    <?php else: ?>
                        · <strong><?= h(ucfirst($req['requested_status'])) ?></strong>
                        · <?= h($req['total_units']) ?> units · ₱<?= h(format_money($req['total_amount'])) ?>
                    <?php endif; ?>
                    · Submitted <?= h(date('M j, Y g:i A', strtotime($req['created_at']))) ?>
                </p>
            </div>
            <span class="badge <?= h(workflow_badge_class($ws)) ?>"><?= h(request_workflow_label($ws)) ?></span>
        </div>

        <?php if ($ws === 'chair_approved'): ?>
            <label style="display:inline-flex;align-items:center;gap:6px;padding:4px 8px;background:#f0fdf4;border-radius:6px;font-size:12px;font-weight:600;color:#0f5132;">
                <input type="checkbox" class="bulk-select" value="<?= h($req['id']) ?>"> Select for bulk finalize
            </label>
        <?php endif; ?>

        <div class="stepper">
            <?php foreach ($steps as $step): ?>
                <div class="step <?= h($step['state']) ?>">
                    <div class="step-circle">
                        <?php if ($step['state'] === 'done'): ?>
                            <span class="material-symbols-outlined" style="font-size:18px;">check</span>
                        <?php elseif ($step['state'] === 'rejected'): ?>
                            <span class="material-symbols-outlined" style="font-size:18px;">close</span>
                        <?php elseif ($step['state'] === 'blocked'): ?>
                            <span class="material-symbols-outlined" style="font-size:18px;">stop</span>
                        <?php elseif ($step['state'] === 'active'): ?>
                            <span class="material-symbols-outlined" style="font-size:18px;">pending</span>
                        <?php else: ?>
                            <span class="material-symbols-outlined" style="font-size:18px;">radio_button_unchecked</span>
                        <?php endif; ?>
                    </div>
                    <div class="step-label"><?= h($step['label']) ?></div>
                    <?php if ($step['remark'] !== ''): ?>
                        <div class="step-remark">"<?= h($step['remark']) ?>"</div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($ws === 'chair_approved'): ?>
            <div class="card slim" style="margin-top:12px;border-left:4px solid #6366f1;">
                <h4>Registrar Action</h4>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="finalize">
                    <input type="hidden" name="type" value="<?= h($requestType) ?>">
                    <input type="hidden" name="request_id" value="<?= h($req['id']) ?>">
                    <select name="section_id" required style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                        <option value="">Choose section…</option>
                        <?php foreach ($allSections as $sec): ?>
                            <option value="<?= h($sec['id']) ?>" <?= (int) ($req['requested_section_id'] ?? $req['section_id'] ?? 0) === (int) $sec['id'] ? 'selected' : '' ?>><?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn small" type="submit"><?= $requestType === 'enrollment' ? 'Finalize & Enroll' : 'Finalize Add/Drop' ?></button>
                </form>
                <form method="post" style="display:inline;margin-left:8px;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="reject">
                    <input type="hidden" name="type" value="<?= h($requestType) ?>">
                    <input type="hidden" name="request_id" value="<?= h($req['id']) ?>">
                    <input type="text" name="registrar_remark" placeholder="Rejection reason…" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:200px;">
                    <button class="btn small danger" type="submit">Reject</button>
                </form>
            </div>
        <?php endif; ?>

        <?php if ($requestType === 'enrollment'): ?>
            <?php $items = enrollment_request_items((int) $req['id']); ?>
            <details style="margin-top:8px;">
                <summary style="cursor:pointer;font-size:13px;color:var(--muted);font-weight:600;">
                    <?= count($items) ?> subject<?= count($items) !== 1 ? 's' : '' ?> requested
                </summary>
                <div class="table-wrap" style="margin-top:8px;">
                    <table>
                        <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th><th>Instructor</th></tr></thead>
                        <tbody>
                        <?php foreach ($items as $item): ?>
                            <tr>
                                <td><?= h($item['subject_code']) ?></td>
                                <td><?= h($item['subject_description']) ?></td>
                                <td><?= h($item['units']) ?></td>
                                <td><?= h($item['year_level'] . $item['section_name']) ?></td>
                                <td><?= h($item['instructor_name'] ?: '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </details>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
</div>

<style>
.step.blocked .step-circle{background:#f1f5f9;color:#94a3b8}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    var checks = document.querySelectorAll('.bulk-select');
    var countSpan = document.getElementById('bulkCount');
    var btn = document.getElementById('bulkSubmitBtn');
    if (!checks.length || !btn) return;

    function updateBulk() {
        var n = 0;
        checks.forEach(function(c) { if (c.checked) n++; });
        countSpan.textContent = n;
        btn.disabled = n === 0;
    }
    checks.forEach(function(c) { c.addEventListener('change', updateBulk); });
});
</script>
<?php
$enrollmentActivePage = $requestType === 'add_drop' ? 'Add/Drop Requests' : 'Enrollment Queue';
$enrollmentPageTitle = $requestType === 'add_drop' ? 'Add/Drop Requests' : 'Enrollment Queue';
render_page($enrollmentPageTitle, $enrollmentActivePage, (string) ob_get_clean());
