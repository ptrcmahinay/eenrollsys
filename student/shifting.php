<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('student/dashboard.php');
}

$currentProgram = fetch_one(
    'SELECT p.programs_id, p.program_code, p.program_name, p.department_id
     FROM programs p WHERE p.programs_id = :pid',
    ['pid' => (int) $student['program_id']]
);

$activeProgramHistory = get_current_student_program((int) $student['id']);
$programHistory = get_student_program_history((int) $student['id']);

// Check for existing active shifting request
$activeShiftRequest = fetch_one(
    'SELECT * FROM shifting_requests WHERE student_id = :sid AND workflow_status NOT IN ("processed","rejected","cancelled")
     ORDER BY id DESC LIMIT 1',
    ['sid' => (int) $student['id']]
);

$programs = fetch_all(
    'SELECT programs_id, program_code, program_name FROM programs WHERE status = "active" ORDER BY program_code'
);

$flashes = get_flashes();

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'submit_shift_request') {
        if ($activeShiftRequest) {
            flash('error', 'You already have an active shifting request.');
            redirect('student/shifting.php');
        }

        $targetProgramId = (int) ($_POST['target_program_id'] ?? 0);
        $targetYearLevel = !empty($_POST['target_year_level']) ? (int) $_POST['target_year_level'] : null;
        $reason = trim((string) ($_POST['reason'] ?? ''));

        if ($targetProgramId <= 0) {
            flash('error', 'Please select a target program.');
            redirect('student/shifting.php');
        }
        if ($targetProgramId === (int) $student['program_id']) {
            flash('error', 'Target program must be different from your current program.');
            redirect('student/shifting.php');
        }
        if ($reason === '') {
            flash('error', 'Please provide a reason for shifting.');
            redirect('student/shifting.php');
        }

        $requestId = create_shifting_request(
            (int) $student['id'],
            (int) $student['program_id'],
            $targetProgramId,
            (int) $student['year_level'],
            $targetYearLevel,
            $reason
        );

        flash('success', 'Shifting request submitted successfully. Your adviser will review it shortly.');
        redirect('student/shifting.php');
    }

    if ($action === 'cancel_shift') {
        $shiftId = (int) ($_POST['shift_id'] ?? 0);
        if ($shiftId > 0) {
            execute_sql(
                'UPDATE shifting_requests SET workflow_status = "cancelled", updated_at = NOW() WHERE id = :id AND student_id = :sid AND workflow_status = "submitted"',
                ['id' => $shiftId, 'sid' => (int) $student['id']]
            );
            log_audit($shiftId, 'student_cancel', 'student', 'submitted', 'cancelled', null);
            flash('success', 'Shifting request cancelled.');
        }
        redirect('student/shifting.php');
    }
}

// Fetch all shifting requests for this student
$shiftRequests = fetch_all(
    'SELECT sr.*, 
            p_curr.program_code AS current_program_code, p_curr.program_name AS current_program_name,
            p_target.program_code AS target_program_code, p_target.program_name AS target_program_name
     FROM shifting_requests sr
     INNER JOIN programs p_curr ON p_curr.programs_id = sr.current_program_id
     INNER JOIN programs p_target ON p_target.programs_id = sr.target_program_id
     WHERE sr.student_id = :sid
     ORDER BY sr.created_at DESC',
    ['sid' => (int) $student['id']]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Program Shifting</h1>
        <p>Request to change your program. Your request will go through adviser, department chair, and registrar review.</p>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<!-- Current Program Info -->
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">Current Program</h3>
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
        <div>
            <div style="font-size:12px;color:var(--muted);">Program</div>
            <div style="font-size:14px;font-weight:600;"><?= h($currentProgram['program_code'] . ' — ' . $currentProgram['program_name']) ?></div>
        </div>
        <div>
            <div style="font-size:12px;color:var(--muted);">Year Level</div>
            <div style="font-size:14px;font-weight:600;">Year <?= (int) $student['year_level'] ?></div>
        </div>
        <div>
            <div style="font-size:12px;color:var(--muted);">Status</div>
            <div style="font-size:14px;font-weight:600;"><?= h(ucfirst($activeProgramHistory['status'] ?? 'Active')) ?></div>
        </div>
    </div>
</div>

<?php if ($programHistory !== []): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">Program History</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Program</th><th>Started</th><th>Ended</th><th>Status</th></tr>
            </thead>
            <tbody>
            <?php foreach ($programHistory as $ph): ?>
                <tr>
                    <td><strong><?= h($ph['program_code']) ?></strong> — <?= h($ph['program_name']) ?></td>
                    <td><?= h(($ph['start_year_label'] ?? '') . ' / ' . semester_label((string) ($ph['start_semester'] ?? ''))) ?></td>
                    <td><?= $ph['end_year_label'] ? h(($ph['end_year_label'] ?? '') . ' / ' . semester_label((string) ($ph['end_semester'] ?? ''))) : '<span style="color:var(--muted);">—</span>' ?></td>
                    <td>
                        <?php
                        $statusClass = match($ph['status']) {
                            'active' => 'badge success',
                            'shifted' => 'badge info',
                            default => 'badge',
                        };
                        ?>
                        <span class="<?= $statusClass ?>"><?= h(ucfirst($ph['status'])) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Active Shifting Request Status -->
<?php if ($activeShiftRequest): ?>
<div class="card" style="margin-bottom:16px;border-left:4px solid var(--warning);">
    <div style="display:flex;justify-content:space-between;align-items:flex-start;">
        <div>
            <h3 style="margin:0 0 8px;">Active Shifting Request</h3>
            <p style="font-size:13px;color:#64748b;">
                Shifting from <strong><?= h($activeShiftRequest['current_program_id'] ? fetch_one('SELECT program_code FROM programs WHERE programs_id = :id', ['id' => (int) $activeShiftRequest['current_program_id']])['program_code'] ?? '?' : '?') ?></strong>
                to <strong><?= h($activeShiftRequest['target_program_id'] ? fetch_one('SELECT program_code FROM programs WHERE programs_id = :id', ['id' => (int) $activeShiftRequest['target_program_id']])['program_code'] ?? '?' : '?') ?></strong>
            </p>
            <div style="font-size:12px;color:#64748b;margin-top:4px;">
                Submitted: <?= h(date('M j, Y g:i A', strtotime($activeShiftRequest['created_at']))) ?>
            </div>
        </div>
        <div>
            <?php
            $ws = $activeShiftRequest['workflow_status'];
            $badgeClass = match($ws) {
                'submitted' => 'badge',
                'adviser_review', 'current_chair_review', 'target_chair_review', 'registrar_review', 'curriculum_evaluation' => 'badge info',
                'approved', 'processed' => 'badge success',
                'rejected' => 'badge danger',
                'cancelled' => 'badge',
                default => 'badge',
            };
            ?>
            <span class="<?= $badgeClass ?>" style="font-size:12px;"><?= h(ucfirst(str_replace('_', ' ', $ws))) ?></span>
        </div>
    </div>

    <!-- Workflow Stepper -->
    <?php
    $steps = [
        ['label' => 'Submitted',       'status' => 'done', 'time' => $activeShiftRequest['created_at']],
        ['label' => 'Adviser Review',  'status' => match($activeShiftRequest['adviser_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'adviser_review' ? 'active' : '') }],
        ['label' => 'Current Chair',   'status' => match($activeShiftRequest['current_chair_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'current_chair_review' ? 'active' : '') }],
        ['label' => 'Target Chair',    'status' => match($activeShiftRequest['target_chair_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'target_chair_review' ? 'active' : '') }],
        ['label' => 'Registrar',       'status' => match($activeShiftRequest['registrar_status']) { 'approved' => 'done', 'rejected' => 'rejected', default => ($ws === 'registrar_review' ? 'active' : '') }],
        ['label' => 'Processed',       'status' => $ws === 'processed' ? 'done' : ($ws === 'rejected' ? 'blocked' : '')],
    ];
    ?>
    <div class="stepper" style="margin-top:16px;">
        <?php foreach ($steps as $step): ?>
            <div class="step <?= h($step['status']) ?>">
                <div class="step-circle">
                    <?php if ($step['status'] === 'done'): ?>
                        <span class="material-symbols-outlined" style="font-size:18px;">check</span>
                    <?php elseif ($step['status'] === 'rejected'): ?>
                        <span class="material-symbols-outlined" style="font-size:18px;">close</span>
                    <?php elseif ($step['status'] === 'active'): ?>
                        <span class="material-symbols-outlined" style="font-size:18px;">pending</span>
                    <?php else: ?>
                        <span class="material-symbols-outlined" style="font-size:18px;">radio_button_unchecked</span>
                    <?php endif; ?>
                </div>
                <div class="step-label"><?= h($step['label']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($ws === 'submitted'): ?>
    <div style="margin-top:12px;">
        <form method="post" style="display:inline;">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="cancel_shift">
            <input type="hidden" name="shift_id" value="<?= (int) $activeShiftRequest['id'] ?>">
            <button class="btn secondary" style="font-size:12px;" type="submit" onclick="return confirm('Cancel this shifting request?');">Cancel Request</button>
        </form>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- New Shifting Request Form -->
<?php if (!$activeShiftRequest): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;">Request to Shift</h3>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_shift_request">
        <div class="form-grid cols-2" style="gap:16px;">
            <div>
                <label>Current Program</label>
                <input type="text" value="<?= h($currentProgram['program_code'] . ' — ' . $currentProgram['program_name']) ?>" disabled style="width:100%;">
            </div>
            <div>
                <label>Target Program</label>
                <select name="target_program_id" required style="width:100%;">
                    <option value="">-- Select New Program --</option>
                    <?php foreach ($programs as $p): ?>
                        <?php if ((int) $p['programs_id'] !== (int) $student['program_id']): ?>
                        <option value="<?= (int) $p['programs_id'] ?>"><?= h($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Current Year Level</label>
                <input type="text" value="Year <?= (int) $student['year_level'] ?>" disabled style="width:100%;">
            </div>
            <div>
                <label>Proposed Year Level in New Program (optional)</label>
                <select name="target_year_level" style="width:100%;">
                    <option value="">Auto-determine later</option>
                    <?php for ($yl = 1; $yl <= 5; $yl++): ?>
                    <option value="<?= $yl ?>">Year <?= $yl ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div style="grid-column:1/-1;">
                <label>Reason for Shifting</label>
                <textarea name="reason" rows="4" required placeholder="Explain why you want to shift programs..." style="width:100%;"></textarea>
            </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;">
            <button class="btn" type="submit" onclick="return confirm('Submit this shifting request? It will be sent to your adviser for review.');">Submit Shifting Request</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- Past Shifting Requests -->
<?php if ($shiftRequests !== []): ?>
<div class="card">
    <h3 style="margin:0 0 12px;">Shifting Request History</h3>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Date</th><th>From</th><th>To</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($shiftRequests as $sr): ?>
                <tr>
                    <td><?= h(date('M j, Y', strtotime($sr['created_at']))) ?></td>
                    <td><?= h($sr['current_program_code']) ?></td>
                    <td><?= h($sr['target_program_code']) ?></td>
                    <td>
                        <?php
                        $badgeClass = match($sr['workflow_status']) {
                            'processed' => 'badge success',
                            'rejected' => 'badge danger',
                            'cancelled' => 'badge',
                            default => 'badge info',
                        };
                        ?>
                        <span class="<?= $badgeClass ?>"><?= h(ucfirst(str_replace('_', ' ', $sr['workflow_status']))) ?></span>
                    </td>
                    <td style="font-size:12px;color:#64748b;"><?= h($sr['reason'] ? mb_strimwidth($sr['reason'], 0, 60, '...') : '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<style>
.step-time{font-size:10px;color:var(--muted,#64748b);margin-top:2px;text-align:center}
.step.rejected .step-circle{background:#fee2e2;color:#dc2626}
.step.blocked .step-circle{background:#f1f5f9;color:#94a3b8}
</style>
<?php
render_page('Program Shifting', 'Shifting', (string) ob_get_clean());
