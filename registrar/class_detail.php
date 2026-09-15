<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar', 'chair', 'instructor']);

$schedCode = trim($_GET['code'] ?? '');
if ($schedCode === '') {
    redirect('registrar/schedule_codes.php');
}

$scheduleCode = fetch_one(
    'SELECT sc.id, sc.sched_code, sc.term_id, sc.program_id, sc.section_id, sc.subject_id, sc.curriculum_id,
            ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS term_label,
            sec.year_level, sec.section_name,
            p.program_code, p.program_name,
            sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
            d.dept_id, d.department_code, d.department_name
     FROM schedule_codes sc
     INNER JOIN academic_terms t ON t.id = sc.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     INNER JOIN sections sec ON sec.id = sc.section_id
     INNER JOIN programs p ON p.programs_id = sc.program_id
     INNER JOIN subjects sub ON sub.subject_id = sc.subject_id
     LEFT JOIN departments d ON d.dept_id = p.department_id
     WHERE sc.sched_code = :code',
    ['code' => $schedCode]
);

if ($scheduleCode === null) {
    flash('error', 'Schedule code not found.');
    redirect('registrar/schedule_codes.php');
}

$classSchedule = fetch_one(
    'SELECT cs.id, cs.day, cs.start_time, cs.end_time, cs.time_range, cs.room, cs.instructor_id,
            st.full_name AS instructor_name
     FROM class_schedules cs
     LEFT JOIN staff st ON st.staff_id = cs.instructor_id
     WHERE cs.schedule_code_id = :scid',
    ['scid' => $scheduleCode['id']]
);

$currentUser = current_user();
$role = $currentUser['role'] ?? '';
$staffId = (int) ($currentUser['staff_id'] ?? 0);

$enrolledCount = (int) (fetch_one(
    'SELECT COUNT(*) AS cnt FROM student_subjects WHERE schedule_code_id = :scid AND enrollment_status = "enrolled"',
    ['scid' => $scheduleCode['id']]
)['cnt'] ?? 0);

$instructors = fetch_all(
    'SELECT st.staff_id, st.full_name, d.department_code
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     ORDER BY st.full_name'
);

$rooms = fetch_all(
    'SELECT DISTINCT room FROM section_subject_offerings WHERE room IS NOT NULL AND room != \'\' ORDER BY room'
);

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_schedule') {
        $day = trim($_POST['day'] ?? '');
        $startTime = trim($_POST['start_time'] ?? '');
        $endTime = trim($_POST['end_time'] ?? '');
        $timeRange = trim($_POST['time_range'] ?? '');
        if ($timeRange === '' && $startTime !== '' && $endTime !== '') {
            $timeRange = date('h:i A', strtotime($startTime)) . ' — ' . date('h:i A', strtotime($endTime));
        }
        $room = trim($_POST['room'] ?? '');
        $instructorId = ($_POST['instructor_id'] ?? '') !== '' ? (int) $_POST['instructor_id'] : null;

        $scid = (int) $scheduleCode['id'];
        $existing = fetch_one('SELECT id FROM class_schedules WHERE schedule_code_id = :scid', ['scid' => $scid]);
        if ($existing !== null) {
            execute_sql(
                'UPDATE class_schedules SET day = :day, start_time = :st, end_time = :et, time_range = :tr, room = :room, instructor_id = :iid WHERE schedule_code_id = :scid',
                ['day' => $day, 'st' => $startTime, 'et' => $endTime, 'tr' => $timeRange, 'room' => $room, 'iid' => $instructorId, 'scid' => $scid]
            );
        } else {
            execute_sql(
                'INSERT INTO class_schedules (schedule_code_id, day, start_time, end_time, time_range, room, instructor_id, created_at) VALUES (:scid, :day, :st, :et, :tr, :room, :iid, NOW())',
                ['scid' => $scid, 'day' => $day, 'st' => $startTime, 'et' => $endTime, 'tr' => $timeRange, 'room' => $room, 'iid' => $instructorId]
            );
        }
        flash('success', 'Schedule saved.');
        redirect('registrar/class_detail.php?code=' . urlencode($schedCode) . '&tab=schedule');
    }

    if ($action === 'assign_instructor') {
        $instructorId = ($_POST['instructor_id'] ?? '') !== '' ? (int) $_POST['instructor_id'] : null;
        $scid = (int) $scheduleCode['id'];
        $existing = fetch_one('SELECT id FROM class_schedules WHERE schedule_code_id = :scid', ['scid' => $scid]);
        if ($existing !== null) {
            execute_sql('UPDATE class_schedules SET instructor_id = :iid WHERE schedule_code_id = :scid', ['iid' => $instructorId, 'scid' => $scid]);
        } else {
            execute_sql('INSERT INTO class_schedules (schedule_code_id, instructor_id, created_at) VALUES (:scid, :iid, NOW())', ['scid' => $scid, 'iid' => $instructorId]);
        }
        flash('success', 'Instructor assigned.');
        redirect('registrar/class_detail.php?code=' . urlencode($schedCode) . '&tab=schedule');
    }

    if ($action === 'save_grades') {
        $gradesData = json_decode($_POST['grades_data'] ?? '[]', true);
        if (is_array($gradesData)) {
            foreach ($gradesData as $g) {
                $ssid = (int) ($g['ss_id'] ?? 0);
                $final = trim($g['final'] ?? '');
                $remarks = trim($g['remarks'] ?? '');
                if ($ssid > 0) {
                    execute_sql(
                        'UPDATE student_subjects SET final_grade = :final, remarks = :remarks WHERE id = :ssid',
                        ['final' => $final !== '' ? $final : null, 'remarks' => $remarks !== '' ? $remarks : null, 'ssid' => $ssid]
                    );
                }
            }
        }
        flash('success', 'Grades saved.');
        redirect('registrar/class_detail.php?code=' . urlencode($schedCode) . '&tab=grades');
    }
}

// Handle AJAX get_grades (for grade management modal)
if (is_get() && ($_GET['action'] ?? '') === 'get_grades') {
    header('Content-Type: application/json');
    $code = trim($_GET['sched_code'] ?? '');
    $periodId = (int) ($_GET['period_id'] ?? 0);
    if ($code === '' || $periodId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing parameters.']);
        exit;
    }
    $grades = GradingEngine::getGradesForSchedCode($code, $periodId);
    echo json_encode(['success' => true, 'grades' => $grades]);
    exit;
}

$tab = trim($_GET['tab'] ?? 'students');

$students = [];
$grades = [];
if ($tab === 'students' || $tab === 'grades') {
    $students = fetch_all(
        'SELECT ss.id AS ss_id, ss.enrollment_status,
                stu.student_number, CONCAT(stu.first_name, \' \', IFNULL(stu.middle_name, \'\'), \' \', stu.last_name) AS student_name
         FROM student_subjects ss
         INNER JOIN students stu ON stu.id = ss.student_id
         WHERE ss.schedule_code_id = :scid AND ss.enrollment_status = "enrolled"
         ORDER BY stu.last_name, stu.first_name',
        ['scid' => $scheduleCode['id']]
    );
}

if ($tab === 'grades') {
    $grades = fetch_all(
        'SELECT ss.id AS ss_id, ss.final_grade, ss.remarks, ss.enrollment_status,
                stu.student_number, CONCAT(stu.first_name, \' \', IFNULL(stu.middle_name, \'\'), \' \', stu.last_name) AS student_name
         FROM student_subjects ss
         INNER JOIN students stu ON stu.id = ss.student_id
         WHERE ss.schedule_code_id = :scid
         ORDER BY stu.last_name, stu.first_name',
        ['scid' => $scheduleCode['id']]
    );
}

$timeDisplay = '—';
$instructorName = '—';
$roomDisplay = '—';
$dayDisplay = '—';
if ($classSchedule !== null) {
    $instructorName = $classSchedule['instructor_name'] ?? '—';
    $roomDisplay = $classSchedule['room'] ?? '—';
    $dayDisplay = $classSchedule['day'] ?? '—';
    if ($classSchedule['start_time'] && $classSchedule['end_time']) {
        $timeDisplay = date('h:i A', strtotime($classSchedule['start_time'])) . ' — ' . date('h:i A', strtotime($classSchedule['end_time']));
    } elseif ($classSchedule['time_range']) {
        $timeDisplay = $classSchedule['time_range'];
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Class Details — <span class="badge" style="font-family:monospace;"><?= h($scheduleCode['sched_code']) ?></span></h1>
        <p><?= h($scheduleCode['subject_code'] . ' — ' . $scheduleCode['subject_description']) ?></p>
    </div>
    <a class="btn secondary" href="<?= h(app_url('registrar/schedule_codes.php?term_id=' . $scheduleCode['term_id'] . '&program_id=' . $scheduleCode['program_id'])) ?>">← Back to Schedule Codes</a>
</div>

<div class="info-cards" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px;">
    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:28px;font-weight:700;color:var(--primary);"><?= h((string)$enrolledCount) ?></div>
        <div class="helper" style="margin-top:4px;">Total Enrolled Students</div>
    </div>
    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:16px;font-weight:600;"><?= h($instructorName) ?></div>
        <div class="helper" style="margin-top:4px;">Instructor Assigned</div>
    </div>
    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:16px;font-weight:600;"><?= h($dayDisplay !== '—' ? substr($dayDisplay, 0, 1) . ' ' . $timeDisplay : '—') ?></div>
        <div class="helper" style="margin-top:4px;">Class Schedule</div>
    </div>
    <div class="card" style="text-align:center;padding:16px;">
        <div style="font-size:16px;font-weight:600;"><?= h($roomDisplay) ?></div>
        <div class="helper" style="margin-top:4px;">Room</div>
    </div>
</div>

<div class="info-grid card" style="margin-bottom:16px;">
    <div class="info-item"><strong>Program:</strong> <?= h($scheduleCode['program_code'] . ' — ' . $scheduleCode['program_name']) ?></div>
    <div class="info-item"><strong>Year Level:</strong> <?= h((string)$scheduleCode['year_level']) ?></div>
    <div class="info-item"><strong>Section:</strong> <?= h($scheduleCode['program_code'] . ' ' . $scheduleCode['year_level'] . $scheduleCode['section_name']) ?></div>
    <div class="info-item"><strong>Units:</strong> <?= h((string)$scheduleCode['units']) ?></div>
    <div class="info-item"><strong>Term:</strong> <?= h($scheduleCode['term_label']) ?></div>
    <div class="info-item"><strong>Department:</strong> <?= h($scheduleCode['department_code'] ?? '—') ?></div>
</div>

<div class="tabs" style="margin-bottom:16px;">
    <a class="tab <?= $tab === 'students' ? 'active' : '' ?>" href="?code=<?= h(urlencode($schedCode)) ?>&tab=students">Enrolled Students</a>
    <a class="tab <?= $tab === 'schedule' ? 'active' : '' ?>" href="?code=<?= h(urlencode($schedCode)) ?>&tab=schedule">Class Schedule</a>
    <a class="tab <?= $tab === 'grades' ? 'active' : '' ?>" href="?code=<?= h(urlencode($schedCode)) ?>&tab=grades">Grades</a>
</div>

<?php if ($tab === 'students'): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h3 style="margin:0;">Enrolled Students (<?= count($students) ?>)</h3>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="btn small secondary" onclick="window.print()">Print Class List</button>
            <button class="btn small secondary" onclick="exportTableToCSV('class-list.csv')">Export CSV</button>
        </div>
    </div>

    <div style="display:flex;gap:8px;margin-bottom:12px;flex-wrap:wrap;">
        <input type="text" id="student-search" placeholder="Search by name or student no..." style="flex:1;min-width:200px;padding:8px;">
        <select id="status-filter" style="padding:8px;">
            <option value="">All Status</option>
            <option value="enrolled">Enrolled</option>
            <option value="completed">Completed</option>
            <option value="dropped">Dropped</option>
        </select>
    </div>

    <div class="table-wrap">
        <table id="students-table">
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Student Name</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($students as $s): ?>
                <tr class="student-row" data-status="<?= h($s['enrollment_status']) ?>">
                    <td><span class="badge" style="font-family:monospace;"><?= h($s['student_number'] ?? '—') ?></span></td>
                    <td data-name="<?= h(strtolower($s['student_name'])) ?>"><?= h($s['student_name']) ?></td>
                    <td><span class="badge <?= $s['enrollment_status'] === 'enrolled' ? 'success' : ($s['enrollment_status'] === 'dropped' ? 'danger' : 'secondary') ?>"><?= h(ucfirst($s['enrollment_status'])) ?></span></td>
                </tr>
                <?php endforeach; ?>
                <?php if ($students === []): ?>
                <tr><td colspan="3" class="helper" style="text-align:center;">No enrolled students.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.getElementById('student-search')?.addEventListener('input', filterStudents);
document.getElementById('status-filter')?.addEventListener('change', filterStudents);

function filterStudents() {
    var q = document.getElementById('student-search')?.value.toLowerCase() || '';
    var status = document.getElementById('status-filter')?.value || '';
    document.querySelectorAll('#students-table .student-row').forEach(function (row) {
        var name = row.querySelector('td[data-name]')?.getAttribute('data-name') || '';
        var rowStatus = row.getAttribute('data-status') || '';
        var matchName = name.includes(q);
        var matchStatus = status === '' || rowStatus === status;
        row.style.display = matchName && matchStatus ? '' : 'none';
    });
}

function exportTableToCSV(filename) {
    var csv = [];
    var rows = document.querySelectorAll('#students-table tr');
    for (var i = 0; i < rows.length; i++) {
        if (rows[i].style.display === 'none') continue;
        var row = [], cols = rows[i].querySelectorAll('td, th');
        for (var j = 0; j < cols.length; j++) {
            row.push('"' + cols[j].innerText.replace(/"/g, '""') + '"');
        }
        csv.push(row.join(','));
    }
    var csvFile = new Blob([csv.join('\n')], {type: 'text/csv'});
    var link = document.createElement('a');
    link.download = filename;
    link.href = URL.createObjectURL(csvFile);
    link.click();
}
</script>

<?php elseif ($tab === 'schedule'): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h3 style="margin:0;">Class Schedule</h3>
        <div style="display:flex;gap:6px;">
            <button class="btn small secondary" onclick="toggleEditInstructor()">Assign / Change Instructor</button>
            <button class="btn small" onclick="toggleEditSchedule()">Edit Schedule</button>
        </div>
    </div>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Day</th>
                    <th>Time</th>
                    <th>Room</th>
                    <th>Instructor</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($dayDisplay !== '—'): ?>
                <tr>
                    <td><?= h($dayDisplay) ?></td>
                    <td><?= h($timeDisplay) ?></td>
                    <td><?= h($roomDisplay) ?></td>
                    <td><?= h($instructorName) ?></td>
                </tr>
                <?php else: ?>
                <tr><td colspan="4" class="helper" style="text-align:center;">No schedule assigned yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div id="edit-schedule-form" style="display:none;margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
        <h4>Edit Schedule</h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save_schedule">
            <div class="filter-bar">
                <div>
                    <label>Day</label>
                    <select name="day">
                        <option value="">— Select —</option>
                        <?php foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d): ?>
                            <option value="<?= h($d) ?>" <?= ($classSchedule['day'] ?? '') === $d ? 'selected' : '' ?>><?= h($d) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Start Time</label>
                    <input type="time" name="start_time" value="<?= h($classSchedule['start_time'] ?? '') ?>">
                </div>
                <div>
                    <label>End Time</label>
                    <input type="time" name="end_time" value="<?= h($classSchedule['end_time'] ?? '') ?>">
                </div>
                <div>
                    <label>Room</label>
                    <div style="display:flex;gap:4px;">
                        <input type="text" name="room" id="room-input" value="<?= h($classSchedule['room'] ?? '') ?>" list="room-list" placeholder="e.g. Rm 301" style="flex:1;">
                        <datalist id="room-list">
                            <?php foreach ($rooms as $r): ?>
                            <option value="<?= h($r['room']) ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>
                </div>
            </div>
            <div style="margin-top:10px;">
                <button class="btn" type="submit">Save Schedule</button>
            </div>
        </form>
    </div>

    <div id="assign-instructor-form" style="display:none;margin-top:16px;border-top:1px solid var(--border);padding-top:16px;">
        <h4>Assign / Change Instructor</h4>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="assign_instructor">
            <div class="filter-bar">
                <div>
                    <label>Instructor</label>
                    <select name="instructor_id" style="min-width:280px;">
                        <option value="">— TBA —</option>
                        <?php foreach ($instructors as $i): ?>
                            <option value="<?= h((string)$i['staff_id']) ?>" <?= (int)($classSchedule['instructor_id'] ?? 0) === (int)$i['staff_id'] ? 'selected' : '' ?>><?= h($i['full_name'] . ' [' . ($i['department_code'] ?? '') . ']') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-top:10px;">
                <button class="btn" type="submit">Save Instructor</button>
            </div>
        </form>
    </div>
</div>

<script>
function toggleEditSchedule() {
    var el = document.getElementById('edit-schedule-form');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    document.getElementById('assign-instructor-form').style.display = 'none';
}
function toggleEditInstructor() {
    var el = document.getElementById('assign-instructor-form');
    el.style.display = el.style.display === 'none' ? 'block' : 'none';
    document.getElementById('edit-schedule-form').style.display = 'none';
}
</script>

<?php elseif ($tab === 'grades'): ?>
<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:12px;">
        <h3 style="margin:0;">Grading Sheet — <?= h($scheduleCode['subject_code']) ?></h3>
        <?php if ($role === 'instructor'): ?>
        <button class="btn small" id="save-grades-btn" onclick="saveGrades()">Save Grades</button>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table id="grades-table">
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Student Name</th>
                    <th>Midterm</th>
                    <th>Final</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($grades as $g): ?>
                <tr data-ssid="<?= h((string)$g['ss_id']) ?>">
                    <td><span class="badge" style="font-family:monospace;"><?= h($g['student_number'] ?? '—') ?></span></td>
                    <td><?= h($g['student_name']) ?></td>
                    <?php if ($role === 'instructor'): ?>
                    <td><input type="text" class="grade-input" data-field="midterm" value="" placeholder="—" style="width:70px;"></td>
                    <td><input type="text" class="grade-input" data-field="final" value="<?= h($g['final_grade'] ?? '') ?>" placeholder="—" style="width:70px;"></td>
                    <td><input type="text" class="grade-input" data-field="remarks" value="<?= h($g['remarks'] ?? '') ?>" placeholder="—" style="width:120px;"></td>
                    <?php else: ?>
                    <td style="text-align:center;">—</td>
                    <td style="text-align:center;"><?= h($g['final_grade'] ?? '—') ?></td>
                    <td><?= h($g['remarks'] ?? '—') ?></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($grades === []): ?>
                <tr><td colspan="5" class="helper" style="text-align:center;">No students enrolled.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <p class="helper" style="margin-top:10px;">
        <?= $role === 'instructor' ? 'You are the assigned instructor. You may encode and submit grades for this class.' : ($role === 'chair' || $role === 'admin' || $role === 'registrar' ? 'View-only access. Only the assigned instructor can encode and submit grades.' : '') ?>
    </p>
</div>

<?php if ($role === 'instructor'): ?>
<form id="grades-form" method="post" style="display:none;">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_grades">
    <input type="hidden" name="grades_data" id="grades-data" value="">
</form>
<script>
function saveGrades() {
    var data = [];
    document.querySelectorAll('#grades-table tbody tr[data-ssid]').forEach(function (row) {
        var ssid = row.getAttribute('data-ssid');
        var inputs = row.querySelectorAll('.grade-input');
        var rowData = {ss_id: ssid};
        inputs.forEach(function (inp) {
            rowData[inp.getAttribute('data-field')] = inp.value;
        });
        data.push(rowData);
    });
    document.getElementById('grades-data').value = JSON.stringify(data);
    document.getElementById('grades-form').submit();
}
</script>
<?php endif; ?>

<?php endif; ?>

<?php
render_page('Class Details — ' . $scheduleCode['sched_code'], 'Class Details', (string) ob_get_clean());
