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

    if ($action === 'registrar_approve' && $requestId > 0) {
        loa_registrar_review($requestId, 'approve', (int) $user['users_id'], $remark);
        flash('success', 'LOA approved. Student status updated to on_leave.');
        redirect('registrar/loa.php?view=' . $requestId);
    }

    if ($action === 'registrar_reject' && $requestId > 0) {
        loa_registrar_review($requestId, 'reject', (int) $user['users_id'], $remark);
        flash('success', 'LOA rejected.');
        redirect('registrar/loa.php');
    }

    if ($action === 'process_return' && $requestId > 0) {
        loa_process_return($requestId, (int) $user['users_id']);
        flash('success', 'Return processed. Student status restored to active.');
        redirect('registrar/loa.php?view=' . $requestId);
    }
}

$viewId = (int) ($_GET['view'] ?? 0);
$loaRequests = fetch_all(
    'SELECT loa.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
            t.semester, ay.year_label,
            rt.semester AS return_semester, ray.year_label AS return_year_label
     FROM loa_requests loa
     INNER JOIN students s ON s.id = loa.student_id
     INNER JOIN academic_terms t ON t.id = loa.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     LEFT JOIN academic_terms rt ON rt.id = loa.expected_return_term_id
     LEFT JOIN academic_years ray ON ray.id = rt.academic_year_id
     ORDER BY FIELD(loa.workflow_status, "submitted","chair_review","registrar_review","approved","returned","rejected","cancelled") ASC, loa.created_at DESC'
);

$selectedRequest = null;
if ($viewId > 0) {
    $selectedRequest = fetch_one(
        'SELECT loa.*, s.student_number, CONCAT(s.first_name, " ", IFNULL(s.middle_name, ""), " ", s.last_name) AS full_name,
                s.program_id, s.year_level, s.academic_status,
                t.semester, ay.year_label,
                rt.semester AS return_semester, ray.year_label AS return_year_label
         FROM loa_requests loa
         INNER JOIN students s ON s.id = loa.student_id
         INNER JOIN academic_terms t ON t.id = loa.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         LEFT JOIN academic_terms rt ON rt.id = loa.expected_return_term_id
         LEFT JOIN academic_years ray ON ray.id = rt.academic_year_id
         WHERE loa.id = :id',
        ['id' => $viewId]
    );
}

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>LOA Requests</h1><p>Review and process Leave of Absence requests.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:300px 1fr;gap:16px;">
    <div class="card" style="max-height:80vh;overflow-y:auto;">
        <h3 style="margin:0 0 12px;">Requests (<?= count($loaRequests) ?>)</h3>
        <?php if ($loaRequests === []): ?>
        <p style="text-align:center;color:#94a3b8;padding:24px;">No LOA requests found.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($loaRequests as $lr): ?>
            <a href="?view=<?= (int) $lr['id'] ?>" style="padding:10px;border:<?= $viewId === (int) $lr['id'] ? '2px solid var(--primary)' : '1px solid var(--line)' ?>;border-radius:8px;text-decoration:none;color:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div><strong style="font-size:13px;"><?= h($lr['student_number']) ?></strong><div style="font-size:11px;color:#64748b;"><?= h($lr['full_name']) ?></div></div>
                    <?php $bc = match($lr['workflow_status']) { 'approved' => 'badge success', 'returned' => 'badge success', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge info' }; ?>
                    <span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst(str_replace('_', ' ', $lr['workflow_status']))) ?></span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= h(ucfirst($lr['reason_category'])) ?> &middot; <?= h($lr['year_label'] . ' — ' . semester_label((string) $lr['semester'])) ?></div>
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
                        Term: <strong><?= h($selectedRequest['year_label'] . ' — ' . semester_label((string) $selectedRequest['semester'])) ?></strong>
                        &middot; Reason: <strong><?= h(ucfirst($selectedRequest['reason_category'])) ?></strong>
                        <?php if ($selectedRequest['return_year_label']): ?><br>Expected Return: <?= h($selectedRequest['return_year_label'] . ' — ' . semester_label((string) $selectedRequest['return_semester'])) ?><?php endif; ?>
                        <?php if ($selectedRequest['academic_status']): ?><br>Current Status: <strong><?= h(ucfirst(str_replace('_', ' ', $selectedRequest['academic_status']))) ?></strong><?php endif; ?>
                    </div>
                </div>
                <?php $bc = match($selectedRequest['workflow_status']) { 'approved' => 'badge success', 'returned' => 'badge success', 'rejected' => 'badge danger', 'cancelled' => 'badge', default => 'badge info' }; ?>
                <span class="<?= $bc ?>"><?= h(ucfirst(str_replace('_', ' ', $selectedRequest['workflow_status']))) ?></span>
            </div>
            <?php if ($selectedRequest['reason_detail']): ?>
            <div style="margin-top:8px;font-size:13px;background:var(--bg-secondary,#f8fafc);padding:10px;border-radius:8px;">
                <strong>Reason Details:</strong><br><?= nl2br(h($selectedRequest['reason_detail'])) ?>
            </div>
            <?php endif; ?>
        </div>

        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 8px;">Approval Chain</h3>
            <div style="display:flex;flex-direction:column;gap:8px;">
                <?php
                $steps = [
                    ['label' => 'Adviser', 'status' => $selectedRequest['adviser_status'], 'remark' => $selectedRequest['adviser_remark']],
                    ['label' => 'Department Chair', 'status' => $selectedRequest['chair_status'], 'remark' => $selectedRequest['chair_remark']],
                    ['label' => 'Registrar', 'status' => $selectedRequest['registrar_status'], 'remark' => $selectedRequest['registrar_remark']],
                ];
                foreach ($steps as $step):
                    $icon = match($step['status']) { 'approved' => 'check_circle', 'rejected' => 'cancel', default => 'radio_button_unchecked' };
                    $color = match($step['status']) { 'approved' => '#16a34a', 'rejected' => '#dc2626', default => '#94a3b8' };
                ?>
                <div style="display:flex;align-items:center;gap:8px;">
                    <span class="material-symbols-outlined" style="font-size:20px;color:<?= $color ?>;"><?= $icon ?></span>
                    <span style="font-size:13px;"><?= h($step['label']) ?></span>
                    <span style="font-size:12px;color:#64748b;margin-left:auto;"><?= h(ucfirst($step['status'])) ?></span>
                    <?php if ($step['remark']): ?><span style="font-size:11px;color:#64748b;" title="<?= h($step['remark']) ?>">(remark)</span><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <?php if ($selectedRequest['workflow_status'] === 'registrar_review'): ?>
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 8px;">Registrar Decision</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <div style="margin-bottom:8px;"><textarea name="remark" rows="2" style="width:100%;" placeholder="Remarks (optional)..."></textarea></div>
                <div style="display:flex;gap:8px;">
                    <input type="hidden" name="action" value="registrar_approve">
                    <button class="btn success" type="submit" onclick="return confirm('Approve LOA? Student will be placed on leave.');">Approve LOA</button>
                </div>
            </form>
            <form method="post" style="margin-top:8px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="registrar_reject">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" name="remark" placeholder="Rejection reason" style="font-size:12px;flex:1;">
                    <button class="btn danger" type="submit" onclick="return confirm('Reject this LOA request?');">Reject</button>
                </div>
            </form>
        </div>
        <?php endif; ?>

        <?php if ($selectedRequest['workflow_status'] === 'approved'): ?>
        <div class="card" style="margin-bottom:16px;border-left:4px solid #16a34a;">
            <h3 style="margin:0 0 8px;">Process Return from Leave</h3>
            <p style="font-size:13px;color:#64748b;margin:0 0 8px;">This student is currently on Leave of Absence. Process their return when they are ready to resume studies.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="process_return">
                <input type="hidden" name="request_id" value="<?= $viewId ?>">
                <button class="btn success" type="submit" onclick="return confirm('Process return from LOA? This will restore active status and recalculate academic placement.');">Process Return</button>
            </form>
        </div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card" style="text-align:center;padding:48px;"><p style="color:#94a3b8;">Select a request to view details.</p></div>
    <?php endif; ?>
    </div>
</div>
<?php
render_page('LOA Requests', 'LOA Requests', (string) ob_get_clean());
