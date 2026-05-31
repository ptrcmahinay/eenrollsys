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
        // Block duplicate subject selection across sections
        if (student_is_irregular((int) $student['id'])) {
            $offeringSubjectMap = [];
            foreach ($allowed as $off) {
                $offeringSubjectMap[(int) $off['id']] = (int) $off['subject_id'];
            }
            $seenSubjects = [];
            foreach ($offeringIds as $oid) {
                $sid = $offeringSubjectMap[$oid] ?? 0;
                if ($sid && isset($seenSubjects[$sid])) {
                    flash('error', 'You cannot select the same subject from multiple sections. Please choose only one offering per subject.');
                    redirect('student/enrollment.php');
                }
                if ($sid) $seenSubjects[$sid] = true;
            }
        }
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
            $stmt = db()->prepare("SELECT COALESCE(SUM(sub.lec_credit + sub.lab_credit), 0) AS total FROM section_subject_offerings o INNER JOIN subjects sub ON sub.subject_id = o.subject_id WHERE o.id IN ($placeholders)");
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
$feeItems = fee_items_for_enrollment((int) $student['program_id'], (int) $student['year_level'], (string) $currentTerm['semester']);

$tuitionPerUnit = 0;
$labFeeRate = 0;
$labFeeName = 'Laboratory Fee';
if (isset($feeItems['assessment'])) {
    foreach ($feeItems['assessment'] as $fi) {
        if (strcasecmp($fi['fee_name'], 'tuition') === 0) {
            $tuitionPerUnit = (float) $fi['amount'];
            break;
        }
    }
}
if (isset($feeItems['laboratory'])) {
    foreach ($feeItems['laboratory'] as $fi) {
        $labFeeRate = (float) $fi['amount'];
        $labFeeName = ucfirst($fi['fee_name']) . ' Fee';
        break;
    }
}

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

$initialTotalUnits = 0;
$initialLabCredits = 0;
$feeItemsTotalDisplay = 0;
foreach ($feeItems as $cat => $items) {
    if ($cat === 'laboratory') continue;
    foreach ($items as $fi) {
        if ($cat === 'assessment' && strcasecmp($fi['fee_name'], 'tuition') === 0) continue;
        $feeItemsTotalDisplay += (float) $fi['amount'];
    }
}
if ($recommendedStatus === 'regular') {
    foreach ($regularPreview as $r) {
        $initialTotalUnits += (float) $r['units'];
        $initialLabCredits += (float) ($r['lab_credit'] ?? 0);
    }
} elseif ($resubmitSource) {
    foreach ($resubmitItems as $it) {
        $initialTotalUnits += (float) $it['units'];
        $initialLabCredits += (float) ($it['lab_credit'] ?? 0);
    }
} elseif ($editingDraft) {
    foreach ($draftItems as $it) {
        $initialTotalUnits += (float) $it['units'];
        $initialLabCredits += (float) ($it['lab_credit'] ?? 0);
    }
}
$initialTuition = $initialTotalUnits * $tuitionPerUnit;
$initialLabFee = $initialLabCredits * $labFeeRate;
$initialTotal = $initialTuition + $initialLabFee + $feeItemsTotalDisplay + $otherFees;

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
        <p class="helper">Requested status: <?= h($latestRequest['requested_status']) ?> Total units: <?= h($latestRequest['total_units']) ?> Amount: <?= h(format_money($latestRequest['total_amount'])) ?></p>
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
                                <span class="badge <?= $recommendedStatus === 'regular' ? 'success' : 'warning' ?>"><?= h(ucfirst($recommendedStatus)) ?></span>
                                <input type="hidden" name="requested_status" id="statusSelect" value="<?= h($recommendedStatus) ?>">
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
                        <thead><tr><th>Code</th><th>Description</th><th>Lec</th><th>Lab</th><th>Units</th><th>Prerequisite Check</th></tr></thead>
                        <tbody id="regularTableBody">
                        <?php foreach ($regularPreview as $row): ?>
                            <?php $eligibility = prerequisite_status_for_curriculum((int) $student['id'], $row); ?>
                            <tr data-lab-credits="<?= h((string) ($row['lab_credit'] ?? 0)) ?>">
                                <td><?= h($row['subject_code']) ?></td>
                                <td><?= h($row['subject_description']) ?></td>
                                <td style="text-align:center"><?= h($row['lec_credit'] ?? '0') ?></td>
                                <td style="text-align:center"><?= h($row['lab_credit'] ?? '0') ?></td>
                                <td style="text-align:center" class="reg-unit"><?= h($row['units']) ?></td>
                                <td><span class="badge <?= $eligibility['eligible'] ? 'success' : 'danger' ?>"><?= $eligibility['eligible'] ? 'Eligible' : h($eligibility['reason']) ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="4" style="text-align:right;font-weight:700;">Total Units:</td>
                                <td id="regularTotalUnits" style="text-align:center;font-weight:700;">
                                    <?php
                                    $regTotal = 0;
                                    foreach ($regularPreview as $r) {
                                        if (prerequisite_status_for_curriculum((int) $student['id'], $r)['eligible']) $regTotal += (float) $r['units'];
                                    }
                                    echo h((string) $regTotal);
                                    ?>
                                </td>
                                <td></td>
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
                        <thead><tr><th>Select</th><th>Section</th><th>Code</th><th>Description</th><th>Lec</th><th>Lab</th><th>Units</th><th>Eligibility</th></tr></thead>
                        <tbody>
                        <?php foreach ($irregularSuggestions as $row): ?>
                            <?php $checked = isset($resubmitOfferingIds[(int) $row['id']]) ? 'checked' : ''; ?>
                            <tr>
                                <td>
                                    <?php if ($row['eligible']): ?>
                                        <input type="checkbox" name="offering_ids[]" value="<?= h($row['id']) ?>" class="irr-check" data-subject-id="<?= h($row['subject_id']) ?>" data-units="<?= h($row['units']) ?>" data-lab-credits="<?= h((string) ($row['lab_credit'] ?? 0)) ?>" <?= $checked ?>>
                                    <?php else: ?>
                                        <span class="helper">Blocked</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h($row['year_level'] . '-' . $row['section_name']) ?></td>
                                <td><?= h($row['subject_code']) ?></td>
                                <td><?= h($row['subject_description']) ?></td>
                                <td style="text-align:center"><?= h($row['lec_credit'] ?? '0') ?></td>
                                <td style="text-align:center"><?= h($row['lab_credit'] ?? '0') ?></td>
                                <td style="text-align:center" class="irr-unit"><?= h($row['units']) ?></td>
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

            <div style="margin-top:16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">
                <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:8px 16px;">
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Student Number</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($student['student_number']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Full Name</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($student['full_name']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Program</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($student['program_code']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Section</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;" id="reviewSection"></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Term</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($currentTerm['year_label'] . ' / ' . semester_label((string) $currentTerm['semester'])) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Year Level</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($student['year_level']) ?></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Status</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;" id="reviewStatus"></div>
                    </div>
                    <div>
                        <div style="font-size:10px;font-weight:600;color:#15803d;text-transform:uppercase;letter-spacing:0.4px;">Financial Status</div>
                        <div style="font-size:13px;font-weight:600;color:#1e293b;"><?= h($financial['label']) ?></div>
                    </div>
                </div>
            </div>

            <div class="card slim" style="margin-top:14px;">
                <h4>Subjects to be Enrolled</h4>
                <div class="table-wrap">
                    <table>
                        <thead><tr><th>Code</th><th>Description</th><th>Lec</th><th>Lab</th><th>Units</th></tr></thead>
                        <tbody id="reviewSubjects"></tbody>
                        <tfoot>
                            <tr>
                                <td colspan="3" style="text-align:right;font-weight:700;">Total Units:</td>
                                <td id="reviewLabTotal" style="text-align:center;font-weight:700;"><?= h((string) $initialLabCredits) ?></td>
                                <td id="reviewUnitsTotal" style="text-align:center;font-weight:700;"><?= h((string) $initialTotalUnits) ?></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <div style="margin-top:8px;font-size:12px;color:#64748b;" id="reviewCount"><?= h($recommendedStatus === 'regular' ? count($regularPreview) : 0) ?> subject(s), <?= h((string) $initialTotalUnits) ?> units</div>
            </div>

            <div style="margin-top:14px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:8px;padding:14px 16px;">
                <h4 style="font-size:13px;font-weight:700;color:#16a34a;margin:0 0 10px;padding-bottom:4px;border-bottom:2px solid #bbf7d0;">Tuition Fee Breakdown</h4>
                <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:8px;">
                    <div style="display:flex;flex-direction:column;gap:4px;background:#fff;border-radius:6px;padding:10px 12px;border:1px solid #bbf7d0;">
                        <div style="font-size:11px;font-weight:700;color:#15803d;">1. Tuition Fee</div>
                        <div style="font-size:12px;padding:2px 0;">
                            <div style="display:flex;justify-content:space-between;"><span>Tuition per Unit</span><span style="font-weight:600;">&#8369;<?= h(format_money($tuitionPerUnit)) ?></span></div>
                            <div style="display:flex;justify-content:space-between;"><span>Total Units</span><span style="font-weight:600;" id="reviewUnits2"><?= h((string) $initialTotalUnits) ?></span></div>
                            <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;margin-top:4px;padding-top:4px;font-weight:700;"><span>Tuition Fee</span><span id="reviewTuition"><?= h(format_money($initialTuition)) ?></span></div>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;background:#fff;border-radius:6px;padding:10px 12px;border:1px solid #bbf7d0;">
                        <div style="font-size:11px;font-weight:700;color:#15803d;">2. <?= h($labFeeName) ?></div>
                        <div style="font-size:12px;padding:2px 0;">
                            <div style="display:flex;justify-content:space-between;"><span>Total Lab Credits</span><span style="font-weight:600;" id="reviewLabTotal2"><?= h((string) $initialLabCredits) ?></span></div>
                            <div style="display:flex;justify-content:space-between;"><span>Rate per Lab Credit</span><span style="font-weight:600;">&#8369;<?= h(format_money($labFeeRate)) ?></span></div>
                            <div style="display:flex;justify-content:space-between;border-top:1px solid #e2e8f0;margin-top:4px;padding-top:4px;font-weight:700;"><span><?= h($labFeeName) ?> Total</span><span id="reviewLabFee"><?= h(format_money($initialLabFee)) ?></span></div>
                        </div>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:4px;background:#fff;border-radius:6px;padding:10px 12px;border:1px solid #bbf7d0;">
                        <div style="font-size:11px;font-weight:700;color:#15803d;">3. Other Fees</div>
                        <div style="font-size:12px;padding:2px 0;">
                            <?php foreach ($feeItems as $cat => $items): ?>
                                <?php foreach ($items as $fi): ?>
                                    <?php if ($cat === 'laboratory') continue; ?>
                            <div style="display:flex;justify-content:space-between;"><span><?= h($fi['fee_name']) ?></span><span style="font-weight:600;">&#8369;<?= h(format_money((float) $fi['amount'])) ?></span></div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                            <div style="display:flex;justify-content:space-between;"><span>Other School Fees</span><span style="font-weight:600;">&#8369;<?= h(format_money($otherFees)) ?></span></div>
                        </div>
                    </div>
                </div>
                <div style="display:flex;justify-content:space-between;align-items:center;background:linear-gradient(135deg,#16a34a,#22c55e);border-radius:6px;padding:12px 16px;margin-top:8px;">
                    <span style="font-size:13px;font-weight:700;color:#fff;text-transform:uppercase;">Total Amount Due</span>
                    <span style="font-size:18px;font-weight:800;color:#fff;" id="reviewTotal"><?= h(format_money($initialTotal)) ?></span>
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

    var subjectCount, totalUnits, totalLabCredits;
    var reviewBody = document.getElementById('reviewSubjects');
    reviewBody.innerHTML = '';
    totalUnits = 0;
    totalLabCredits = 0;

    if (statSel.value === 'regular') {
        var rows = document.getElementById('regularTableBody').querySelectorAll('tr');
        subjectCount = rows.length;
        rows.forEach(function(r) {
            var cells = r.querySelectorAll('td');
            var units = parseFloat(cells[4].textContent) || 0;
            var lab = parseFloat(cells[3].textContent) || 0;
            totalUnits += units;
            totalLabCredits += lab;
            reviewBody.innerHTML += '<tr><td>' + cells[0].textContent + '</td><td>' + cells[1].textContent + '</td><td style=\"text-align:center\">' + cells[2].textContent + '</td><td style=\"text-align:center\">' + lab + '</td><td style=\"text-align:center\">' + units + '</td></tr>';
        });
    } else {
        var checks = document.querySelectorAll('.irr-check:checked');
        subjectCount = checks.length;
        checks.forEach(function(c) {
            var tr = c.closest('tr');
            var cells = tr.querySelectorAll('td');
            var units = parseFloat(c.getAttribute('data-units')) || 0;
            var lab = parseFloat(c.getAttribute('data-lab-credits')) || 0;
            totalUnits += units;
            totalLabCredits += lab;
            reviewBody.innerHTML += '<tr><td>' + cells[2].textContent + '</td><td>' + cells[3].textContent + '</td><td style=\"text-align:center\">' + cells[4].textContent + '</td><td style=\"text-align:center\">' + lab + '</td><td style=\"text-align:center\">' + units + '</td></tr>';
        });
    }

    document.getElementById('reviewCount').textContent = subjectCount + ' subject(s), ' + totalUnits + ' units';
    document.getElementById('reviewUnitsTotal').textContent = totalUnits;
    document.getElementById('reviewUnits2').textContent = totalUnits;
    document.getElementById('reviewLabTotal').textContent = totalLabCredits;
    document.getElementById('reviewLabTotal2').textContent = totalLabCredits;

    var tuitionPerUnit = <?= (float) $tuitionPerUnit ?>;
    var otherFees = <?= $otherFees ?>;
    var labFeeRate = <?= (float) $labFeeRate; ?>;
    var tuition = totalUnits * tuitionPerUnit;
    var labFee = totalLabCredits * labFeeRate;
    var feeItemsTotal = 0;

    <?php foreach ($feeItems as $cat => $items): ?>
        <?php if ($cat === 'laboratory') continue; ?>
        <?php foreach ($items as $fi): ?>
            <?php if ($cat === 'assessment' && strcasecmp($fi['fee_name'], 'tuition') === 0) continue; ?>
            feeItemsTotal += <?= (float) $fi['amount'] ?>;
        <?php endforeach; ?>
    <?php endforeach; ?>

    var total = tuition + labFee + feeItemsTotal + otherFees;

    document.getElementById('reviewTuition').textContent = tuition.toFixed(2);
    document.getElementById('reviewLabFee').textContent = labFee.toFixed(2);
    document.getElementById('reviewTotal').textContent = total.toFixed(2);

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

        // Re-enable all checkboxes first, then disable same-subject duplicates
        document.querySelectorAll('.irr-check').forEach(function(c) {
            c.disabled = false;
        });
        var checkedSubjects = {};
        document.querySelectorAll('.irr-check:checked').forEach(function(c) {
            var sid = c.getAttribute('data-subject-id');
            if (checkedSubjects[sid]) {
                // Shouldn't happen with the handler below, but safeguard
                c.checked = false;
            } else {
                checkedSubjects[sid] = true;
            }
        });
        document.querySelectorAll('.irr-check:checked').forEach(function(c) {
            var sid = c.getAttribute('data-subject-id');
            document.querySelectorAll('.irr-check[data-subject-id="' + sid + '"]').forEach(function(other) {
                if (other !== c) {
                    other.disabled = true;
                }
            });
        });
    }

    document.querySelectorAll('.irr-check').forEach(function(c) {
        c.addEventListener('change', updateUnitCounter);
    });

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

        toggleStatus();

        if (draftOfferingIds.length > 0) {
            document.querySelectorAll('.irr-check').forEach(function(c) {
                if (draftOfferingIds.indexOf(parseInt(c.value)) !== -1) {
                    c.checked = true;
                }
            });
            updateUnitCounter();
        }
    })();
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
                <thead><tr><th>Code</th><th>Description</th><th>Lec</th><th>Lab</th><th>Units</th><th>Section</th></tr></thead>
                <tbody>
                <?php foreach ($draftItems as $item): ?>
                    <tr>
                        <td><?= h($item['subject_code']) ?></td>
                        <td><?= h($item['subject_description']) ?></td>
                        <td style="text-align:center"><?= h($item['lec_credit'] ?? '0') ?></td>
                        <td style="text-align:center"><?= h($item['lab_credit'] ?? '0') ?></td>
                        <td style="text-align:center"><?= h($item['units']) ?></td>
                        <td><?= h($item['year_level'] . '-' . $item['section_name']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <td colspan="4" style="text-align:right;font-weight:700;">Total Units:</td>
                        <td style="text-align:center;font-weight:700;"><?= h($draftRequest['total_units']) ?></td>
                        <td></td>
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
                    <thead><tr><th>Code</th><th>Description</th><th>Lec</th><th>Lab</th><th>Units</th><th>Section</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach ($prevItems as $item): ?>
                        <tr>
                            <td><?= h($item['subject_code']) ?></td>
                            <td><?= h($item['subject_description']) ?></td>
                            <td style="text-align:center"><?= h($item['lec_credit'] ?? '0') ?></td>
                            <td style="text-align:center"><?= h($item['lab_credit'] ?? '0') ?></td>
                            <td style="text-align:center"><?= h($item['units']) ?></td>
                            <td><?= h($item['year_level'] . '-' . $item['section_name']) ?></td>
                            <td><span class="badge danger"><?= h($latestRequest['workflow_status'] === 'rejected' ? 'Rejected' : 'Cancelled') ?></span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="4" style="text-align:right;font-weight:700;">Total Units:</td>
                            <td style="text-align:center;font-weight:700;"><?= h($latestRequest['total_units']) ?></td>
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