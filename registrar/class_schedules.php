<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_role(['admin', 'registrar']);

$staff = current_staff();
$staffId = (int) ($staff['staff_id'] ?? 0);

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'approve_schedule') {
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            $row = fetch_one('SELECT id, status FROM class_schedules WHERE id = :id', ['id' => $scheduleId]);
            if ($row !== null && $row['status'] === 'submitted') {
                execute_sql(
                    'UPDATE class_schedules SET status = "approved", approved_by = :staff_id, approved_at = NOW() WHERE id = :id',
                    ['staff_id' => $staffId, 'id' => $scheduleId]
                );
                flash('success', 'Schedule approved.');
            } else {
                flash('error', 'Schedule cannot be approved.');
            }
        }
        redirect('registrar/class_schedules.php');
    }

    if ($action === 'reject_schedule') {
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        $rejectionReason = trim($_POST['rejection_reason'] ?? '');
        if ($scheduleId > 0) {
            $row = fetch_one('SELECT id, status FROM class_schedules WHERE id = :id', ['id' => $scheduleId]);
            if ($row !== null && $row['status'] === 'submitted') {
                execute_sql(
                    'UPDATE class_schedules SET status = "rejected", approved_by = :staff_id, approved_at = NOW(), rejection_reason = :reason WHERE id = :id',
                    ['staff_id' => $staffId, 'reason' => $rejectionReason, 'id' => $scheduleId]
                );
                flash('success', 'Schedule rejected.');
            } else {
                flash('error', 'Schedule cannot be rejected.');
            }
        }
        redirect('registrar/class_schedules.php');
    }

    if ($action === 'cancel_schedule') {
        $scheduleId = (int) ($_POST['schedule_id'] ?? 0);
        if ($scheduleId > 0) {
            $row = fetch_one('SELECT id, status FROM class_schedules WHERE id = :id', ['id' => $scheduleId]);
            if ($row !== null && $row['status'] !== 'cancelled') {
                execute_sql(
                    'UPDATE class_schedules SET status = "cancelled" WHERE id = :id',
                    ['id' => $scheduleId]
                );
                flash('success', 'Schedule cancelled.');
            } else {
                flash('error', 'Schedule is already cancelled.');
            }
        }
        redirect('registrar/class_schedules.php');
    }
}

$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label,
            t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$departments = fetch_all(
    'SELECT dept_id, department_code, department_name FROM departments ORDER BY department_code'
);

$programs = fetch_all(
    'SELECT p.programs_id, p.program_code, p.program_name, d.department_code, d.dept_id
     FROM programs p
     INNER JOIN departments d ON d.dept_id = p.department_id
     ORDER BY d.department_code, p.program_code'
);

$sections = fetch_all(
    'SELECT sec.id, sec.program_id, sec.year_level, sec.section_name,
            p.program_code, d.department_code,
            CONCAT(p.program_code, " ", sec.year_level, sec.section_name) AS label
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN departments d ON d.dept_id = p.department_id
     WHERE COALESCE(sec.status, "active") = "active"
     ORDER BY d.department_code, p.program_code, sec.year_level, sec.section_name'
);

$filterTerm = (int) ($_GET['term_id'] ?? 0);
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterDepartment = (int) ($_GET['department_id'] ?? 0);
$filterYearLevel = (int) ($_GET['year_level'] ?? 0);
$filterSection = (int) ($_GET['section_id'] ?? 0);
$filterStatus = trim($_GET['status'] ?? '');
$searchQuery = trim($_GET['search'] ?? '');

$validStatuses = ['draft', 'submitted', 'approved', 'rejected', 'cancelled'];
if ($filterStatus !== '' && !in_array($filterStatus, $validStatuses, true)) {
    $filterStatus = '';
}

$sql = 'SELECT cs.id, cs.day, cs.start_time, cs.end_time, cs.time_range, cs.room,
               cs.status, cs.submitted_at, cs.approved_by, cs.approved_at, cs.rejection_reason, cs.created_at,
               COALESCE(sc.sched_code, cs.schedule_code) AS sched_code,
               sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
               sec.id AS section_id, sec.year_level, sec.section_name,
               p.programs_id, p.program_code, p.program_name,
               d.dept_id, d.department_code,
               st_inst.full_name AS instructor_name,
               st_create.full_name AS created_by_name,
               st_approve.full_name AS approved_by_name
        FROM class_schedules cs
        INNER JOIN subjects sub ON sub.subject_id = cs.subject_id
        INNER JOIN sections sec ON sec.id = cs.section_id
        INNER JOIN programs p ON p.programs_id = sec.program_id
        INNER JOIN departments d ON d.dept_id = p.department_id
        LEFT JOIN schedule_codes sc ON sc.id = cs.schedule_code_id
        LEFT JOIN staff st_inst ON st_inst.staff_id = cs.instructor_id
        LEFT JOIN staff st_create ON st_create.staff_id = cs.created_by
        LEFT JOIN staff st_approve ON st_approve.staff_id = cs.approved_by
        WHERE 1=1';
$params = [];

if ($filterTerm > 0) {
    $sql .= ' AND cs.term_id = :term_id';
    $params['term_id'] = $filterTerm;
}
if ($filterProgram > 0) {
    $sql .= ' AND p.programs_id = :program_id';
    $params['program_id'] = $filterProgram;
}
if ($filterDepartment > 0) {
    $sql .= ' AND d.dept_id = :dept_id';
    $params['dept_id'] = $filterDepartment;
}
if ($filterYearLevel > 0) {
    $sql .= ' AND sec.year_level = :year_level';
    $params['year_level'] = $filterYearLevel;
}
if ($filterSection > 0) {
    $sql .= ' AND sec.id = :section_id';
    $params['section_id'] = $filterSection;
}
if ($filterStatus !== '') {
    $sql .= ' AND cs.status = :status';
    $params['status'] = $filterStatus;
}
if ($searchQuery !== '') {
    $sql .= ' AND (sc.sched_code LIKE :search OR sub.subject_code LIKE :search2 OR sub.subject_description LIKE :search3
                   OR st_inst.full_name LIKE :search4 OR cs.room LIKE :search5)';
    $searchLike = '%' . $searchQuery . '%';
    $params['search'] = $searchLike;
    $params['search2'] = $searchLike;
    $params['search3'] = $searchLike;
    $params['search4'] = $searchLike;
    $params['search5'] = $searchLike;
}

$sql .= ' ORDER BY cs.id DESC';
$schedules = fetch_all($sql, $params);

$countSql = 'SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN cs.status = "draft" THEN 1 ELSE 0 END) AS draft_count,
                SUM(CASE WHEN cs.status = "submitted" THEN 1 ELSE 0 END) AS submitted_count,
                SUM(CASE WHEN cs.status = "approved" THEN 1 ELSE 0 END) AS approved_count,
                SUM(CASE WHEN cs.status = "rejected" THEN 1 ELSE 0 END) AS rejected_count
             FROM class_schedules cs
             INNER JOIN subjects sub ON sub.subject_id = cs.subject_id
             INNER JOIN sections sec ON sec.id = cs.section_id
             INNER JOIN programs p ON p.programs_id = sec.program_id
             INNER JOIN departments d ON d.dept_id = p.department_id
             WHERE 1=1';
$countParams = [];
if ($filterTerm > 0) {
    $countSql .= ' AND cs.term_id = :term_id';
    $countParams['term_id'] = $filterTerm;
}
if ($filterProgram > 0) {
    $countSql .= ' AND p.programs_id = :program_id';
    $countParams['program_id'] = $filterProgram;
}
if ($filterDepartment > 0) {
    $countSql .= ' AND d.dept_id = :dept_id';
    $countParams['dept_id'] = $filterDepartment;
}
if ($filterYearLevel > 0) {
    $countSql .= ' AND sec.year_level = :year_level';
    $countParams['year_level'] = $filterYearLevel;
}
if ($filterSection > 0) {
    $countSql .= ' AND sec.id = :section_id';
    $countParams['section_id'] = $filterSection;
}
if ($filterStatus !== '') {
    $countSql .= ' AND cs.status = :status';
    $countParams['status'] = $filterStatus;
}
if ($searchQuery !== '') {
    $countSql .= ' AND (sc.sched_code LIKE :search OR sub.subject_code LIKE :search2 OR sub.subject_description LIKE :search3
                       OR st_inst.full_name LIKE :search4 OR cs.room LIKE :search5)';
    $searchLike = '%' . $searchQuery . '%';
    $countParams['search'] = $searchLike;
    $countParams['search2'] = $searchLike;
    $countParams['search3'] = $searchLike;
    $countParams['search4'] = $searchLike;
    $countParams['search5'] = $searchLike;
}

$countRow = fetch_one($countSql, $countParams);
$totalCount = (int) ($countRow['total'] ?? 0);
$draftCount = (int) ($countRow['draft_count'] ?? 0);
$submittedCount = (int) ($countRow['submitted_count'] ?? 0);
$approvedCount = (int) ($countRow['approved_count'] ?? 0);
$rejectedCount = (int) ($countRow['rejected_count'] ?? 0);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Class Schedules</h1>
        <p>Review, approve, or reject submitted class schedules across all programs.</p>
    </div>
</div>

<div class="grid" style="margin-bottom:16px;grid-template-columns:repeat(5,minmax(0,1fr));">
    <div class="card slim">
        <div class="metric-label">Total</div>
        <div class="metric"><?= h((string) $totalCount) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Draft</div>
        <div class="metric" style="color:var(--info);"><?= h((string) $draftCount) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Submitted</div>
        <div class="metric" style="color:var(--warning);"><?= h((string) $submittedCount) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Approved</div>
        <div class="metric" style="color:var(--success);"><?= h((string) $approvedCount) ?></div>
    </div>
    <div class="card slim">
        <div class="metric-label">Rejected</div>
        <div class="metric" style="color:var(--danger);"><?= h((string) $rejectedCount) ?></div>
    </div>
</div>

<div class="card" style="margin-bottom:16px;">
    <form method="get">
        <div class="filter-bar">
            <div>
                <label>Academic Term</label>
                <select name="term_id">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= h((string) $t['id']) ?>" <?= $filterTerm === (int) $t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?><?= (int) $t['is_active'] === 1 ? ' (Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Department</label>
                <select name="department_id">
                    <option value="">All Departments</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= h((string) $d['dept_id']) ?>" <?= $filterDepartment === (int) $d['dept_id'] ? 'selected' : '' ?>><?= h($d['department_code'] . ' — ' . $d['department_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Program</label>
                <select name="program_id">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h((string) $p['programs_id']) ?>" <?= $filterProgram === (int) $p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Year Level</label>
                <select name="year_level">
                    <option value="">All Years</option>
                    <?php foreach ([1, 2, 3, 4, 5] as $yr): ?>
                        <option value="<?= $yr ?>" <?= $filterYearLevel === $yr ? 'selected' : '' ?>><?= $yr ?><?= $yr === 1 ? 'st' : ($yr === 2 ? 'nd' : ($yr === 3 ? 'rd' : 'th')) ?> Year</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Section</label>
                <select name="section_id">
                    <option value="">All Sections</option>
                    <?php foreach ($sections as $s): ?>
                        <option value="<?= h((string) $s['id']) ?>" <?= $filterSection === (int) $s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Status</label>
                <select name="status">
                    <option value="">All Status</option>
                    <?php foreach ($validStatuses as $sv): ?>
                        <option value="<?= h($sv) ?>" <?= $filterStatus === $sv ? 'selected' : '' ?>><?= h(ucfirst($sv)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Search</label>
                <input type="text" name="search" value="<?= h($searchQuery) ?>" placeholder="Code, subject, instructor...">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn secondary" type="submit">Filter</button>
            <?php if ($filterTerm > 0 || $filterProgram > 0 || $filterDepartment > 0 || $filterYearLevel > 0 || $filterSection > 0 || $filterStatus !== '' || $searchQuery !== ''): ?>
                <a class="btn small secondary" href="<?= h(app_url('registrar/class_schedules.php')) ?>">Clear Filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="card">
    <?php if ($schedules === []): ?>
        <p class="helper" style="text-align:center;padding:32px;">No class schedules found.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Course</th>
                    <th>Description</th>
                    <th>Units</th>
                    <th>Section</th>
                    <th>Instructor</th>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Room</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Submitted</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($schedules as $s): ?>
                <tr>
                    <td><span class="badge" style="font-family:monospace;"><?= h($s['sched_code']) ?></span></td>
                    <td><span class="badge"><?= h($s['subject_code']) ?></span></td>
                    <td><?= h($s['subject_description']) ?></td>
                    <td><?= h((string) $s['units']) ?></td>
                    <td><?= h($s['program_code'] . ' ' . $s['year_level'] . $s['section_name']) ?></td>
                    <td><?= h($s['instructor_name'] ?? '—') ?></td>
                    <td><?= h($s['day'] ?? '—') ?></td>
                    <td>
                        <?php if (!empty($s['start_time']) && !empty($s['end_time'])): ?>
                            <?= h(format_time_12h($s['start_time'])) ?> — <?= h(format_time_12h($s['end_time'])) ?>
                        <?php else: ?>
                            <?= h($s['time_range'] ?? '—') ?>
                        <?php endif; ?>
                    </td>
                    <td><?= h($s['room'] ?? '—') ?></td>
                    <td><span class="badge <?= h(schedule_status_badge_class($s['status'])) ?>"><?= h(ucfirst($s['status'])) ?></span></td>
                    <td><?= h($s['created_by_name'] ?? '—') ?></td>
                    <td><?= $s['submitted_at'] ? h(date('M j, Y g:i A', strtotime($s['submitted_at']))) : '—' ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($s['status'] === 'submitted'): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Approve this schedule?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="approve_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= h((string) $s['id']) ?>">
                                    <button class="btn small success" type="submit">Approve</button>
                                </form>
                                <button class="btn small danger" type="button" onclick="openRejectModal(<?= h((string) $s['id']) ?>)">Reject</button>
                            <?php endif; ?>
                            <?php if ($s['status'] !== 'cancelled'): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Cancel this schedule?');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="cancel_schedule">
                                    <input type="hidden" name="schedule_id" value="<?= h((string) $s['id']) ?>">
                                    <button class="btn small secondary" type="submit">Cancel</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?= render_modal('rejectScheduleModal', 'Reject Schedule', '
<form method="post">
    ' . csrf_field() . '
    <input type="hidden" name="action" value="reject_schedule">
    <input type="hidden" name="schedule_id" id="reject_schedule_id">
    <div>
        <label>Rejection Reason</label>
        <textarea name="rejection_reason" id="reject_reason" rows="4" placeholder="Enter reason for rejection..." required></textarea>
    </div>
    <div class="form-actions" style="margin-top:12px;">
        <button class="btn danger" type="submit">Reject Schedule</button>
    </div>
</form>
') ?>

<?php
$content = ob_get_clean();
render_page('Class Schedules', 'Class Schedules', (string) $content);
?>
<script>
function openRejectModal(id) {
    document.getElementById('reject_schedule_id').value = id;
    document.getElementById('reject_reason').value = '';
    document.getElementById('rejectScheduleModal').classList.add('active');
}
</script>
