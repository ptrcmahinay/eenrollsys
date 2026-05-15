<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$filterTerm = (int) ($_GET['term_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');

$sql = 'SELECT er.*, ay.year_label, t.semester
        FROM enrollment_requests er
        INNER JOIN academic_terms t ON t.id = er.term_id
        INNER JOIN academic_years ay ON ay.id = t.academic_year_id
        WHERE er.student_id = :student_id';
$params = ['student_id' => (int) $student['id']];
if ($filterTerm > 0) { $sql .= ' AND er.term_id = :tid'; $params['tid'] = $filterTerm; }
if ($filterStatus !== '') { $sql .= ' AND er.workflow_status = :ws'; $params['ws'] = $filterStatus; }
$sql .= ' ORDER BY er.created_at DESC';

$requests = fetch_all($sql, $params);

$terms = fetch_all(
    'SELECT DISTINCT t.id, ay.year_label, t.semester
     FROM enrollment_requests er
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE er.student_id = :sid
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid") DESC',
    ['sid' => (int) $student['id']]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Enrollment Status</h1>
        <p>Track adviser, department chair, and registrar approval progress for all requests.</p>
    </div>
</div>

<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select name="term_id" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <option value="0">All terms</option>
            <?php foreach ($terms as $t): ?>
                <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>>
                    <?= h($t['year_label'] . ' · ' . semester_label((string)$t['semester'])) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <select name="status" onchange="this.form.submit()" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <option value="">All statuses</option>
            <option value="draft" <?= $filterStatus === 'draft' ? 'selected' : '' ?>>Draft</option>
            <option value="registrar_approved" <?= $filterStatus === 'registrar_approved' ? 'selected' : '' ?>>Enrolled</option>
            <option value="chair_approved" <?= $filterStatus === 'chair_approved' ? 'selected' : '' ?>>Chair Approved</option>
            <option value="adviser_approved" <?= $filterStatus === 'adviser_approved' ? 'selected' : '' ?>>Adviser Approved</option>
            <option value="submitted" <?= $filterStatus === 'submitted' ? 'selected' : '' ?>>Submitted</option>
            <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>>Rejected</option>
            <option value="cancelled" <?= $filterStatus === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
        </select>
        <?php if ($filterTerm > 0 || $filterStatus !== ''): ?>
            <a class="btn small secondary" href="<?= h(app_url('student/enrollment_status.php')) ?>">Clear Filters</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($requests)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">inbox</span>
        <p class="helper">No enrollment requests found.</p>
        <a class="btn secondary" href="<?= h(app_url('student/enrollment.php')) ?>">Create Enrollment Request</a>
    </div>
<?php endif; ?>

<div class="grid" style="gap:16px;">
    <?php foreach ($requests as $request): ?>
        <?php $items = enrollment_request_items((int) $request['id']); ?>
        <div class="card">
            <div class="page-header" style="margin-bottom:10px;">
                <div>
                    <h3 style="margin:0;"><?= h($request['year_label']) ?> / <?= h(semester_label((string) $request['semester'])) ?></h3>
                    <p>Requested status: <?= h($request['requested_status']) ?> · Total units: <?= h($request['total_units']) ?> · Amount: ₱<?= h(format_money($request['total_amount'])) ?></p>
                    <p class="helper">Submitted <?= h(date('M j, Y g:i A', strtotime($request['created_at']))) ?></p>
                </div>
                <div style="text-align:right;">
                    <span class="badge <?= h(workflow_badge_class((string) $request['workflow_status'])) ?>"><?= h(request_workflow_label((string) $request['workflow_status'])) ?></span>
                    <?php if ($request['workflow_status'] === 'registrar_approved'): ?>
                        <br><a class="btn small secondary" href="<?= h(app_url('student/registration_form.php?request_id=' . $request['id'])) ?>" style="margin-top:6px;display:inline-block;">Download Registration Form</a>
                    <?php elseif (in_array($request['workflow_status'], ['rejected', 'cancelled'], true)): ?>
                        <br>
                        <form method="post" style="margin-top:6px;">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="resubmit_request">
                            <input type="hidden" name="source_request_id" value="<?= h($request['id']) ?>">
                            <button class="btn small" type="submit">Resubmit</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th></tr></thead>
                    <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><?= h($item['subject_code']) ?></td>
                            <td><?= h($item['subject_description']) ?></td>
                            <td><?= h($item['units']) ?></td>
                            <td><?= h($item['year_level'] . $item['section_name']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php
                $ws = $request['workflow_status'];
                $isFinal = $ws === 'registrar_approved';
                $advRejected = $request['adviser_status'] === 'rejected';
                $chRejected = $request['chair_status'] === 'rejected';
                $regRejected = $request['registrar_status'] === 'rejected';

                $advState = $request['adviser_status'] === 'approved' ? 'done' : ($advRejected ? 'rejected' : ($ws === 'submitted' ? 'active' : ''));
                $chState  = $advRejected ? 'blocked' : ($request['chair_status'] === 'approved' ? 'done' : ($chRejected ? 'rejected' : ($ws === 'adviser_approved' ? 'active' : '')));
                $regState = ($advRejected || $chRejected) ? 'blocked' : ($request['registrar_status'] === 'approved' ? 'done' : ($regRejected ? 'rejected' : ($ws === 'chair_approved' ? 'active' : '')));

                $steps = [
                    ['label' => 'Submitted',   'state' => 'done',     'remark' => '',                'time' => $request['created_at']],
                    ['label' => 'Adviser',     'state' => $advState,  'remark' => $request['adviser_remark']   ?: '', 'time' => $request['adviser_processed_at']   ?: ''],
                    ['label' => 'Dept. Chair', 'state' => $chState,   'remark' => $request['chair_remark']     ?: '', 'time' => $request['chair_processed_at']     ?: ''],
                    ['label' => 'Registrar',   'state' => $regState,  'remark' => $request['registrar_remark'] ?: '', 'time' => $request['registrar_processed_at'] ?: ''],
                    ['label' => 'Enrolled',    'state' => $isFinal ? 'done' : ($advRejected || $chRejected || $regRejected ? 'blocked' : ''), 'remark' => '', 'time' => ''],
                ];
            ?>
            <div class="stepper" style="margin-top:12px;">
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
                            <div class="step-remark">&ldquo;<?= h($step['remark']) ?>&rdquo;</div>
                        <?php endif; ?>
                        <?php if (!empty($step['time'])): ?>
                            <div class="step-time"><?= h(date('M j, g:i A', strtotime($step['time']))) ?></div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($request['total_units'] > 0): ?>
                <div class="card slim" style="margin-top:12px;">
                    <h4>Tuition Fee Breakdown</h4>
                    <?php
                        $financial = financial_profile($student, fetch_one('SELECT * FROM academic_terms WHERE id = :tid', ['tid' => (int) $request['term_id']]));
                        $otherFees = (float) setting('other_school_fees', '2500');
                        $total = (float) $request['total_amount'] + $otherFees;
                    ?>
                    <div class="kv-list">
                        <div class="item"><div class="k">Financial Status</div><div class="v"><?= h($financial['label']) ?></div></div>
                        <div class="item"><div class="k">Units</div><div class="v"><?= h($request['total_units']) ?></div></div>
                        <div class="item"><div class="k">Tuition per Unit</div><div class="v">₱<?= h(format_money($financial['tuition_per_unit'])) ?></div></div>
                        <div class="item"><div class="k">Tuition Fee</div><div class="v">₱<?= h(format_money($request['total_amount'])) ?></div></div>
                        <div class="item"><div class="k">Other Fees</div><div class="v">₱<?= h(format_money($otherFees)) ?></div></div>
                        <div class="item" style="border-top:2px solid var(--line);padding-top:8px;margin-top:4px;"><div class="k" style="font-weight:700;">Total</div><div class="v" style="font-weight:700;">₱<?= h(format_money($total)) ?></div></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<style>
.step-time{font-size:10px;color:var(--muted,#64748b);margin-top:2px;text-align:center}
.kv-list{list-style:none;padding:0;margin:0}
.kv-list .item{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line)}
.kv-list .item:last-child{border-bottom:none}
.kv-list .k{color:var(--muted,#64748b);font-size:13px;min-width:120px}
.kv-list .v{font-size:13px;font-weight:600;text-align:right}
.step.blocked .step-circle{background:#f1f5f9;color:#94a3b8}
</style>
<?php
render_page('Enrollment Status', 'Enrollment Status', (string) ob_get_clean());
