<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'adviser', 'department_chair']);

$user = current_user();
$userRole = $_SESSION['role'] ?? '';

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');
    $requestId = (int) ($_POST['request_id'] ?? 0);
    $remark = trim($_POST['remark'] ?? '');

    $handlers = [
        'adviser_review' => ['roles' => ['admin', 'adviser'], 'fn' => 'shifting_adviser_review'],
        'current_chair_review' => ['roles' => ['admin', 'department_chair'], 'fn' => 'shifting_current_chair_review'],
        'target_chair_review' => ['roles' => ['admin', 'department_chair'], 'fn' => 'shifting_target_chair_review'],
        'registrar_review' => ['roles' => ['admin', 'registrar'], 'fn' => 'shifting_registrar_review'],
    ];

    if (isset($handlers[$action]) && in_array($userRole, $handlers[$action]['roles'], true)) {
        $reviewAction = trim($_POST['review_action'] ?? '');
        if (in_array($reviewAction, ['approve', 'reject'], true)) {
            $handlers[$action]['fn']($requestId, $reviewAction, $remark);
            flash('success', 'Review submitted.');
        }
        redirect('registrar/shifting.php');
    }

    if ($action === 'process_shift' && in_array($userRole, ['admin', 'registrar'], true)) {
        process_shifting($requestId, (int) $user['users_id']);
        flash('success', 'Shifting processed. Student program updated.');
        redirect('registrar/shifting.php');
    }

    if ($action === 'save_equivalencies' && in_array($userRole, ['admin', 'registrar'], true)) {
        save_subject_equivalencies($requestId, $_POST['equivalencies'] ?? []);
        flash('success', 'Subject equivalencies saved.');
        redirect('registrar/shifting.php?view=' . $requestId);
    }
}

$viewId = (int) ($_GET['view'] ?? 0);
$conditions = ['sr.workflow_status != "cancelled"'];
$params = [];

if ($userRole === 'adviser') {
    $conditions[] = 'sr.workflow_status = "submitted"';
} elseif ($userRole === 'department_chair') {
    $staff = fetch_one('SELECT dept_id FROM staff WHERE users_id = :uid', ['uid' => (int) $user['users_id']]);
    if ($staff && $staff['dept_id']) {
        $conditions[] = '(p_curr.department_id = :dept_id OR p_target.department_id = :dept_id2)';
        $params['dept_id'] = (int) $staff['dept_id'];
        $params['dept_id2'] = (int) $staff['dept_id'];
    }
}

$where = implode(' AND ', $conditions);
$shiftRequests = fetch_all(
    "SELECT sr.*, s.student_number, CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
            p_curr.program_code AS current_program_code, p_target.program_code AS target_program_code
     FROM shifting_requests sr
     INNER JOIN students s ON s.id = sr.student_id
     INNER JOIN programs p_curr ON p_curr.programs_id = sr.current_program_id
     INNER JOIN programs p_target ON p_target.programs_id = sr.target_program_id
     WHERE {$where}
     ORDER BY FIELD(sr.workflow_status, 'submitted','adviser_review','current_chair_review','target_chair_review','registrar_review','curriculum_evaluation','approved','processed') ASC, sr.created_at DESC",
    $params
);

$selectedRequest = null;
$evaluation = [];
if ($viewId > 0) {
    $selectedRequest = fetch_one(
        "SELECT sr.*, s.student_number, CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
                s.year_level AS student_year_level, s.entry_year,
                p_curr.program_code AS current_program_code, p_curr.program_name AS current_program_name, p_curr.department_id AS current_dept_id,
                p_target.program_code AS target_program_code, p_target.program_name AS target_program_name, p_target.department_id AS target_dept_id
         FROM shifting_requests sr
         INNER JOIN students s ON s.id = sr.student_id
         INNER JOIN programs p_curr ON p_curr.programs_id = sr.current_program_id
         INNER JOIN programs p_target ON p_target.programs_id = sr.target_program_id
         WHERE sr.id = :id",
        ['id' => $viewId]
    );
    if ($selectedRequest) $evaluation = evaluate_shifting_curriculum($viewId);
}

$flashes = get_flashes();
ob_start();
?>
<div class="page-header">
    <div><h1>Shifting Requests</h1><p>Review and process student program shifting requests.</p></div>
</div>
<?php if ($flashes !== []): ?>
    <div class="flash-stack"><?php foreach ($flashes as $flash): ?><div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div><?php endforeach; ?></div>
<?php endif; ?>
<div style="display:grid;grid-template-columns:300px 1fr;gap:16px;">
    <div class="card" style="max-height:80vh;overflow-y:auto;">
        <h3 style="margin:0 0 12px;">Requests (<?= count($shiftRequests) ?>)</h3>
        <?php if ($shiftRequests === []): ?>
        <p style="text-align:center;color:#94a3b8;padding:24px;">No shifting requests found.</p>
        <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:6px;">
            <?php foreach ($shiftRequests as $sr): ?>
            <a href="?view=<?= (int) $sr['id'] ?>" style="padding:10px;border:<?= $viewId === (int) $sr['id'] ? '2px solid var(--primary)' : '1px solid var(--line)' ?>;border-radius:8px;text-decoration:none;color:inherit;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <div><strong style="font-size:13px;"><?= h($sr['student_number']) ?></strong><div style="font-size:11px;color:#64748b;"><?= h($sr['full_name']) ?></div></div>
                    <?php $bc = match($sr['workflow_status']) { 'processed' => 'badge success', 'rejected' => 'badge danger', default => 'badge info' }; ?>
                    <span class="<?= $bc ?>" style="font-size:10px;"><?= h(ucfirst(str_replace('_', ' ', $sr['workflow_status']))) ?></span>
                </div>
                <div style="font-size:11px;color:#64748b;margin-top:4px;"><?= h($sr['current_program_code']) ?> &rarr; <?= h($sr['target_program_code']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    <div>
    <?php if ($selectedRequest):
        $ws = $selectedRequest['workflow_status'];
        $canAct = false;
        $reviewAction = '';
        if ($ws === 'submitted' && in_array($userRole, ['admin', 'adviser'], true)) { $canAct = true; $reviewAction = 'adviser_review'; }
        elseif ($ws === 'current_chair_review' && in_array($userRole, ['admin', 'department_chair'], true)) {
            $st = fetch_one('SELECT dept_id FROM staff WHERE users_id = :uid', ['uid' => (int) $user['users_id']]);
            if ($st && (int) $st['dept_id'] === (int) $selectedRequest['current_dept_id']) { $canAct = true; $reviewAction = 'current_chair_review'; }
        }
        elseif ($ws === 'target_chair_review' && in_array($userRole, ['admin', 'department_chair'], true)) {
            $st = fetch_one('SELECT dept_id FROM staff WHERE users_id = :uid', ['uid' => (int) $user['users_id']]);
            if ($st && (int) $st['dept_id'] === (int) $selectedRequest['target_dept_id']) { $canAct = true; $reviewAction = 'target_chair_review'; }
        }
        elseif ($ws === 'registrar_review' && in_array($userRole, ['admin', 'registrar'], true)) { $canAct = true; $reviewAction = 'registrar_review'; }
        elseif ($ws === 'curriculum_evaluation' && in_array($userRole, ['admin', 'registrar'], true)) { $canAct = true; $reviewAction = 'process_shift'; }
    ?>
        <div class="card" style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;">
                <div><h3 style="margin:0 0 4px;"><?= h($selectedRequest['student_number']) ?> &mdash; <?= h($selectedRequest['full_name']) ?></h3>
                <div style="font-size:13px;color:#64748b;">Year <?= (int) $selectedRequest['student_year_level'] ?> &middot; Entry: <?= h($selectedRequest['entry_year']) ?></div></div>
                <?php $bc = match($ws) { 'processed' => 'badge success', 'rejected' => 'badge danger', default => 'badge info' }; ?>
                <span class="<?= $bc ?>"><?= h(ucfirst(str_replace('_', ' ', $ws))) ?></span>
            </div>
        </div>
        <div class="card" style="margin-bottom:16px;">
            <div style="display:grid;grid-template-columns:1fr auto 1fr;gap:16px;align-items:center;">
                <div><div style="font-size:12px;color:var(--muted);">From</div><div style="font-weight:600;"><?= h($selectedRequest['current_program_code']) ?> &mdash; <?= h($selectedRequest['current_program_name']) ?></div><div style="font-size:12px;color:#64748b;">Year <?= (int) $selectedRequest['current_year_level'] ?></div></div>
                <div style="font-size:24px;color:var(--primary);">&rarr;</div>
                <div><div style="font-size:12px;color:var(--muted);">To</div><div style="font-weight:600;"><?= h($selectedRequest['target_program_code']) ?> &mdash; <?= h($selectedRequest['target_program_name']) ?></div>
                <?php if ($selectedRequest['target_year_level']): ?><div style="font-size:12px;color:#64748b;">Year <?= (int) $selectedRequest['target_year_level'] ?></div><?php endif; ?></div>
            </div>
            <div style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line);"><div style="font-size:12px;color:var(--muted);">Reason</div><div style="font-size:13px;margin-top:4px;"><?= nl2br(h($selectedRequest['reason'])) ?></div></div>
        </div>
        <?php
        $steps = [
            ['label' => 'Submitted', 'status' => 'done'],
            ['label' => 'Adviser', 'status' => match($selectedRequest['adviser_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'adviser_review' ? 'active' : '') }],
            ['label' => 'Current Chair', 'status' => match($selectedRequest['current_chair_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'current_chair_review' ? 'active' : '') }],
            ['label' => 'Target Chair', 'status' => match($selectedRequest['target_chair_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'target_chair_review' ? 'active' : '') }],
            ['label' => 'Registrar', 'status' => match($selectedRequest['registrar_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'registrar_review' ? 'active' : '') }],
            ['label' => 'Processed', 'status' => $ws === 'processed' ? 'done' : ($ws === 'rejected' ? 'blocked' : '')],
        ];
        ?>
        <div class="card" style="margin-bottom:16px;"><div class="stepper" style="display:flex;gap:0;">
            <?php foreach ($steps as $step): ?>
            <div class="step <?= h($step['status']) ?>" style="flex:1;text-align:center;">
                <div class="step-circle" style="width:32px;height:32px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;margin:0 auto 4px;background:<?= $step['status'] === 'done' ? '#16a34a' : ($step['status'] === 'rejected' ? '#dc2626' : ($step['status'] === 'active' ? '#2563eb' : '#e2e8f0')) ?>;color:<?= in_array($step['status'], ['done','rejected','active']) ? '#fff' : '#94a3b8' ?>;">
                    <?php if ($step['status'] === 'done'): ?><span class="material-symbols-outlined" style="font-size:18px;">check</span>
                    <?php elseif ($step['status'] === 'rejected'): ?><span class="material-symbols-outlined" style="font-size:18px;">close</span>
                    <?php elseif ($step['status'] === 'active'): ?><span class="material-symbols-outlined" style="font-size:18px;">pending</span>
                    <?php else: ?><span class="material-symbols-outlined" style="font-size:18px;">radio_button_unchecked</span><?php endif; ?>
                </div>
                <div style="font-size:11px;font-weight:500;"><?= h($step['label']) ?></div>
            </div>
            <?php endforeach; ?>
        </div></div>
        <?php if ($evaluation !== []): ?>
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 12px;">Curriculum Evaluation</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_equivalencies">
                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                <div class="table-wrap"><table>
                    <thead><tr><th>New Subject</th><th>Previous Match</th><th>Status</th><th>Units</th><th>Equivalence</th></tr></thead>
                    <tbody><?php foreach ($evaluation as $ev): ?>
                        <tr>
                            <td><strong><?= h($ev['subject_code']) ?></strong> <?= h($ev['subject_description']) ?></td>
                            <td><?= $ev['matched_subject'] ? h($ev['matched_subject']['subject_code'] . ' - ' . $ev['matched_subject']['subject_description']) : '<span style="color:#94a3b8;">-</span>' ?></td>
                            <td><?php $sc = match($ev['status']) { 'credited' => 'badge success', 'for_evaluation' => 'badge warning', default => 'badge' }; ?><span class="<?= $sc ?>"><?= h(ucfirst(str_replace('_', ' ', $ev['status']))) ?></span></td>
                            <td><?= h(format_money($ev['total_units'])) ?></td>
                            <td>
                                <input type="hidden" name="equivalencies[<?= (int) $ev['new_subject_id'] ?>][new_subject_id]" value="<?= (int) $ev['new_subject_id'] ?>">
                                <input type="hidden" name="equivalencies[<?= (int) $ev['new_subject_id'] ?>][old_subject_id]" value="<?= $ev['matched_subject'] ? (int) ($ev['matched_subject']['subject_id'] ?? 0) : 0 ?>">
                                <select name="equivalencies[<?= (int) $ev['new_subject_id'] ?>][equivalency_type]" style="font-size:12px;padding:4px 8px;">
                                    <option value="exact" <?= $ev['status'] === 'credited' ? 'selected' : '' ?>>Exact</option>
                                    <option value="equivalent" <?= $ev['status'] === 'for_evaluation' ? 'selected' : '' ?>>Equivalent</option>
                                    <option value="not_equivalent" <?= $ev['status'] === 'to_take' ? 'selected' : '' ?>>Not Equivalent</option>
                                </select>
                                <input type="hidden" name="equivalencies[<?= (int) $ev['new_subject_id'] ?>][credit_units]" value="<?= h(format_money($ev['total_units'])) ?>">
                                <input type="hidden" name="equivalencies[<?= (int) $ev['new_subject_id'] ?>][status]" value="<?= $ev['status'] === 'credited' ? 'approved' : 'pending' ?>">
                            </td>
                        </tr>
                    <?php endforeach; ?></tbody>
                </table></div>
                <?php if ($canAct): ?><div style="margin-top:12px;"><button class="btn" type="submit">Save Equivalencies</button></div><?php endif; ?>
            </form>
        </div>
        <?php endif; ?>
        <?php if ($canAct && $reviewAction !== 'process_shift'): ?>
        <div class="card" style="margin-bottom:16px;">
            <h3 style="margin:0 0 12px;">Review Action</h3>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="<?= h($reviewAction) ?>">
                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                <div style="margin-bottom:12px;"><label>Remarks (optional)</label><textarea name="remark" rows="3" style="width:100%;" placeholder="Add any remarks..."></textarea></div>
                <div style="display:flex;gap:8px;">
                    <button type="submit" name="review_action" value="approve" class="btn">Approve</button>
                    <button type="submit" name="review_action" value="reject" class="btn danger" onclick="return confirm('Reject this shifting request?');">Reject</button>
                </div>
            </form>
        </div>
        <?php elseif ($canAct && $reviewAction === 'process_shift'): ?>
        <div class="card" style="margin-bottom:16px;border-left:4px solid var(--success);">
            <h3 style="margin:0 0 8px;">Ready to Process</h3>
            <p style="font-size:13px;color:#64748b;margin-bottom:12px;">All reviews are approved. Processing will update the student's program and create program history.</p>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="process_shift">
                <input type="hidden" name="request_id" value="<?= (int) $selectedRequest['id'] ?>">
                <button class="btn" type="submit" onclick="return confirm('Process this shift? The student program will be changed.');">Process Shift</button>
            </form>
        </div>
        <?php elseif ($ws === 'rejected'): ?>
        <div class="card" style="border-left:4px solid #dc2626;">
            <p style="color:#dc2626;font-weight:600;">This request has been rejected.</p>
            <?php if ($selectedRequest['adviser_remark']): ?><p style="font-size:13px;"><strong>Adviser:</strong> <?= h($selectedRequest['adviser_remark']) ?></p><?php endif; ?>
            <?php if ($selectedRequest['current_chair_remark']): ?><p style="font-size:13px;"><strong>Current Chair:</strong> <?= h($selectedRequest['current_chair_remark']) ?></p><?php endif; ?>
            <?php if ($selectedRequest['target_chair_remark']): ?><p style="font-size:13px;"><strong>Target Chair:</strong> <?= h($selectedRequest['target_chair_remark']) ?></p><?php endif; ?>
            <?php if ($selectedRequest['registrar_remark']): ?><p style="font-size:13px;"><strong>Registrar:</strong> <?= h($selectedRequest['registrar_remark']) ?></p><?php endif; ?>
        </div>
        <?php elseif ($ws === 'processed'): ?>
        <div class="card" style="border-left:4px solid #16a34a;"><p style="color:#16a34a;font-weight:600;">This shift has been processed.</p></div>
        <?php endif; ?>
    <?php else: ?>
        <div class="card" style="text-align:center;padding:48px;"><p style="color:#94a3b8;">Select a request to view details.</p></div>
    <?php endif; ?>
    </div>
</div>
<style>.step.rejected .step-circle{background:#dc2626!important;color:#fff!important}.step.blocked .step-circle{background:#e2e8f0!important;color:#94a3b8!important}</style>
<?php
render_page('Shifting Requests', 'Shifting Requests', (string) ob_get_clean());
