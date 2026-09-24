<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$user = current_user();

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    $handlers = [
        'review' => 'tor_review',
        'clearance' => null,
        'generate' => null,
        'release' => null,
    ];

    if ($action === 'review' && $requestId > 0) {
        $reviewAction = trim($_POST['review_action'] ?? '');
        if (in_array($reviewAction, ['approve', 'reject'], true)) {
            tor_review($requestId, $reviewAction, (int) $user['users_id'], $remark);
            flash('success', 'Review submitted.');
        }
        redirect('registrar/tor.php');
    }

    if ($action === 'start_clearance' && $requestId > 0) {
        tor_advance_workflow($requestId, 'for_clearance');
        flash('success', 'Moved to clearance stage.');
        redirect('registrar/tor.php?view=' . $requestId);
    }

    if ($action === 'clearance' && $requestId > 0) {
        $clearanceType = trim($_POST['clearance_type'] ?? '');
        $cleared = isset($_POST['cleared']);
        tor_clearance($requestId, $clearanceType, $cleared);
        flash('success', 'Clearance updated.');
        redirect('registrar/tor.php?view=' . $requestId);
    }

    if ($action === 'generate' && $requestId > 0) {
        $docNumber = tor_generate_document($requestId, (int) $user['users_id']);
        flash('success', 'TOR generated. Document #: ' . $docNumber);
        redirect('registrar/tor.php?view=' . $requestId);
    }

    if ($action === 'release' && $requestId > 0) {
        tor_release($requestId, (int) $user['users_id']);
        flash('success', 'TOR released.');
        redirect('registrar/tor.php');
    }
}

$viewId = (int) ($_GET['view'] ?? 0);
$torRequests = fetch_all(
    'SELECT tor.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name
     FROM tor_requests tor
     INNER JOIN students s ON s.id = tor.student_id
     ORDER BY FIELD(tor.workflow_status, "pending","under_review","for_clearance","approved","processing","ready_for_release","released","rejected","cancelled") ASC, tor.created_at DESC'
);

$selectedRequest = null;
$academicRecords = null;
if ($viewId > 0) {
    $selectedRequest = fetch_one(
        'SELECT tor.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
                s.program_id, s.year_level
         FROM tor_requests tor
         INNER JOIN students s ON s.id = tor.student_id
         WHERE tor.id = :id',
        ['id' => $viewId]
    );
    if ($selectedRequest) {
        $academicRecords = get_tor_academic_records((int) $selectedRequest['student_id']);
    }
}

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>TOR Requests</h1><p>Process and generate Transcript of Records documents.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:16px;">
    <div class="card" style="max-height:80vh;overflow-y:auto;">
        <h3 style="margin:0 0 12px;">Requests (<?= count($torRequests) ?>)</h3>
        <?php if ($torRequests === []): ?>
        <p style="text-align:center;color:#94a3b8;padding:24px;">No TOR requests found.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($torRequests as $tr): ?>
            <a href="?view=<?= (int) $tr['id'] ?>" style="padding:10px;border:<?= $viewId === (int) $tr['id'] ? '2px solid var(--primary)' : '1px solid var(--line)' ?>;border-radius:8px;text-decoration:none;color:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div><strong style="font-size:13px;"><?= h($tr['student_number']) ?></strong><div style="font-size:11px;color:#64748b;"><?= h($tr['full_name']) ?></div></div>
                    <?php $bc = match($tr['workflow_status']) { 'released' => 'badge success', 'ready_for_release' => 'badge info', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge warning' }; ?>
                    <span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst(str_replace('_', ' ', $tr['workflow_status']))) ?></span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= h(ucfirst(str_replace('_', ' ', $tr['purpose']))) ?> &middot; <?= h(date('M j', strtotime($tr['created_at']))) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <div>
    <?php if ($selectedRequest): ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div>
                    <h3 style="margin:0 0 4px;"><?= h($selectedRequest['student_number']) ?> &mdash; <?= h($selectedRequest['full_name']) ?></h3>
                    <div style="font-size:13px;color:#64748b;">
                        Purpose: <strong><?= h(ucfirst(str_replace('_', ' ', $selectedRequest['purpose']))) ?></strong>
                        <?= $selectedRequest['purpose_detail'] ? ' — ' . h($selectedRequest['purpose_detail']) : '' ?>
                        <?php if ($selectedRequest['destination']): ?><br>Destination: <?= h($selectedRequest['destination']) ?><?php endif; ?>
                        <?php if ($selectedRequest['document_number']): ?><br>Document #: <strong><?= h($selectedRequest['document_number']) ?></strong><?php endif; ?>
                    </div>
                </div>
                <?php $bc = match($selectedRequest['workflow_status']) { 'released' => 'badge success', 'ready_for_release' => 'badge info', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge warning' }; ?>
                <span class="<?= $bc ?>"><?= h(ucfirst(str_replace('_', ' ', $selectedRequest['workflow_status']))) ?></span>
            </div>
        </div>

        <?php if ($selectedRequest['workflow_status'] !== 'pending'): ?>
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 8px;">Clearance Checklist</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="clearance">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <div style="display:grid;grid-template-columns:1fr auto;gap:8px;align-items:center;">
                    <?php
                    $clearanceMap = [
                        'academic'   => ['col' => 'academic_cleared',   'label' => 'Academic Records'],
                        'financial'  => ['col' => 'financial_cleared',  'label' => 'Financial Clearance'],
                        'library'    => ['col' => 'library_cleared',    'label' => 'Library Clearance'],
                        'registrar'  => ['col' => 'registrar_verified', 'label' => 'Registrar Verification'],
                    ];
                    foreach ($clearanceMap as $key => $info):
                        $cleared = !empty($selectedRequest[$info['col']]);
                    ?>
                    <span style="font-size:13px;"><?= h($info['label']) ?></span>
                    <label style="display:flex;align-items:center;gap:4px;font-size:12px;cursor:pointer;">
                        <input type="checkbox" name="cleared" value="1" <?= $cleared ? 'checked' : '' ?>
                            onchange="this.form.elements['clearance_type'].value='<?= $key ?>'; this.form.submit();">
                        <?= $cleared ? '<span style="color:#16a34a;">Cleared</span>' : '<span style="color:#f59e0b;">Pending</span>' ?>
                    </label>
                    <?php endforeach; ?>
                </div>
                <input type="hidden" name="clearance_type" value="">
            </form>
        </div>
        <?php endif; ?>

        <?php if ($academicRecords && $academicRecords['terms'] !== []): ?>
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 12px;">Academic Records</h3>
            <div style="font-size:13px;color:#64748b;margin-bottom:8px;">
                GWA: <strong><?= $academicRecords['gwa'] !== null ? h(number_format($academicRecords['gwa'], 2)) : 'N/A' ?></strong>
                &middot; Units Passed: <strong><?= (int) $academicRecords['passed_units'] ?></strong>
                &middot; Total Units: <strong><?= (int) $academicRecords['total_units'] ?></strong>
            </div>
            <?php foreach ($academicRecords['terms'] as $termData): ?>
            <div style="margin-bottom:12px;">
                <div style="font-size:13px;font-weight:600;margin-bottom:4px;"><?= h($termData['year_label'] . ' — ' . semester_label((string) $termData['semester'])) ?></div>
                <div class="table-wrap"><table style="font-size:12px;">
                    <thead><tr><th>Code</th><th>Subject</th><th>Units</th><th>Grade</th></tr></thead>
                    <tbody><?php foreach ($termData['subjects'] as $s): ?>
                        <tr>
                            <td><?= h($s['subject_code']) ?></td>
                            <td><?= h($s['subject_description']) ?></td>
                            <td><?= h(format_money($s['units'])) ?></td>
                            <td><strong><?= h($s['final_grade'] ?? '—') ?></strong></td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php if ($selectedRequest['workflow_status'] === 'pending'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="start_clearance">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <button class="btn" type="submit">Start Clearance Review</button>
            </form>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <input type="hidden" name="review_action" value="reject">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="remark" placeholder="Rejection reason" style="font-size:12px;">
                    <button class="btn danger" type="submit" onclick="return confirm('Reject this TOR request?');">Reject</button>
                </div>
            </form>
            <?php endif; ?>

            <?php if ($selectedRequest['workflow_status'] === 'for_clearance'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="review">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <input type="hidden" name="review_action" value="approve">
                <button class="btn" type="submit">Approve (All Clear)</button>
            </form>
            <?php endif; ?>

            <?php if ($selectedRequest['workflow_status'] === 'approved'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="generate">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <button class="btn" type="submit" onclick="return confirm('Generate TOR document?');">Generate TOR</button>
            </form>
            <?php endif; ?>

            <?php if ($selectedRequest['workflow_status'] === 'ready_for_release'): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="release">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <button class="btn" type="submit" onclick="return confirm('Mark as released?');">Release TOR</button>
            </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="card" style="text-align:center;padding:48px;"><p style="color:#94a3b8;">Select a request to view details.</p></div>
    <?php endif; ?>
    </div>
</div>
<?php
render_page('TOR Requests', 'TOR Requests', (string) ob_get_clean());
