<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$staff = current_staff();
$currentTerm = current_term();
$termId = (int) ($_GET['term_id'] ?? ($currentTerm['id'] ?? 0));

// Handle AJAX actions
if (is_post()) {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_grade') {
        $studentSubjectId = (int) ($_POST['student_subject_id'] ?? 0);
        $grade = trim($_POST['grade_value'] ?? '');
        $periodId = (int) ($_POST['grading_period_id'] ?? 0);
        $result = GradingEngine::saveDraft($studentSubjectId, $periodId, $grade, (int) $staff['staff_id']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if ($action === 'submit_grades') {
        $schedCode = $_POST['sched_code'] ?? '';
        $periodId = (int) ($_POST['grading_period_id'] ?? 0);
        $result = GradingEngine::submitGrades($schedCode, $periodId, (int) $staff['staff_id']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }

    if ($action === 'lock_grades') {
        $schedCode = $_POST['sched_code'] ?? '';
        $periodId = (int) ($_POST['grading_period_id'] ?? 0);
        $result = GradingEngine::lockGrades($schedCode, $periodId, (int) $staff['staff_id']);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit;
    }
}

$filters = [
    'sched_code'     => trim($_GET['sched_code'] ?? ''),
    'department_id'  => (int) ($_GET['department_id'] ?? 0),
    'subject_id'     => (int) ($_GET['subject_id'] ?? 0),
    'instructor_id'  => (int) ($_GET['instructor_id'] ?? 0),
];

$summary = [];
if ($termId > 0) {
    $summary = GradingEngine::getGradeSummaryBySchedCode($termId, $filters);
}

$departments = db()->query("SELECT dept_id, department_name FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
$subjects = db()->query("SELECT subject_id, subject_code, subject_description FROM subjects ORDER BY subject_code")->fetchAll(PDO::FETCH_ASSOC);
$instructors = db()->query("SELECT staff_id, full_name FROM staff WHERE status = 'active' ORDER BY full_name")->fetchAll(PDO::FETCH_ASSOC);
$gradingPeriods = GradingEngine::getGradingPeriods();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Grade Management</h1>
        <p>Manage grades by Sched Code. Filter by department, subject, instructor, and submission status.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url('registrar/grade_corrections.php')) ?>">Grade Corrections</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/grade_scale.php')) ?>">Grade Scale</a>
    </div>
</div>

<div class="card">
    <form method="get" class="filter-bar">
        <div>
            <label>Academic Term</label>
            <select name="term_id" onchange="this.form.submit()">
                <?php
                $terms = db()->query("SELECT t.id, ay.year_label, t.semester FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.start_year DESC, FIELD(t.semester, '1', '2', 'mid')")->fetchAll(PDO::FETCH_ASSOC);
                foreach ($terms as $t):
                    $label = $t['year_label'] . ' — ' . semester_label($t['semester']);
                ?>
                    <option value="<?= $t['id'] ?>" <?= $termId === (int) $t['id'] ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Sched Code</label>
            <input type="text" name="sched_code" value="<?= h($filters['sched_code']) ?>" placeholder="Search...">
        </div>
        <div>
            <label>Department</label>
            <select name="department_id" onchange="this.form.submit()">
                <option value="0">All Departments</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['dept_id'] ?>" <?= $filters['department_id'] === (int) $d['dept_id'] ? 'selected' : '' ?>><?= h($d['department_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Subject</label>
            <select name="subject_id" onchange="this.form.submit()">
                <option value="0">All Subjects</option>
                <?php foreach ($subjects as $s): ?>
                    <option value="<?= $s['subject_id'] ?>" <?= $filters['subject_id'] === (int) $s['subject_id'] ? 'selected' : '' ?>><?= h($s['subject_code'] . ' - ' . $s['subject_description']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label>Instructor</label>
            <select name="instructor_id" onchange="this.form.submit()">
                <option value="0">All Instructors</option>
                <?php foreach ($instructors as $i): ?>
                    <option value="<?= $i['staff_id'] ?>" <?= $filters['instructor_id'] === (int) $i['staff_id'] ? 'selected' : '' ?>><?= h($i['full_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <button class="btn small" type="submit">Filter</button>
        </div>
    </form>
</div>

<div class="card" style="margin-top:16px;">
    <h3>Subject Offerings (<?= count($summary) ?>)</h3>
    <?php if ($termId <= 0): ?>
        <p style="color:#64748b;">Please select an academic term.</p>
    <?php elseif (empty($summary)): ?>
        <p style="color:#64748b;">No offerings found for the selected filters.</p>
    <?php else: ?>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sched Code</th>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Instructor</th>
                        <th>Enrolled</th>
                        <th>Graded (Final)</th>
                        <th>Status</th>
                        <th data-dt-no-sort>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($summary as $row): ?>
                    <tr>
                        <td><strong><?= h($row['sched_code'] ?? '---') ?></strong></td>
                        <td><?= h($row['subject_code'] . ' - ' . $row['subject_description']) ?></td>
                        <td><?= h($row['program_code'] . ' ' . $row['year_level'] . $row['section_name']) ?></td>
                        <td><?= h($row['instructor_name']) ?></td>
                        <td><?= $row['enrolled_count'] ?></td>
                        <td><?= $row['graded_count'] ?></td>
                        <td>
                            <?php if ($row['overall_status']): ?>
                                <span class="badge <?= GradingEngine::statusBadgeClass($row['overall_status']) ?>"><?= GradingEngine::statusLabel($row['overall_status']) ?></span>
                            <?php else: ?>
                                <span class="badge secondary">No Grades</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <button class="btn small" onclick="viewGrades('<?= h($row['sched_code'] ?? '') ?>')">View Grades</button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<!-- Grade Detail Modal -->
<div id="gradeModal" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(0,0,0,.5);z-index:1000;overflow-y:auto;">
    <div style="background:#fff;max-width:1000px;margin:40px auto;border-radius:12px;padding:24px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
            <h3 id="modalTitle" style="margin:0;"></h3>
            <button class="btn secondary" onclick="closeModal()">Close</button>
        </div>
        <div id="modalPeriodTabs" style="display:flex;gap:8px;margin-bottom:16px;"></div>
        <div id="modalContent"></div>
    </div>
</div>

<script>
var CURRENT_TERM_ID = <?= $termId ?>;
var PERIODS = <?= json_encode($gradingPeriods) ?>;
var VALID_GRADES = <?= json_encode(GradingEngine::validGradeCodes()) ?>;

function viewGrades(schedCode) {
    document.getElementById('gradeModal').style.display = 'block';
    document.getElementById('modalTitle').textContent = schedCode + ' — Grade Sheet';

    var tabsHtml = '';
    PERIODS.forEach(function(p, i) {
        tabsHtml += '<button class="btn ' + (i === 0 ? '' : 'secondary') + '" onclick="loadPeriodGrades(\'' + schedCode + '\', ' + p.id + ', this)">' + p.period_name + '</button>';
    });
    document.getElementById('modalPeriodTabs').innerHTML = tabsHtml;

    if (PERIODS.length > 0) {
        loadPeriodGrades(schedCode, PERIODS[0].id, document.querySelector('#modalPeriodTabs .btn'));
    }
}

function loadPeriodGrades(schedCode, periodId, btn) {
    document.querySelectorAll('#modalPeriodTabs .btn').forEach(function(b) { b.className = 'btn secondary'; });
    if (btn) btn.className = 'btn';

    fetch('<?= h(app_url('registrar/class_detail.php')) ?>?action=get_grades&sched_code=' + encodeURIComponent(schedCode) + '&period_id=' + periodId)
        .then(r => r.json())
        .then(function(data) {
            if (!data.success) {
                document.getElementById('modalContent').innerHTML = '<p>' + data.message + '</p>';
                return;
            }
            var html = '<div class="table-wrap"><table><thead><tr><th>Student Number</th><th>Name</th><th>Grade</th><th>Status</th><th>Action</th></tr></thead><tbody>';
            data.grades.forEach(function(g) {
                var editable = (g.grade_status === 'draft' || g.grade_status === 'correction_pending');
                html += '<tr>';
                html += '<td>' + esc(g.student_number) + '</td>';
                html += '<td>' + esc(g.full_name) + '</td>';
                html += '<td>';
                if (editable) {
                    html += '<select class="grade-select" data-ss-id="' + g.student_subject_id + '" data-period-id="' + periodId + '">';
                    html += '<option value="">---</option>';
                    VALID_GRADES.forEach(function(code) {
                        html += '<option value="' + code + '"' + ((g.grade_value || '').toUpperCase() === code.toUpperCase() ? ' selected' : '') + '>' + code + '</option>';
                    });
                    html += '</select>';
                } else {
                    html += '<strong>' + esc(g.grade_value || '-') + '</strong>';
                }
                html += '</td>';
                html += '<td><span class="badge ' + statusBadge(g.grade_status) + '">' + statusLabel(g.grade_status) + '</span></td>';
                html += '<td>';
                if (editable) {
                    html += '<button class="btn small" onclick="saveModalGrade(this)">Save</button>';
                }
                html += '</td>';
                html += '</tr>';
            });
            html += '</tbody></table></div>';

            html += '<div style="margin-top:16px;display:flex;gap:8px;">';
            html += '<button class="btn" onclick="submitModalGrades(\'' + schedCode + '\', ' + periodId + ')">Submit All</button>';
            html += '<button class="btn secondary" onclick="lockModalGrades(\'' + schedCode + '\', ' + periodId + ')">Lock All</button>';
            html += '</div>';

            document.getElementById('modalContent').innerHTML = html;
        })
        .catch(function() {
            document.getElementById('modalContent').innerHTML = '<p>Failed to load grades.</p>';
        });
}

function saveModalGrade(btn) {
    var row = btn.closest('tr');
    var select = row.querySelector('.grade-select');
    if (!select) return;
    var ssId = parseInt(select.dataset.ssId);
    var periodId = parseInt(select.dataset.periodId);
    var grade = select.value;

    var formData = new FormData();
    formData.append('action', 'save_grade');
    formData.append('student_subject_id', ssId);
    formData.append('grading_period_id', periodId);
    formData.append('grade_value', grade);

    fetch('<?= h(app_url('registrar/grade_management.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { btn.textContent = 'Saved'; setTimeout(() => btn.textContent = 'Save', 1500); }
        else { alert(data.message); }
    }).catch(() => alert('Save failed.'));
}

function submitModalGrades(schedCode, periodId) {
    if (!confirm('Submit all draft grades for ' + schedCode + '?')) return;
    var formData = new FormData();
    formData.append('action', 'submit_grades');
    formData.append('sched_code', schedCode);
    formData.append('grading_period_id', periodId);

    fetch('<?= h(app_url('registrar/grade_management.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { alert(data.message); viewGrades(schedCode); }
        else { alert(data.message); }
    }).catch(() => alert('Submission failed.'));
}

function lockModalGrades(schedCode, periodId) {
    if (!confirm('Lock all submitted grades for ' + schedCode + '? This cannot be undone.')) return;
    var formData = new FormData();
    formData.append('action', 'lock_grades');
    formData.append('sched_code', schedCode);
    formData.append('grading_period_id', periodId);

    fetch('<?= h(app_url('registrar/grade_management.php')) ?>', {
        method: 'POST', body: formData
    }).then(r => r.json()).then(data => {
        if (data.success) { alert(data.message); viewGrades(schedCode); }
        else { alert(data.message); }
    }).catch(() => alert('Lock failed.'));
}

function closeModal() {
    document.getElementById('gradeModal').style.display = 'none';
}

function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }
function statusBadge(s) {
    return {draft:'secondary',submitted:'info',locked:'success',correction_pending:'warning',corrected:'success'}[s] || 'info';
}
function statusLabel(s) {
    return {draft:'Draft',submitted:'Submitted',locked:'Locked',correction_pending:'Correction Pending',corrected:'Corrected'}[s] || s;
}
</script>
<?php
render_page('Grade Management', 'Grade Management', (string) ob_get_clean());
