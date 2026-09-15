<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['instructor', 'adviser']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$gradingPeriods = GradingEngine::getGradingPeriods();
$selectedPeriodId = (int) ($_GET['period_id'] ?? 0);
if ($selectedPeriodId === 0 && !empty($gradingPeriods)) {
    $selectedPeriodId = (int) $gradingPeriods[0]['id'];
}

// Handle save grade
if (is_post() && ($_POST['action'] ?? '') === 'save_grade') {
    $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
    $grade = trim($_POST['grade_value'] ?? '');
    $periodId = (int) ($_POST['grading_period_id'] ?? 0);
    $result = GradingEngine::saveDraft($studentSubjectId, $periodId, $grade, (int) $staff['staff_id']);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Handle submit all grades
if (is_post() && ($_POST['action'] ?? '') === 'submit_grades') {
    $schedCode = $_POST['sched_code'] ?? '';
    $periodId = (int) ($_POST['grading_period_id'] ?? 0);
    $result = GradingEngine::submitGrades($schedCode, $periodId, (int) $staff['staff_id']);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Handle request correction
if (is_post() && ($_POST['action'] ?? '') === 'request_correction') {
    $gradeId = (int) ($_POST['grade_id'] ?? 0);
    $newGrade = trim($_POST['new_grade'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    $result = GradingEngine::requestCorrection($gradeId, $newGrade, $reason, (int) $staff['staff_id']);
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

// Get offerings for this instructor (current term only)
$currentTerm = current_term();
$termFilter = '';
$termParams = ['iid1' => (int) $staff['staff_id'], 'iid2' => (int) $staff['staff_id']];
if ($currentTerm !== null) {
    $termFilter = ' AND o.term_id = :term_id';
    $termParams['term_id'] = (int) $currentTerm['id'];
}

$offerings = fetch_all(
    'SELECT o.id, o.sched_code, o.term_id, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name,
            ay.year_label, t.semester
     FROM section_subject_offerings o
     LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     INNER JOIN academic_terms t ON t.id = o.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE (o.instructor_id = :iid1 OR cs.instructor_id = :iid2) AND o.status = "active"' . $termFilter . '
     GROUP BY o.id
     ORDER BY sub.subject_code',
    $termParams
);

$offeringId = (int) ($_GET['offering_id'] ?? ($offerings[0]['id'] ?? 0));
$students = [];
$offeringInfo = null;

if ($offeringId > 0) {
    $offeringInfo = fetch_one(
        'SELECT o.*, sub.subject_code, sub.subject_description, p.program_code, sec.year_level, sec.section_name,
                COALESCE(st.full_name, "TBA") AS instructor_name,
                ay.year_label, t.semester
         FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN academic_terms t ON t.id = o.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
         LEFT JOIN staff st ON st.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
         WHERE o.id = :id',
        ['id' => $offeringId]
    );

    if ($offeringInfo !== null) {
        $schedCode = $offeringInfo['sched_code'] ?? '';
        // Verify the offering belongs to the current term
        if ($currentTerm !== null && (int) ($offeringInfo['term_id'] ?? 0) !== (int) $currentTerm['id']) {
            flash('error', 'This offering does not belong to the current term.');
            redirect('instructor/students.php');
        }
        $students = fetch_all(
            "SELECT ss.id AS student_subject_id, s.student_number,
                    CONCAT(s.first_name, ' ', IFNULL(s.middle_name, ''), ' ', s.last_name) AS full_name,
                    ss.midterm_grade, ss.final_grade,
                    gs_mid.id AS mid_grade_id, gs_mid.grade_value AS mid_grade_value, gs_mid.grade_status AS mid_grade_status,
                    gs_fin.id AS fin_grade_id, gs_fin.grade_value AS fin_grade_value, gs_fin.grade_status AS fin_grade_status
             FROM student_subjects ss
             INNER JOIN students s ON s.id = ss.student_id
             LEFT JOIN grades gs_mid ON gs_mid.student_subject_id = ss.id AND gs_mid.grading_period_id = (SELECT id FROM grading_periods WHERE period_code = 'midterm' LIMIT 1)
             LEFT JOIN grades gs_fin ON gs_fin.student_subject_id = ss.id AND gs_fin.grading_period_id = (SELECT id FROM grading_periods WHERE period_code = 'final' LIMIT 1)
             WHERE ss.offering_id = :offering_id AND ss.enrollment_status = 'enrolled'
             ORDER BY s.student_number",
            ['offering_id' => $offeringId]
        );
    }
}

$termDeadline = current_term();
$validGrades = GradingEngine::validGradeCodes();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Student Lists and Grades</h1>
        <p>View handled subject student lists and input grades. <?= deadline_badge('grade_deadline', $termDeadline) ?></p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('instructor/download_students.php?offering_id=' . $offeringId)) ?>">Download CSV</a>
    </div>
</div>

<div class="card">
    <form method="get" class="filter-bar">
        <div>
            <label>Subject Offering</label>
            <select name="offering_id" onchange="this.form.submit()">
                <?php foreach ($offerings as $offering): ?>
                    <option value="<?= h($offering['id']) ?>" <?= $offeringId === (int) $offering['id'] ? 'selected' : '' ?>><?= h(($offering['sched_code'] ?? '---') . ' | ' . $offering['subject_code'] . ' [' . $offering['program_code'] . ' ' . $offering['year_level'] . $offering['section_name'] . ']') ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Grading Period</label>
            <select name="period_id" onchange="this.form.submit()">
                <?php foreach ($gradingPeriods as $period): ?>
                    <option value="<?= h($period['id']) ?>" <?= $selectedPeriodId === (int) $period['id'] ? 'selected' : '' ?>><?= h($period['period_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<?php if ($offeringInfo !== null): ?>
<div class="card" style="margin-top: 16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;">
        <div>
            <h3 style="margin:0;"><?= h($offeringInfo['sched_code'] ?? '---') ?> — <?= h($offeringInfo['subject_code'] . ' - ' . $offeringInfo['subject_description']) ?></h3>
            <p style="margin:4px 0 0;color:#64748b;"><?= h($offeringInfo['program_code'] . ' ' . $offeringInfo['year_level'] . $offeringInfo['section_name']) ?> | Instructor: <?= h($offeringInfo['instructor_name']) ?></p>
        </div>
        <div class="actions-row">
            <button class="btn" onclick="submitAllGrades()">Submit All Grades</button>
        </div>
    </div>

    <div class="dt" data-dt-page-size="10">
        <div class="table-wrap">
            <table id="gradeTable">
                <thead>
                    <tr>
                        <th>Student Number</th>
                        <th>Name</th>
                        <th>Midterm</th>
                        <th>Final</th>
                        <th>Status</th>
                        <th data-dt-no-sort>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($students as $student): ?>
                    <tr data-ss-id="<?= $student['student_subject_id'] ?>">
                        <td><?= h($student['student_number']) ?></td>
                        <td><?= h($student['full_name']) ?></td>
                        <td>
                            <?php
                            $midStatus = $student['mid_grade_status'] ?? 'draft';
                            $midVal = $student['mid_grade_value'] ?? $student['midterm_grade'] ?? '';
                            $midEditable = !in_array($midStatus, ['locked', 'submitted', 'corrected'], true);
                            ?>
                            <?php if ($midEditable): ?>
                                <select class="grade-input" data-period="midterm" data-ss-id="<?= $student['student_subject_id'] ?>" data-grade-id="<?= $student['mid_grade_id'] ?? '' ?>" onchange="saveGrade(this)">
                                    <option value="">---</option>
                                    <?php foreach ($validGrades as $code): ?>
                                        <option value="<?= h($code) ?>" <?= strtoupper($midVal) === $code ? 'selected' : '' ?>><?= h($code) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="badge <?= GradingEngine::statusBadgeClass($midStatus) ?>"><?= h($midVal ?: '-') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $finStatus = $student['fin_grade_status'] ?? 'draft';
                            $finVal = $student['fin_grade_value'] ?? $student['final_grade'] ?? '';
                            $finEditable = !in_array($finStatus, ['locked', 'submitted', 'corrected'], true);
                            ?>
                            <?php if ($finEditable): ?>
                                <select class="grade-input" data-period="final" data-ss-id="<?= $student['student_subject_id'] ?>" data-grade-id="<?= $student['fin_grade_id'] ?? '' ?>" onchange="saveGrade(this)">
                                    <option value="">---</option>
                                    <?php foreach ($validGrades as $code): ?>
                                        <option value="<?= h($code) ?>" <?= strtoupper($finVal) === $code ? 'selected' : '' ?>><?= h($code) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <span class="badge <?= GradingEngine::statusBadgeClass($finStatus) ?>"><?= h($finVal ?: '-') ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $displayStatus = $finStatus ?: $midStatus;
                            ?>
                            <span class="badge <?= GradingEngine::statusBadgeClass($displayStatus) ?>"><?= GradingEngine::statusLabel($displayStatus) ?></span>
                        </td>
                        <td>
                            <?php if ($finStatus === 'locked' && ($midStatus === 'locked' || $midStatus === 'submitted')): ?>
                                <button class="btn small secondary" onclick="requestCorrection(<?= $student['fin_grade_id'] ?? 0 ?>)">Request Correction</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
var SCHED_CODE = <?= json_encode($offeringInfo['sched_code'] ?? '') ?>;
var PERIOD_ID = <?= $selectedPeriodId ?>;
var VALID_GRADES = <?= json_encode($validGrades) ?>;

function saveGrade(el) {
    var ssId = parseInt(el.dataset.ssId);
    var grade = el.value;
    var periodCode = el.dataset.period;
    var periodId = periodCode === 'midterm' ? <?= $gradingPeriods[0]['id'] ?? 1 ?> : <?= $gradingPeriods[1]['id'] ?? 2 ?>;

    var formData = new FormData();
    formData.append('action', 'save_grade');
    formData.append('student_subject_id', ssId);
    formData.append('grading_period_id', periodId);
    formData.append('grade_value', grade);

    fetch('<?= h(app_url('instructor/students.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (!data.success) {
            alert(data.message);
            location.reload();
        }
    }).catch(() => { alert('Save failed.'); location.reload(); });
}

function submitAllGrades() {
    if (!confirm('Submit all grades for ' + SCHED_CODE + '? You cannot edit after submission.')) return;

    var formData = new FormData();
    formData.append('action', 'submit_grades');
    formData.append('sched_code', SCHED_CODE);
    formData.append('grading_period_id', PERIOD_ID);

    fetch('<?= h(app_url('instructor/students.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    }).catch(() => alert('Submission failed.'));
}

function requestCorrection(gradeId) {
    if (!confirm('Request a grade correction? This will require registrar approval.')) return;
    var newGrade = prompt('Enter the corrected grade:');
    if (newGrade === null || newGrade.trim() === '') return;
    var reason = prompt('Reason for correction:');
    if (reason === null || reason.trim() === '') return;

    var formData = new FormData();
    formData.append('action', 'request_correction');
    formData.append('grade_id', gradeId);
    formData.append('new_grade', newGrade.trim());
    formData.append('reason', reason.trim());

    fetch('<?= h(app_url('instructor/students.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) {
            alert(data.message);
            location.reload();
        } else {
            alert(data.message);
        }
    }).catch(() => alert('Request failed.'));
}
</script>
<?php
render_page('Student Lists', 'Student List / Grades', (string) ob_get_clean());
