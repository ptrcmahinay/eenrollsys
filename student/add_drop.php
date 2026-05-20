<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
$currentTerm = current_term();

if ($student === null || $currentTerm === null) {
    flash('error', 'Student profile or active term is missing.');
    redirect('student/dashboard.php');
}

if (!student_is_irregular((int) $student['id'])) {
    flash('info', 'Add/Drop is only available for irregular students.');
    redirect('student/dashboard.php');
}

$enrolledSubjects = fetch_all(
    'SELECT ss.id AS enrollment_id, ss.subject_id, ss.offering_id, ss.section_id, ss.curriculum_id, ss.units,
            sub.subject_code, sub.subject_description, o.day_of_week, o.time_range, o.room,
            sec.section_name, CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
     FROM student_subjects ss
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     LEFT JOIN section_subject_offerings o ON o.id = ss.offering_id
     LEFT JOIN sections sec ON sec.id = ss.section_id
     LEFT JOIN staff st ON st.staff_id = o.instructor_id
     WHERE ss.student_id = :sid AND ss.term_id = :tid AND ss.enrollment_status = "enrolled"
     ORDER BY sub.subject_code',
    ['sid' => (int) $student['id'], 'tid' => (int) $currentTerm['id']]
);

$availableOfferings = fetch_all(
    'SELECT o.id, o.section_id, o.curriculum_id, o.subject_id, sub.subject_code, sub.subject_description, sub.units,
            o.day_of_week, o.time_range, o.room, sec.section_name,
            CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name,
            sec.max_slots,
            (SELECT COUNT(DISTINCT ss2.student_id) FROM student_subjects ss2 WHERE ss2.offering_id = o.id AND ss2.enrollment_status = "enrolled") AS enrolled_count
     FROM section_subject_offerings o
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     INNER JOIN sections sec ON sec.id = o.section_id
     LEFT JOIN staff st ON st.staff_id = o.instructor_id
     WHERE o.term_id = :tid
     ORDER BY sub.subject_code',
    ['tid' => (int) $currentTerm['id']]
);

$enrolledSubjectIds = [];
foreach ($enrolledSubjects as $es) {
    $enrolledSubjectIds[(int) $es['subject_id']] = true;
}

$addDropRequests = add_drop_request_items((int) $student['id'], (int) $currentTerm['id']);

$hasActiveRequest = false;
foreach ($addDropRequests as $adr) {
    if (in_array($adr['workflow_status'], ['submitted', 'adviser_approved', 'chair_approved'], true)) {
        $hasActiveRequest = true;
        break;
    }
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'submit_add') {
        if ($hasActiveRequest) {
            flash('error', 'You already have a pending add/drop request.');
            redirect('student/add_drop.php');
        }

        $offeringId = (int) ($_POST['offering_id'] ?? 0);
        $selected = null;
        foreach ($availableOfferings as $ao) {
            if ((int) $ao['id'] === $offeringId) {
                $selected = $ao;
                break;
            }
        }
        if ($selected === null) {
            flash('error', 'Invalid subject offering.');
            redirect('student/add_drop.php');
        }
        if (isset($enrolledSubjectIds[(int) $selected['subject_id']])) {
            flash('error', 'You are already enrolled in this subject.');
            redirect('student/add_drop.php');
        }
        if ((int) $selected['enrolled_count'] >= (int) ($selected['max_slots'] ?? 999)) {
            flash('error', 'This section is full.');
            redirect('student/add_drop.php');
        }

        $currentUnits = 0;
        foreach ($enrolledSubjects as $es) $currentUnits += (float) $es['units'];
        if ($currentUnits + (float) $selected['units'] > 27) {
            flash('error', 'Adding this subject would exceed the 27-unit limit.');
            redirect('student/add_drop.php');
        }

        create_add_drop_request((int) $student['id'], (int) $currentTerm['id'], 'add',
            (int) $selected['id'], (int) $selected['subject_id'], (int) $selected['section_id'],
            (int) $selected['curriculum_id'], (float) $selected['units']);
        flash('success', 'Add request submitted for ' . $selected['subject_code'] . '.');
        redirect('student/add_drop.php');
    }

    if ($action === 'submit_drop') {
        if ($hasActiveRequest) {
            flash('error', 'You already have a pending add/drop request.');
            redirect('student/add_drop.php');
        }

        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $selected = null;
        foreach ($enrolledSubjects as $es) {
            if ((int) $es['subject_id'] === $subjectId) {
                $selected = $es;
                break;
            }
        }
        if ($selected === null) {
            flash('error', 'Invalid subject.');
            redirect('student/add_drop.php');
        }

        create_add_drop_request((int) $student['id'], (int) $currentTerm['id'], 'drop',
            null, (int) $selected['subject_id'], (int) $selected['section_id'],
            (int) $selected['curriculum_id'], (float) $selected['units']);
        flash('success', 'Drop request submitted for ' . $selected['subject_code'] . '.');
        redirect('student/add_drop.php');
    }

    if ($action === 'cancel_add_drop') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $req = fetch_one('SELECT * FROM add_drop_requests WHERE id = :id AND student_id = :sid AND workflow_status = "submitted" LIMIT 1',
            ['id' => $requestId, 'sid' => (int) $student['id']]);
        if ($req !== null) {
            cancel_add_drop_request($requestId);
            flash('success', 'Add/drop request cancelled.');
        }
        redirect('student/add_drop.php');
    }
}

$sectionOptions = fetch_all(
    'SELECT id, program_id, year_level, section_name FROM sections
     INNER JOIN programs ON programs.programs_id = sections.program_id
     WHERE sections.program_id = :pid AND sections.year_level = :yl
     ORDER BY section_name',
    ['pid' => (int) $student['program_id'], 'yl' => (int) $student['year_level']]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Add / Drop Subjects</h1>
        <p>Request to add new subjects or drop existing ones. Each request goes through adviser → chair → registrar approval.</p>
    </div>
</div>

<?php if ($hasActiveRequest): ?>
    <div class="card" style="margin-top:16px;border-left:4px solid #f59e0b;">
        <h3>Active add/drop request pending</h3>
        <p class="helper">You already have a pending request. It must be processed before you can submit another.</p>
    </div>
<?php endif; ?>

<div class="wizard-stepbar" style="margin-top:16px;">
    <div class="wizard-step active" data-step="1">
        <div class="wizard-step-num">1</div>
        <div class="wizard-step-label">Current Subjects</div>
    </div>
    <div class="wizard-connector"></div>
    <div class="wizard-step" data-step="2">
        <div class="wizard-step-num">2</div>
        <div class="wizard-step-label">Add Subjects</div>
    </div>
    <div class="wizard-connector"></div>
    <div class="wizard-step" data-step="3">
        <div class="wizard-step-num">3</div>
        <div class="wizard-step-label">Request History</div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <!-- TAB 1: Current subjects with drop option -->
    <div class="wizard-panel" data-panel="1">
        <h3>Currently Enrolled Subjects</h3>
        <p class="helper">Click "Drop" to submit a drop request. The subject remains enrolled until the registrar approves.</p>

        <?php if (empty($enrolledSubjects)): ?>
            <p class="helper" style="padding:20px;text-align:center;">No enrolled subjects found for this term.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th><th>Schedule</th><th>Room</th><th>Instructor</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($enrolledSubjects as $subj): ?>
                        <tr>
                            <td><?= h($subj['subject_code']) ?></td>
                            <td><?= h($subj['subject_description']) ?></td>
                            <td><?= h($subj['units']) ?></td>
                            <td><?= h($subj['section_name'] ?: '-') ?></td>
                            <td><?= h(trim(($subj['day_of_week'] ?: 'TBA') . ' ' . ($subj['time_range'] ?: ''))) ?></td>
                            <td><?= h($subj['room'] ?: 'TBA') ?></td>
                            <td><?= h($subj['instructor_name']) ?></td>
                            <td>
                                <?php if (!$hasActiveRequest): ?>
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="submit_drop">
                                        <input type="hidden" name="subject_id" value="<?= h($subj['subject_id']) ?>">
                                        <button class="btn small danger" type="submit" onclick="return confirm('Submit drop request for <?= h(addslashes($subj['subject_code'])) ?>?')">Drop</button>
                                    </form>
                                <?php else: ?>
                                    <span class="helper">Disabled</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="text-align:right;font-weight:700;">Total Units:</td>
                            <td style="font-weight:700;">
                                <?php
                                $totalUnits = 0;
                                foreach ($enrolledSubjects as $es) $totalUnits += (float) $es['units'];
                                echo h((string) $totalUnits);
                                ?>
                            </td>
                            <td colspan="5"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        <?php endif; ?>

        <div class="wizard-nav">
            <div></div>
            <button type="button" class="btn" onclick="wizardGoTo(2)">Next: Add Subjects &rarr;</button>
        </div>
    </div>

    <!-- TAB 2: Available subjects to add -->
    <div class="wizard-panel" data-panel="2" style="display:none;">
        <h3>Add Subjects</h3>
        <p class="helper">Select an available section offering to request adding it to your enrollment.</p>

        <?php if (empty($availableOfferings)): ?>
            <p class="helper" style="padding:20px;text-align:center;">No subject offerings available.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th><th>Schedule</th><th>Room</th><th>Instructor</th><th>Slots</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach ($availableOfferings as $off): ?>
                        <?php
                        $isEnrolled = isset($enrolledSubjectIds[(int) $off['subject_id']]);
                        $isFull = (int) $off['enrolled_count'] >= (int) ($off['max_slots'] ?? 999);
                        $disabled = $hasActiveRequest || $isEnrolled || $isFull;
                        ?>
                        <tr>
                            <td><?= h($off['subject_code']) ?></td>
                            <td><?= h($off['subject_description']) ?></td>
                            <td><?= h($off['units']) ?></td>
                            <td><?= h($off['section_name'] ?: '-') ?></td>
                            <td><?= h(trim(($off['day_of_week'] ?: 'TBA') . ' ' . ($off['time_range'] ?: ''))) ?></td>
                            <td><?= h($off['room'] ?: 'TBA') ?></td>
                            <td><?= h($off['instructor_name']) ?></td>
                            <td>
                                <?php if ($off['max_slots']): ?>
                                    <span class="badge <?= ((int) $off['max_slots'] - (int) $off['enrolled_count']) > 5 ? 'success' : (((int) $off['max_slots'] - (int) $off['enrolled_count']) > 0 ? 'warning' : 'danger') ?>">
                                        <?= max(0, (int) $off['max_slots'] - (int) $off['enrolled_count']) ?> / <?= h($off['max_slots']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge info">Unlimited</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($isEnrolled): ?>
                                    <span class="helper">Already enrolled</span>
                                <?php elseif ($disabled): ?>
                                    <span class="helper">Disabled</span>
                                <?php else: ?>
                                    <form method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="submit_add">
                                        <input type="hidden" name="offering_id" value="<?= h($off['id']) ?>">
                                        <button class="btn small" type="submit">Add</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="wizard-nav">
            <button type="button" class="btn secondary" onclick="wizardGoTo(1)">&larr; Back</button>
            <button type="button" class="btn" onclick="wizardGoTo(3)">Next: Request History &rarr;</button>
        </div>
    </div>

    <!-- TAB 3: Request history -->
    <div class="wizard-panel" data-panel="3" style="display:none;">
        <h3>Add/Drop Request History</h3>
        <p class="helper">Track the approval progress of your add/drop requests.</p>

        <?php if (empty($addDropRequests)): ?>
            <p class="helper" style="padding:20px;text-align:center;">No add/drop requests yet.</p>
        <?php else: ?>
            <?php foreach ($addDropRequests as $adr): ?>
                <div class="card slim" style="margin-top:12px;border-left:4px solid <?= $adr['workflow_status'] === 'registrar_approved' ? '#0f5132' : ($adr['workflow_status'] === 'rejected' ? '#ef4444' : '#6366f1') ?>;">
                    <div style="display:flex;justify-content:space-between;align-items:center;">
                        <div>
                            <h4 style="margin:0;">
                                <?= $adr['action_type'] === 'add' ? '➕ Add' : '➖ Drop' ?>:
                                <?= h($adr['subject_code'] ?: 'Subject #' . $adr['subject_id']) ?>
                            </h4>
                            <p class="helper" style="margin:2px 0 0;">
                                <?= h($adr['subject_description'] ?: '') ?>
                                · <?= h($adr['units']) ?> units
                                · Submitted <?= h(date('M j, Y g:i A', strtotime($adr['created_at']))) ?>
                            </p>
                        </div>
                        <div style="text-align:right;">
                            <span class="badge <?= h(workflow_badge_class((string) $adr['workflow_status'])) ?>"><?= h(request_workflow_label((string) $adr['workflow_status'])) ?></span>
                            <?php if ($adr['workflow_status'] === 'submitted'): ?>
                                <form method="post" style="margin-top:6px;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_add_drop">
                                    <input type="hidden" name="request_id" value="<?= h($adr['id']) ?>">
                                    <button class="btn small danger" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php
                        $ws = $adr['workflow_status'];
                        $advRejected = $adr['adviser_status'] === 'rejected';
                        $chRejected = $adr['chair_status'] === 'rejected';
                        $regRejected = $adr['registrar_status'] === 'rejected';
                        $isFinal = $ws === 'registrar_approved';

                        $advState = $adr['adviser_status'] === 'approved' ? 'done' : ($advRejected ? 'rejected' : ($ws === 'submitted' ? 'active' : ''));
                        $chState  = $advRejected ? 'blocked' : ($adr['chair_status'] === 'approved' ? 'done' : ($chRejected ? 'rejected' : ($ws === 'adviser_approved' ? 'active' : '')));
                        $regState = ($advRejected || $chRejected) ? 'blocked' : ($adr['registrar_status'] === 'approved' ? 'done' : ($regRejected ? 'rejected' : ($ws === 'chair_approved' ? 'active' : '')));

                        $steps = [
                            ['label' => 'Submitted', 'state' => 'done', 'remark' => '', 'time' => $adr['created_at']],
                            ['label' => 'Adviser',   'state' => $advState, 'remark' => $adr['adviser_remark'] ?: '', 'time' => $adr['adviser_processed_at'] ?: ''],
                            ['label' => 'Chair',     'state' => $chState,  'remark' => $adr['chair_remark'] ?: '', 'time' => $adr['chair_processed_at'] ?: ''],
                            ['label' => 'Registrar', 'state' => $regState, 'remark' => $adr['registrar_remark'] ?: '', 'time' => $adr['registrar_processed_at'] ?: ''],
                            ['label' => 'Finalized', 'state' => $isFinal ? 'done' : ($advRejected || $chRejected || $regRejected ? 'blocked' : ''), 'remark' => '', 'time' => ''],
                        ];
                    ?>
                    <div class="stepper" style="margin-top:8px;">
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
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="wizard-nav">
            <button type="button" class="btn secondary" onclick="wizardGoTo(2)">&larr; Back</button>
            <div></div>
        </div>
    </div>
</div>

<style>
.wizard-stepbar{display:flex;align-items:center;justify-content:center;gap:0;padding:20px 16px;margin-bottom:0;background:var(--bg,#f8fafc);border-radius:12px}
.wizard-step{display:flex;flex-direction:column;align-items:center;gap:4px;min-width:120px}
.wizard-step-num{width:36px;height:36px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;background:var(--line,#e2e8f0);color:var(--muted,#64748b);transition:all .2s}
.wizard-step.active .wizard-step-num{background:#6366f1;color:#fff;box-shadow:0 0 0 4px rgba(99,102,241,.2)}
.wizard-step.done .wizard-step-num{background:#0f5132;color:#fff}
.wizard-step-label{font-size:12px;font-weight:600;color:var(--muted,#64748b);text-align:center}
.wizard-step.active .wizard-step-label{color:#6366f1}
.wizard-step.done .wizard-step-label{color:#0f5132}
.wizard-connector{width:40px;height:3px;background:var(--line,#e2e8f0);border-radius:2px;margin:0 4px;margin-bottom:20px;transition:background .2s}
.wizard-connector.done{background:#0f5132}
.wizard-nav{display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--line)}
.step-time{font-size:10px;color:var(--muted,#64748b);margin-top:2px;text-align:center}
.step.blocked .step-circle{background:#f1f5f9;color:#94a3b8}
</style>

<script>
function wizardGoTo(step) {
    document.querySelectorAll('.wizard-panel').forEach(function(p) { p.style.display = 'none'; });
    var panel = document.querySelector('.wizard-panel[data-panel="' + step + '"]');
    if (panel) panel.style.display = 'block';
    document.querySelectorAll('.wizard-step').forEach(function(s) {
        var sn = parseInt(s.getAttribute('data-step'));
        s.classList.remove('active', 'done');
        if (sn === step) s.classList.add('active');
        else if (sn < step) s.classList.add('done');
    });
    document.querySelectorAll('.wizard-connector').forEach(function(c, i) {
        c.classList.toggle('done', i + 1 < step);
    });
}
</script>
<?php
render_page('Add/Drop Subjects', 'Add/Drop Subjects', (string) ob_get_clean());
