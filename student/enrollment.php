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

$latestRequest = fetch_one(
    'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id ORDER BY id DESC LIMIT 1',
    ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
);

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'cancel_request') {
        $requestId = (int) ($_POST['request_id'] ?? 0);
        $request = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id AND student_id = :student_id LIMIT 1', [
            'id' => $requestId,
            'student_id' => (int) $student['id'],
        ]);
        if ($request !== null && can_user_cancel_request($request)) {
            cancel_request($requestId);
            flash('success', 'Enrollment request cancelled. You can create a new request now.');
        } else {
            flash('error', 'This request can no longer be cancelled.');
        }
        redirect('student/enrollment.php');
    }

    if ($action === 'save_draft') {
        if (!enrollment_is_open((int) $student['year_level'])) {
            flash('error', 'Online enrollment is currently closed for your year level.');
            redirect('student/enrollment.php');
        }
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $requestedStatus = trim($_POST['requested_status'] ?? student_status_recommendation((int) $student['id']));
        $requestedStatus = $requestedStatus === 'irregular' ? 'irregular' : 'regular';

        $offeringIds = [];
        if ($requestedStatus === 'regular') {
            $regularOfferings = regular_offerings_for_student((int) $student['id'], (int) $currentTerm['id'], $sectionId);
            foreach ($regularOfferings as $offering) {
                $eligibility = prerequisite_status_for_curriculum((int) $student['id'], $offering);
                if ($eligibility['eligible']) {
                    $offeringIds[] = (int) $offering['id'];
                }
            }
        } else {
            $selected = $_POST['offering_ids'] ?? [];
            $allowed = irregular_offerings_for_student((int) $student['id'], (int) $currentTerm['id']);
            $eligibleMap = [];
            foreach ($allowed as $offering) {
                if ($offering['eligible']) {
                    $eligibleMap[(int) $offering['id']] = true;
                }
            }
            foreach ((array) $selected as $id) {
                $id = (int) $id;
                if (isset($eligibleMap[$id])) {
                    $offeringIds[] = $id;
                }
            }
        }

        $offeringIds = array_values(array_unique(array_filter($offeringIds)));
        if ($sectionId <= 0 || $offeringIds === []) {
            flash('error', 'Select a section and at least one valid subject offering.');
            redirect('student/enrollment.php');
        }

        $draft = fetch_one(
            'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id AND workflow_status = "draft" ORDER BY id DESC LIMIT 1',
            ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
        );
        if ($draft !== null) {
            execute_sql('DELETE FROM enrollment_request_items WHERE request_id = :rid', ['rid' => (int) $draft['id']]);
            execute_sql('DELETE FROM enrollment_requests WHERE id = :id', ['id' => (int) $draft['id']]);
        }

        $requestId = create_enrollment_request_draft((int) $student['id'], (int) $currentTerm['id'], $sectionId, $requestedStatus, $offeringIds);
        flash('success', 'Draft saved. You can review and submit later.');
        redirect('student/enrollment.php');
    }

    if ($action === 'submit_draft') {
        $draftId = (int) ($_POST['draft_id'] ?? 0);
        $draft = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id AND student_id = :student_id AND workflow_status = "draft" LIMIT 1', [
            'id' => $draftId,
            'student_id' => (int) $student['id'],
        ]);
        if ($draft === null) {
            flash('error', 'Draft not found.');
            redirect('student/enrollment.php');
        }

        $items = enrollment_request_items((int) $draft['id']);
        $offeringIds = [];
        foreach ($items as $it) {
            $offeringIds[] = (int) $it['offering_id'];
        }

        if (empty($offeringIds)) {
            flash('error', 'No subjects in this draft.');
            redirect('student/enrollment.php');
        }

        execute_sql(
            'UPDATE enrollment_requests SET workflow_status = "submitted", updated_at = NOW() WHERE id = :id',
            ['id' => $draftId]
        );
        flash('success', 'Enrollment request submitted successfully.');
        redirect('student/enrollment.php');
    }

    if ($action === 'edit_draft') {
        $draftId = (int) ($_POST['draft_id'] ?? 0);
        redirect('student/enrollment.php?edit_draft=' . $draftId);
    }

    if ($action === 'discard_draft') {
        $draftId = (int) ($_POST['draft_id'] ?? 0);
        $draft = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id AND student_id = :student_id AND workflow_status = "draft" LIMIT 1', [
            'id' => $draftId,
            'student_id' => (int) $student['id'],
        ]);
        if ($draft !== null) {
            execute_sql('DELETE FROM enrollment_request_items WHERE request_id = :rid', ['rid' => (int) $draft['id']]);
            execute_sql('DELETE FROM enrollment_requests WHERE id = :id', ['id' => (int) $draft['id']]);
            flash('success', 'Draft discarded.');
        }
        redirect('student/enrollment.php');
    }

    if ($action === 'resubmit_request') {
        $sourceId = (int) ($_POST['source_request_id'] ?? 0);
        $source = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id AND student_id = :student_id AND workflow_status IN ("rejected","cancelled") LIMIT 1', [
            'id' => $sourceId,
            'student_id' => (int) $student['id'],
        ]);
        if ($source !== null) {
            $_SESSION['resubmit_from'] = $sourceId;
            flash('info', 'Review your subjects below and modify before resubmitting.');
        }
        redirect('student/enrollment.php');
    }

    if ($action === 'submit_request') {
        if (!enrollment_is_open((int) $student['year_level'])) {
            flash('error', 'Online enrollment is currently closed for your year level.');
            redirect('student/enrollment.php');
        }

        $sectionId = (int) ($_POST['section_id'] ?? 0);
        $requestedStatus = trim($_POST['requested_status'] ?? student_status_recommendation((int) $student['id']));
        $requestedStatus = $requestedStatus === 'irregular' ? 'irregular' : 'regular';
        $latestRequest = fetch_one(
            'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id ORDER BY id DESC LIMIT 1',
            ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
        );
        if ($latestRequest !== null && in_array($latestRequest['workflow_status'], ['submitted', 'adviser_approved', 'chair_approved', 'registrar_approved'], true)) {
            flash('error', 'You already have an active request for this term.');
            redirect('student/enrollment.php');
        }

        $offeringIds = [];
        if ($requestedStatus === 'regular') {
            $regularOfferings = regular_offerings_for_student((int) $student['id'], (int) $currentTerm['id'], $sectionId);
            foreach ($regularOfferings as $offering) {
                $eligibility = prerequisite_status_for_curriculum((int) $student['id'], $offering);
                if ($eligibility['eligible']) {
                    $offeringIds[] = (int) $offering['id'];
                }
            }
        } else {
            $selected = $_POST['offering_ids'] ?? [];
            $allowed = irregular_offerings_for_student((int) $student['id'], (int) $currentTerm['id']);
            $eligibleMap = [];
            foreach ($allowed as $offering) {
                if ($offering['eligible']) {
                    $eligibleMap[(int) $offering['id']] = true;
                }
            }
            foreach ((array) $selected as $id) {
                $id = (int) $id;
                if (isset($eligibleMap[$id])) {
                    $offeringIds[] = $id;
                }
            }
        }

        $offeringIds = array_values(array_unique(array_filter($offeringIds)));
        if ($sectionId <= 0 || $offeringIds === []) {
            flash('error', 'Select a section and at least one valid subject offering.');
            redirect('student/enrollment.php');
        }

        if (!section_has_slot($sectionId, (int) $currentTerm['id'])) {
            flash('error', 'The selected section is already full. Please choose another section.');
            redirect('student/enrollment.php');
        }

        if ($requestedStatus === 'irregular' && count($offeringIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($offeringIds), '?'));
            $stmt = db()->prepare("SELECT COALESCE(SUM(sub.units), 0) AS total FROM section_subject_offerings o INNER JOIN subjects sub ON sub.subject_id = o.subject_id WHERE o.id IN ($placeholders)");
            $stmt->execute($offeringIds);
            $selectedUnits = (float) $stmt->fetchColumn();
            if ($selectedUnits > 27) {
                flash('error', 'Irregular students may not enroll in more than 27 units. You selected ' . $selectedUnits . ' units.');
                redirect('student/enrollment.php');
            }
        }

        create_enrollment_request((int) $student['id'], (int) $currentTerm['id'], $sectionId, $requestedStatus, $offeringIds);
        flash('success', 'Enrollment request submitted successfully.');
        redirect('student/enrollment.php');
    }
}

$resubmitFrom = isset($_SESSION['resubmit_from']) ? (int) $_SESSION['resubmit_from'] : 0;
$resubmitSource = null;
if ($resubmitFrom > 0) {
    $resubmitSource = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id AND student_id = :student_id LIMIT 1', [
        'id' => $resubmitFrom,
        'student_id' => (int) $student['id'],
    ]);
    if ($resubmitSource) {
        unset($_SESSION['resubmit_from']);
    } else {
        $resubmitFrom = 0;
    }
}

$sections = fetch_all(
    'SELECT id, year_level, section_name FROM sections WHERE program_id = :program_id AND year_level = :year_level ORDER BY section_name',
    ['program_id' => (int) $student['program_id'], 'year_level' => (int) $student['year_level']]
);
$selectedSectionId = (int) ($_GET['section_id'] ?? ($student['section_id'] ?: ($sections[0]['id'] ?? 0)));
$recommendedStatus = student_status_recommendation((int) $student['id']);
$slotsUsed     = section_enrollment_count($selectedSectionId, (int) $currentTerm['id']);
$slotsCapacity = $selectedSectionId > 0 ? section_capacity($selectedSectionId) : 0;
$slotsLeft     = max(0, $slotsCapacity - $slotsUsed);
$regularPreview = $selectedSectionId > 0 ? regular_offerings_for_student((int) $student['id'], (int) $currentTerm['id'], $selectedSectionId) : [];
$irregularSuggestions = irregular_offerings_for_student((int) $student['id'], (int) $currentTerm['id']);

$resubmitOfferingIds = [];
if ($resubmitSource) {
    $items = enrollment_request_items((int) $resubmitSource['id']);
    foreach ($items as $it) {
        $resubmitOfferingIds[(int) $it['offering_id']] = true;
    }
}

$financial = financial_profile($student, $currentTerm);
$otherFees = (float) setting('other_school_fees', '2500');

$draftRequest = fetch_one(
    'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id AND workflow_status = "draft" ORDER BY id DESC LIMIT 1',
    ['student_id' => (int) $student['id'], 'term_id' => (int) $currentTerm['id']]
);
$draftItems = $draftRequest ? enrollment_request_items((int) $draftRequest['id']) : [];

$resubmitParam = $resubmitSource !== null;
$editDraftId = (int) ($_GET['edit_draft'] ?? 0);
$editingDraft = null;
if ($editDraftId > 0 && $draftRequest !== null && (int) $draftRequest['id'] === $editDraftId) {
    $editingDraft = $draftRequest;
    $draftItems = enrollment_request_items((int) $draftRequest['id']);
}

$resubmitItems = [];
if ($resubmitSource) {
    $resubmitItems = enrollment_request_items((int) $resubmitSource['id']);
}

$showWizard = ($editingDraft !== null) || $resubmitParam || ($draftRequest === null && !$latestRequest) ||
    ($latestRequest !== null && in_array($latestRequest['workflow_status'], ['rejected', 'cancelled'], true) && isset($_GET['new']));

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Online Enrollment</h1>
        <p>Follow the steps below to submit your enrollment request. Complete each step before proceeding.</p>
    </div>
</div>

<?php if ($latestRequest !== null && in_array($latestRequest['workflow_status'], ['submitted', 'adviser_approved', 'chair_approved', 'registrar_approved'], true)): ?>
    <div class="card" style="margin-top:16px;">
        <h3>Active request already exists</h3>
        <p class="helper">Wait for the current request to finish, or cancel it first if cancellation is still allowed.</p>
        <p><strong>Latest request:</strong> <span class="badge <?= h(workflow_badge_class((string) $latestRequest['workflow_status'])) ?>"><?= h(request_workflow_label((string) $latestRequest['workflow_status'])) ?></span></p>
        <p class="helper">Requested status: <?= h($latestRequest['requested_status']) ?> Â· Total units: <?= h($latestRequest['total_units']) ?> Â· Amount: â‚±<?= h(format_money($latestRequest['total_amount'])) ?></p>
        <?php if (can_user_cancel_request($latestRequest)): ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="cancel_request">
                <input type="hidden" name="request_id" value="<?= h($latestRequest['id']) ?>">
                <button class="btn danger" type="submit">Cancel Request</button>
            </form>
        <?php endif; ?>
    </div>
<?php elseif ($resubmitParam || $editingDraft || (isset($_GET["new"]) && ($latestRequest === null || in_array($latestRequest["workflow_status"], ["rejected", "cancelled"], true)))): ?>

<div class="card" style="margin-top:16px;">
    <?php if ($editingDraft): ?>
    <div style="margin-bottom:12px;">
        <span class="badge warning">Editing Draft</span>
        <span class="helper" style="margin-left:8px;">Modify your draft and submit when ready.</span>
    </div>
    <?php endif; ?>
    <?php if ($resubmitParam): ?>
    <div style="margin-bottom:12px;">
        <span class="badge info">Resubmitting</span>
        <span class="helper" style="margin-left:8px;">Your previous subjects are pre-selected. Review and modify before submitting.</span>
    </div>
    <?php endif; ?>
    <div class="wizard-stepbar">
        <div class="wizard-step active" data-step="1">
            <div class="wizard-step-num">1</div>
            <div class="wizard-step-label">Student Info &amp; Section</div>
        </div>
        <div class="wizard-connector"></div>
        <div class="wizard-step" data-step="2">
            <div class="wizard-step-num">2</div>
            <div class="wizard-step-label">Select Subjects</div>
        </div>
        <div class="wizard-connector"></div>
        <div class="wizard-step" data-step="3">
            <div class="wizard-step-num">3</div>
            <div class="wizard-step-label">Review &amp; Submit</div>
        </div>
    </div>

    <form method="post" id="enrollmentForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="submit_request">

        <div class="wizard-panel" data-panel="1">
            <h3>Step 1: Student Information &amp; Section Selection</h3>
            <p class="helper">Verify your details and choose your section for this term.</p>

            <div class="form-grid cols-2" style="margin-top:16px;">
                <div class="card slim">
                    <h4>Your Information</h4>
                    <div class="kv-list">
                        <div class="item"><div class="k">Student Number</div><div class="v"><?= h($student['student_number']) ?></div></div>
                        <div class="item"><div class="k">Full Name</div><div class="v"><?= h($student['full_name']) ?></div></div>
                        <div class="item"><div class="k">Program</div><div class="v"><?= h($student['program_code']) ?></div></div>
                        <div class="item"><div class="k">Year Level</div><div class="v"><?= h($student['year_level']) ?></div></div>
                        <div class="item"><div class="k">Recommended Status</div><div class="v"><span class="badge success"><?= h(ucfirst($recommendedStatus)) ?></span></div></div>
                    </div>
                </div>
                <div class="card slim">
                    <h4>Enrollment Details</h4>
                    <div class="kv-list">
                        <div class="item"><div class="k">Term</div><div class="v"><?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></div></div>
                        <div class="item">
                            <div class="k">Section</div>
                            <div class="v">
                                <select name="section_id" id="sectionSelect" required style="width:100%;">
                                    <?php foreach ($sections as $section): ?>
                                        <option value="<?= h($section['id']) ?>" <?= $selectedSectionId === (int) $section['id'] ? 'selected' : '' ?>><?= h($student['program_code'] . ' ' . $section['year_level'] . '-' . $section['section_name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="item">
                            <div class="k">Student Status</div>
                            <div class="v">
                                <select name="requested_status" id="statusSelect" style="width:100%;">
                                    <option value="regular" <?= $recommendedStatus === 'regular' ? 'selected' : '' ?>>Regular</option>
                                    <option value="irregular" <?= $recommendedStatus === 'irregular' ? 'selected' : '' ?>>Irregular</option>
                                </select>
                            </div>
                        </div>
                        <div class="item">
                            <div class="k">Available Slots</div>
                            <div class="v">
                                <span id="slotsBadge" class="badge <?= $slotsLeft > 5 ? 'success' : ($slotsLeft > 0 ? 'warning' : 'danger') ?>">
                                    <?= $slotsLeft ?> / <?= $slotsCapacity ?> slots remaining
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="wizard-nav">
                <div></div>
                <button type="button" class="btn" onclick="wizardNext(2)">Next: Select Subjects &rarr;</button>
            </div>
        </div>

        <div class="wizard-panel" data-panel="2" style="display:none;">
            <h3>Step 2: Subject Selection</h3>
            <p class="helper" id="subjectInstruction">Regular students receive all eligible subjects automatically. Switch to irregular to pick individual subjects.</p>

            <div id="regularPanel">
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Schedule</th><th>Prerequisite Check</th></tr></thead>
                        <tbody id="regularTableBody">
                        <?php foreach ($regularPreview as $row): ?>
                            <?php $eligibility = prerequisite_status_for_curriculum((int) $student['id'], $row); ?>
                            <tr>
                                <td><?= h($row['subject_code']) ?></td>
                                <td><?= h($row['subject_description']) ?></td>
                                <td class="reg-unit"><?= h($row['units']) ?></td>
                                <td><?= h(trim(($row['day_of_week'] ?: 'TBA') . ' ' . ($row['time_range'] ?: ''))) ?></td>
                                <td><span class="badge <?= $eligibility['eligible'] ? 'success' : 'danger' ?>"><?= $eligibility['eligible'] ? 'Eligible' : h($eligibility['reason']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="text-align:right;font-weight:700;">Total Units:</td>
                                <td id="regularTotalUnits" style="font-weight:700;">
                                    <?php
                                    $regTotal = 0;
                                    foreach ($regularPreview as $r) {
                                        if (prerequisite_status_for_curriculum((int) $student['id'], $r)['eligible']) $regTotal += (float) $r['units'];
                                    }
                                    echo h((string) $regTotal);
                                    ?>
                                </td>
                                <td colspan="2"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            <div id="irregularPanel" style="display:none;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
                    <span class="badge info" id="unitCounter">0 units selected / 27 max</span>
                    <span class="badge" id="subjectCounter">0 subjects selected</span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Select</th><th>Section</th><th>Code</th><th>Description</th><th>Units</th><th>Eligibility</th></tr></thead>
                        <tbody>
                        <?php foreach ($irregularSuggestions as $row): ?>
                            <?php $checked = isset($resubmitOfferingIds[(int) $row['id']]) ? 'checked' : ''; ?>
                            <tr>
                                <td>
                                    <?php if ($row['eligible']): ?>
                                        <input type="checkbox" name="offering_ids[]" value="<?= h($row['id']) ?>" class="irr-check" data-units="<?= h($row['units']) ?>" <?= $checked ?>>
                                    <?php else: ?>
                                        <span class="helper">Blocked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['year_level'] . '-' . $row['section_name']) ?></td>
                                <td><?= h($row['subject_code']) ?></td>
                                <td><?= h($row['subject_description']) ?></td>
                                <td class="irr-unit"><?= h($row['units']) ?></td>
                                <td><span class="badge <?= $row['eligible'] ? 'success' : 'danger' ?>"><?= $row['eligible'] ? 'Eligible' : h($row['eligibility_reason']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn secondary" onclick="wizardPrev(1)">&larr; Back</button>
                <button type="button" class="btn" onclick="wizardNext(3)">Next: Review &amp; Submit &rarr;</button>
            </div>
        </div>

        <div class="wizard-panel" data-panel="3" style="display:none;">
            <h3>Step 3: Review &amp; Confirm</h3>
            <p class="helper">Review your enrollment details below. You cannot edit once submitted.</p>

            <div class="grid cols-2" style="margin-top:16px;">
                <div class="card slim">
                    <h4>Enrollment Summary</h4>
                    <div class="kv-list">
                        <div class="item"><div class="k">Term</div><div class="v"><?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></div></div>
                        <div class="item"><div class="k">Section</div><div class="v" id="reviewSection"></div></div>
                        <div class="item"><div class="k">Status</div><div class="v" id="reviewStatus"></div></div>
                        <div class="item"><div class="k">Subjects</div><div class="v" id="reviewCount"></div></div>
                        <div class="item"><div class="k">Total Units</div><div class="v" id="reviewUnits"></div></div>
                    </div>
                </div>
                <div class="card slim">
                    <h4>Tuition Fee Breakdown</h4>
                    <div class="kv-list">
                        <div class="item"><div class="k">Financial Status</div><div class="v"><?= h($financial['label']) ?></div></div>
                        <div class="item"><div class="k">Tuition per Unit</div><div class="v">â‚±<?= h(format_money($financial['tuition_per_unit'])) ?></div></div>
                        <div class="item"><div class="k">Tuition Fee</div><div class="v" id="reviewTuition">â‚±0.00</div></div>
                        <div class="item"><div class="k">Other School Fees</div><div class="v">â‚±<?= h(format_money($otherFees)) ?></div></div>
                        <div class="item" style="border-top:2px solid var(--line);padding-top:8px;margin-top:4px;"><div class="k" style="font-weight:700;">Total Amount Due</div><div class="v" id="reviewTotal" style="font-weight:700;font-size:18px;">â‚±0.00</div></div>
                    </div>
                </div>
            </div>

            <div class="card slim" style="margin-top:12px;">
                <h4>Subjects Enrolled</h4>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Schedule</th></tr></thead>
                        <tbody id="reviewSubjects"></tbody>
                    </table>
                </div>
            </div>

            <div class="wizard-nav">
                <button type="button" class="btn secondary" onclick="wizardPrev(2)">&larr; Back</button>
                <div style="display:flex;gap:8px;">
                    <button type="button" class="btn secondary" onclick="saveDraft()">Save as Draft</button>
                    <button type="button" class="btn" id="finalSubmitBtn" onclick="showConfirmModal()">Confirm &amp; Submit Enrollment</button>
                </div>
            </div>
        </div>
    </form>
    <form method="post" id="draftForm" style="display:none;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_draft">
        <input type="hidden" name="section_id" id="draft_section_id">
        <input type="hidden" name="requested_status" id="draft_requested_status">
        <div id="draft_offering_ids"></div>
    </form>
</div>

<div id="confirm-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;">
    <div style="background:var(--panel,#fff);border-radius:12px;padding:28px 32px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);">
        <h3 style="margin:0 0 8px;">Confirm Enrollment Request</h3>
        <p style="color:var(--muted);font-size:14px;margin-bottom:16px;">
            Please confirm that all details are correct. You <strong>cannot edit</strong> once submitted.
        </p>
        <ul style="font-size:14px;line-height:1.8;padding-left:18px;">
            <li><strong>Term:</strong> <?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></li>
            <li><strong>Section:</strong> <span id="modal-section"></span></li>
            <li><strong>Status:</strong> <span id="modal-status"></span></li>
            <li><strong>Subjects:</strong> <span id="modal-subject-count"></span></li>
        </ul>
        <div style="display:flex;gap:10px;margin-top:20px;justify-content:flex-end;">
            <button class="btn secondary" type="button" onclick="hideConfirmModal()">Cancel</button>
            <button class="btn" type="button" onclick="document.getElementById('enrollmentForm').submit()">Yes, Submit</button>
        </div>
    </div>
</div>

<style>
.wizard-stepbar{display:flex;align-items:center;justify-content:center;gap:0;padding:20px 16px;margin-bottom:20px;background:var(--bg,#f8fafc);border-radius:12px}
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
.kv-list{list-style:none;padding:0;margin:0}
.kv-list .item{display:flex;justify-content:space-between;align-items:flex-start;padding:8px 0;border-bottom:1px solid var(--line)}
.kv-list .item:last-child{border-bottom:none}
.kv-list .k{color:var(--muted,#64748b);font-size:13px;min-width:120px}
.kv-list .v{font-size:13px;font-weight:600;text-align:right}
</style>

<script>
var wizardCurrentStep = 1;

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

    wizardCurrentStep = step;
    if (step === 3) updateReview();
}

function wizardNext(step) { wizardGoTo(step); }
function wizardPrev(step) { wizardGoTo(step); }

function updateReview() {
    var secSel = document.getElementById('sectionSelect');
    var statSel = document.getElementById('statusSelect');
    var sectionText = secSel.options[secSel.selectedIndex].text;
    var statusText = statSel.value === 'regular' ? 'Regular' : 'Irregular';

    document.getElementById('reviewSection').textContent = sectionText;
    document.getElementById('reviewStatus').textContent = statusText;

    var subjectCount, totalUnits;
    var reviewBody = document.getElementById('reviewSubjects');
    reviewBody.innerHTML = '';

    if (statSel.value === 'regular') {
        var rows = document.getElementById('regularTableBody').querySelectorAll('tr');
        subjectCount = rows.length;
        totalUnits = 0;
        rows.forEach(function(r) {
            var cells = r.querySelectorAll('td');
            var units = parseFloat(cells[2].textContent) || 0;
            totalUnits += units;
            reviewBody.innerHTML += '<tr><td>' + cells[0].textContent + '</td><td>' + cells[1].textContent + '</td><td>' + units + '</td><td>' + cells[3].textContent + '</td></tr>';
        });
    } else {
        var checks = document.querySelectorAll('.irr-check:checked');
        subjectCount = checks.length;
        totalUnits = 0;
        checks.forEach(function(c) {
            var tr = c.closest('tr');
            var cells = tr.querySelectorAll('td');
            var units = parseFloat(c.getAttribute('data-units')) || 0;
            totalUnits += units;
            reviewBody.innerHTML += '<tr><td>' + cells[2].textContent + '</td><td>' + cells[3].textContent + '</td><td>' + units + '</td><td>-</td></tr>';
        });
    }

    document.getElementById('reviewCount').textContent = subjectCount + ' subject(s)';
    document.getElementById('reviewUnits').textContent = totalUnits + ' units';

    var tuitionPerUnit = <?= (float) $financial['tuition_per_unit'] ?>;
    var otherFees = <?= $otherFees ?>;
    var tuition = totalUnits * tuitionPerUnit;
    var total = tuition + otherFees;

    document.getElementById('reviewTuition').textContent = 'â‚±' + tuition.toFixed(2);
    document.getElementById('reviewTotal').textContent = 'â‚±' + total.toFixed(2);

    document.getElementById('modal-section').textContent = sectionText;
    document.getElementById('modal-status').textContent = statusText;
    document.getElementById('modal-subject-count').textContent = subjectCount + ' subject(s)';
}

function showConfirmModal() { document.getElementById('confirm-modal').style.display = 'flex'; }
function hideConfirmModal() { document.getElementById('confirm-modal').style.display = 'none'; }

function saveDraft() {
    var secSel = document.getElementById('sectionSelect');
    var statSel = document.getElementById('statusSelect');
    document.getElementById('draft_section_id').value = secSel.value;
    document.getElementById('draft_requested_status').value = statSel.value;

    var idsDiv = document.getElementById('draft_offering_ids');
    idsDiv.innerHTML = '';
    if (statSel.value === 'irregular') {
        document.querySelectorAll('.irr-check:checked').forEach(function(c, i) {
            var inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = 'offering_ids[]';
            inp.value = c.value;
            idsDiv.appendChild(inp);
        });
    }
    document.getElementById('draftForm').submit();
}

document.addEventListener('DOMContentLoaded', function() {
    var sectionSel = document.getElementById('sectionSelect');
    var statusSel = document.getElementById('statusSelect');
    var regularPanel = document.getElementById('regularPanel');
    var irregularPanel = document.getElementById('irregularPanel');
    var instruction = document.getElementById('subjectInstruction');

    function toggleStatus() {
        if (statusSel.value === 'regular') {
            regularPanel.style.display = 'block';
            irregularPanel.style.display = 'none';
            instruction.textContent = 'Regular students receive all eligible subjects automatically.';
        } else {
            regularPanel.style.display = 'none';
            irregularPanel.style.display = 'block';
            instruction.textContent = 'Select the eligible subjects you want. Maximum of 27 units.';
            updateUnitCounter();
        }
    }

    function updateUnitCounter() {
        var checks = document.querySelectorAll('.irr-check:checked');
        var units = 0;
        checks.forEach(function(c) { units += parseFloat(c.getAttribute('data-units')) || 0; });
        var counter = document.getElementById('unitCounter');
        var sc = document.getElementById('subjectCounter');
        counter.textContent = units + ' units selected / 27 max';
        sc.textContent = checks.length + ' subject(s) selected';
        if (units > 27) { counter.className = 'badge danger'; }
        else if (units > 20) { counter.className = 'badge warning'; }
        else { counter.className = 'badge info'; }
    }

    document.querySelectorAll('.irr-check').forEach(function(c) {
        c.addEventListener('change', updateUnitCounter);
    });

    statusSel.addEventListener('change', toggleStatus);
    toggleStatus();

    <?php if ($editingDraft || $resubmitParam): ?>
    (function(){
        <?php if ($editingDraft): ?>
            var draftSection = <?= (int) $editingDraft['requested_section_id'] ?>;
            var draftStatus = '<?= h($editingDraft['requested_status']) ?>';
            var draftOfferingIds = <?= json_encode(array_map(function($it) { return (int) $it['offering_id']; }, $draftItems)) ?>;
        <?php elseif ($resubmitSource): ?>
            var draftSection = <?= (int) $resubmitSource['requested_section_id'] ?>;
            var draftStatus = '<?= h($resubmitSource['requested_status']) ?>';
            var draftOfferingIds = <?= json_encode(array_map(function($it) { return (int) $it['offering_id']; }, $resubmitItems)) ?>;
        <?php endif; ?>

        if (draftSection) {
            var secSel = document.getElementById('sectionSelect');
            if (secSel) {
                for (var i = 0; i < secSel.options.length; i++) {
                    if (parseInt(secSel.options[i].value) === draftSection) {
                        secSel.selectedIndex = i;
                        break;
                    }
                }
            }
        }

        if (draftStatus) {
            statusSel.value = draftStatus;
            toggleStatus();
        }

        if (draftOfferingIds.length > 0) {
            document.querySelectorAll('.irr-check').forEach(function(c) {
                if (draftOfferingIds.indexOf(parseInt(c.value)) !== -1) {
                    c.checked = true;
                }
            });
            updateUnitCounter();
        }
    })();
    <?php elseif ($resubmitSource && $resubmitSource['requested_status'] === 'irregular'): ?>
    statusSel.value = 'irregular';
    toggleStatus();
    <?php endif; ?>
});
</script>
<?php elseif ($draftRequest !== null): ?>
    <div class="card" style="margin-top:16px;border-left:4px solid #f59e0b;">
        <div class="page-header" style="margin-bottom:10px;">
            <div>
                <h3>Draft Enrollment Request</h3>
                <p class="helper">You have a saved draft from <?= h(date('M j, Y g:i A', strtotime($draftRequest['created_at']))) ?></p>
            </div>
            <div style="display:flex;gap:8px;">
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="submit_draft">
                    <input type="hidden" name="draft_id" value="<?= h($draftRequest['id']) ?>">
                    <button class="btn" type="submit">Submit Draft</button>
                </form>
                <form method="post" style="display:inline;">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="edit_draft">
                    <input type="hidden" name="draft_id" value="<?= h($draftRequest['id']) ?>">
                    <button class="btn secondary" type="submit">Edit Draft</button>
                </form>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th><th>Schedule</th></tr></thead>
                <tbody>
                <?php foreach ($draftItems as $item): ?>
                    <tr>
                        <td><?= h($item['subject_code']) ?></td>
                        <td><?= h($item['subject_description']) ?></td>
                        <td><?= h($item['units']) ?></td>
                        <td><?= h($item['year_level'] . '-' . $item['section_name']) ?></td>
                        <td><?= h(trim(($item['instructor_name'] ?: 'TBA'))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="2" style="text-align:right;font-weight:700;">Total Units:</td>
                        <td style="font-weight:700;"><?= h($draftRequest['total_units']) ?></td>
                        <td colspan="2"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;align-items:center;">
            <form method="post" onsubmit="return confirm('Discard this draft?');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="discard_draft">
                <input type="hidden" name="draft_id" value="<?= h($draftRequest['id']) ?>">
                <button class="btn secondary danger" type="submit">Discard Draft</button>
            </form>
        </div>
    </div>
<?php elseif ($latestRequest !== null && in_array($latestRequest['workflow_status'], ['rejected', 'cancelled'], true)): ?>
    <div class="card" style="margin-top:16px;border-left:4px solid #6366f1;">
        <h3>Previous request was <?= h($latestRequest['workflow_status'] === 'rejected' ? 'rejected' : 'cancelled') ?></h3>
        <p class="helper">
            <?php if ($latestRequest['workflow_status'] === 'rejected'): ?>
                <?php
                $rejectStage = $latestRequest['adviser_status'] === 'rejected' ? 'adviser' :
                               ($latestRequest['chair_status'] === 'rejected' ? 'department chair' :
                               ($latestRequest['registrar_status'] === 'rejected' ? 'registrar' : ''));
                $rejectRemark = $latestRequest['adviser_remark'] ?: $latestRequest['chair_remark'] ?: $latestRequest['registrar_remark'] ?: '';
                ?>
                Your request was rejected at the <strong><?= h(ucfirst($rejectStage)) ?></strong> stage.
                <?php if ($rejectRemark): ?><br>Reason: <em>"<?= h($rejectRemark) ?>"</em><?php endif; ?>
            <?php else: ?>
                You cancelled your previous request.
            <?php endif; ?>
        </p>
        <?php $prevItems = enrollment_request_items((int) $latestRequest['id']); ?>
        <?php if (!empty($prevItems)): ?>
        <div style="margin:12px 0;">
            <h4 style="margin-bottom:8px;">Subjects from previous request:</h4>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>Code</th><th>Description</th><th>Units</th><th>Section</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($prevItems as $item): ?>
                        <tr>
                            <td><?= h($item['subject_code']) ?></td>
                            <td><?= h($item['subject_description']) ?></td>
                            <td><?= h($item['units']) ?></td>
                            <td><?= h($item['year_level'] . '-' . $item['section_name']) ?></td>
                            <td><span class="badge danger"><?= h($latestRequest['workflow_status'] === 'rejected' ? 'Rejected' : 'Cancelled') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2" style="text-align:right;font-weight:700;">Total Units:</td>
                            <td style="font-weight:700;"><?= h($latestRequest['total_units']) ?></td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
        <?php endif; ?>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;">
            <form method="post" style="display:inline;" id="resubmitForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resubmit_request">
                <input type="hidden" name="source_request_id" value="<?= h($latestRequest['id']) ?>">
                <button class="btn" type="submit">Resubmit with Same Subjects</button>
            </form>
            <a class="btn secondary" href="<?= h(app_url('student/enrollment.php')) ?>">Create New Enrollment</a>
        </div>
    </div>

    <div id="resubmit-confirm" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:999;align-items:center;justify-content:center;">
        <div style="background:var(--panel,#fff);border-radius:12px;padding:28px 32px;max-width:480px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.18);">
            <h3 style="margin:0 0 8px;">Confirm Resubmission</h3>
            <p style="color:var(--muted);font-size:14px;margin-bottom:16px;">
                Your previous subjects will be pre-selected. You can modify them before submitting.
            </p>
            <ul style="font-size:14px;line-height:1.8;padding-left:18px;margin-bottom:20px;">
                <li><strong>Term:</strong> <?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></li>
                <li><strong>Subjects:</strong> <?= count($prevItems) ?> subject(s)</li>
                <li><strong>Total Units:</strong> <?= h($latestRequest['total_units']) ?></li>
            </ul>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button class="btn secondary" type="button" onclick="document.getElementById('resubmit-confirm').style.display='none'">Cancel</button>
                <button class="btn" type="button" onclick="document.getElementById('resubmit-confirm').style.display='none';document.getElementById('resubmitForm').submit();">Continue to Edit</button>
            </div>
        </div>
    </div>

    <script>
    (function(){
        var form = document.getElementById('resubmitForm');
        if (!form) return;
        form.addEventListener('submit', function(e){
            e.preventDefault();
            document.getElementById('resubmit-confirm').style.display = 'flex';
        });
    })();
    </script>
<?php else: ?>
    <div class="card" style="margin-top:16px;">
        <h3>No enrollment request</h3>
        <p class="helper">You don't have any active enrollment requests for this term. Start a new enrollment below.</p>
        <a class="btn" href="<?= h(app_url('student/enrollment.php?new=1')) ?>">Start New Enrollment</a>
    </div>
<?php endif; ?>
<?php
render_page('Online Enrollment', 'Online Enrollment', (string) ob_get_clean());