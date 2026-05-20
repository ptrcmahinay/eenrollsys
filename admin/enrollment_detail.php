<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$requestId = (int) ($_GET['id'] ?? 0);

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'finalize' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($requestId > 0 && $sectionId > 0) {
            $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
            if ($req !== null && request_deadline_passed($req, 'registrar')) {
                flash('error', 'The registrar finalization deadline has passed.');
            } elseif (finalize_request_by_registrar($requestId, $sectionId)) {
                flash('success', 'Student enrolled successfully.');
            } else {
                flash('error', 'Failed to enroll. Section may be full.');
            }
        }
        redirect('admin/enrollment_detail.php?id=' . $requestId);
    }

    if ($action === 'reject' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        $remark = trim($_POST['registrar_remark'] ?? '');
        if ($requestId > 0) {
            reject_request($requestId, 'registrar', $remark);
            flash('warning', 'Request rejected with registrar remark.');
        }
        redirect('admin/enrollment_detail.php?id=' . $requestId);
    }

    if ($action === 'unreject' && in_array($_SESSION['role'] ?? '', ['admin', 'registrar'], true)) {
        execute_sql(
            'UPDATE enrollment_requests SET workflow_status = "chair_approved", registrar_status = NULL, registrar_remark = NULL, registrar_processed_at = NULL, registrar_processed_by = NULL, updated_at = NOW() WHERE id = :id',
            ['id' => $requestId]
        );
        flash('success', 'Request reopened for registrar action.');
        redirect('admin/enrollment_detail.php?id=' . $requestId);
    }
}

if ($requestId <= 0) {
    flash('error', 'Invalid request.');
    redirect('admin/enrollment_dashboard.php');
}

$req = fetch_one(
    'SELECT er.*,
            s.student_number, s.full_name, s.year_level, s.id AS student_id,
            p.program_code, p.program_name,
            sec.section_name AS requested_section_name,
            rsec.section_name AS registrar_section_name,
            ay.year_label, t.semester
     FROM enrollment_requests er
     INNER JOIN students s    ON s.id          = er.student_id
     INNER JOIN programs p    ON p.programs_id = s.program_id
     INNER JOIN academic_terms t  ON t.id      = er.term_id
     INNER JOIN academic_years ay ON ay.id     = t.academic_year_id
     LEFT  JOIN sections sec  ON sec.id        = er.requested_section_id
     LEFT  JOIN sections rsec ON rsec.id       = er.registrar_section_id
     WHERE er.id = :id',
    ['id' => $requestId]
);

if ($req === null) {
    flash('error', 'Enrollment request not found.');
    redirect('admin/enrollment_dashboard.php');
}

$ws = $req['workflow_status'];
$isFinal = $ws === 'registrar_approved';
$advRejected = $req['adviser_status'] === 'rejected';
$chRejected = $req['chair_status'] === 'rejected';
$regRejected = $req['registrar_status'] === 'rejected';

$advState = $req['adviser_status'] === 'approved' ? 'done' : ($advRejected ? 'rejected' : ($ws === 'submitted' ? 'active' : ''));
$chState = $advRejected ? 'blocked' : ($req['chair_status'] === 'approved' ? 'done' : ($chRejected ? 'rejected' : ($ws === 'adviser_approved' ? 'active' : '')));
$regState = ($advRejected || $chRejected) ? 'blocked' : ($req['registrar_status'] === 'approved' ? 'done' : ($regRejected ? 'rejected' : ($ws === 'chair_approved' ? 'active' : '')));

$steps = [
    ['label' => 'Submitted', 'state' => 'done', 'remark' => ''],
    ['label' => 'Adviser', 'state' => $advState, 'remark' => $req['adviser_remark'] ?: ''],
    ['label' => 'Dept. Chair', 'state' => $chState, 'remark' => $req['chair_remark'] ?: ''],
    ['label' => 'Registrar', 'state' => $regState, 'remark' => $req['registrar_remark'] ?: ''],
    ['label' => 'Enrolled', 'state' => $isFinal ? 'done' : ($advRejected || $chRejected || $regRejected ? 'blocked' : ''), 'remark' => ''],
];

$items = enrollment_request_items($requestId);

$allSections = fetch_all(
    'SELECT id, program_code, year_level, section_name FROM sections
     INNER JOIN programs ON programs.programs_id = sections.program_id
     ORDER BY program_code, year_level, section_name'
);

$breadcrumbs = [
    ['label' => 'Enrollment Dashboard', 'url' => app_url('admin/enrollment_dashboard.php')],
    ['label' => $req['student_number'] . ' — ' . $req['full_name']],
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= h($req['student_number']) ?> — <?= h($req['full_name']) ?></h1>
        <p><?= h($req['program_code']) ?> · Year <?= h((string)$req['year_level']) ?> · <?= h($req['year_label']) ?> / <?= h(semester_label((string)$req['semester'])) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('admin/enrollment_dashboard.php')) ?>">&larr; Back to Dashboard</a>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:10px;margin-bottom:16px;">
        <div>
            <h3 style="margin:0;">Enrollment Status</h3>
            <p class="helper" style="margin:2px 0 0;">
                Submitted <?= h(date('M j, Y g:i A', strtotime($req['created_at']))) ?>
                · <?= h(ucfirst($req['requested_status'])) ?>
                · <?= h($req['total_units']) ?> units · ₱<?= h(format_money($req['total_amount'])) ?>
            </p>
        </div>
        <span class="badge <?= h(workflow_badge_class($ws)) ?>" style="font-size:14px;padding:6px 16px;"><?= h(request_workflow_label($ws)) ?></span>
    </div>

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
        <div class="card slim" style="margin-top:16px;border-left:4px solid #6366f1;">
            <h4 style="margin:0 0 10px;">Registrar Action Required</h4>
            <form method="post" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="finalize">
                <select name="section_id" required style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
                    <option value="">Choose section…</option>
                    <?php foreach ($allSections as $sec): ?>
                        <option value="<?= h($sec['id']) ?>" <?= (int)($req['requested_section_id'] ?? 0) === (int)$sec['id'] ? 'selected' : '' ?>>
                            <?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn" type="submit">Finalize &amp; Enroll</button>
            </form>
            <form method="post" style="display:inline-flex;gap:8px;align-items:center;margin-top:8px;flex-wrap:wrap;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reject">
                <input type="text" name="registrar_remark" placeholder="Rejection reason…" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:240px;">
                <button class="btn small danger" type="submit">Reject</button>
            </form>
        </div>
    <?php endif; ?>

    <?php if ($regRejected): ?>
        <div class="card slim" style="margin-top:12px;border-left:4px solid #f59e0b;">
            <p style="margin:0 0 8px;font-size:13px;">This request was rejected by the registrar. You can reopen it for a different decision.</p>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="unreject">
                <button class="btn small secondary" type="submit">Reopen for Registrar Action</button>
            </form>
        </div>
    <?php endif; ?>
</div>

<!-- Subjects -->
<div class="card">
    <h3 style="margin:0 0 10px;">Requested Subjects (<?= count($items) ?>)</h3>
    <?php if (empty($items)): ?>
        <p class="helper">No subjects loaded for this request.</p>
    <?php else: ?>
    <div class="table-wrap">
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
    <?php endif; ?>
</div>

<style>
.step.blocked .step-circle{background:#f1f5f9;color:#94a3b8}
</style>
<?php
render_page('Enrollment Detail — ' . $req['student_number'], 'Enrollment Dashboard', (string) ob_get_clean(), [
    'breadcrumbs' => $breadcrumbs,
]);
