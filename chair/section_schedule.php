<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
$user = require_role(['chair', 'admin', 'registrar']);

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('index.php');
}

$currentUser = current_user();
$isChair = ($currentUser['role'] ?? '') === 'chair';

$sectionId = (int) ($_GET['section_id'] ?? 0);
$termId    = (int) ($_GET['term_id'] ?? 0);

$section = $sectionId > 0 ? fetch_one(
    'SELECT sec.*, p.program_code, p.program_name, p.department_id
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     WHERE sec.id = :id',
    ['id' => $sectionId]
) : null;

if ($section === null || ($isChair && (int) $section['department_id'] !== (int) ($staff['dept_id'] ?? 0))) {
    flash('error', 'Section not found or not in your department.');
    redirect('chair/class_schedules.php');
}

if ($termId <= 0) {
    $term = current_term();
    $termId = (int) ($term['id'] ?? 0);
}

$term = fetch_one(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE t.id = :id',
    ['id' => $termId]
);
$termLabel = $term['label'] ?? '';
$sectionLabel = $section['program_code'] . ' ' . $section['year_level'] . $section['section_name'];

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_schedule') {
        $subjectId    = (int) ($_POST['subject_id'] ?? 0);
        $instructorId = (int) ($_POST['instructor_id'] ?? 0);
        $instructorDb = $instructorId > 0 ? $instructorId : null;
        $day          = trim($_POST['day'] ?? '');
        $timeStart    = trim($_POST['start_time'] ?? '');
        $timeEnd      = trim($_POST['end_time'] ?? '');
        $room         = trim($_POST['room'] ?? '');
        $schedType    = trim($_POST['schedule_type'] ?? 'LEC');
        if (!in_array($schedType, ['LEC', 'LAB', 'ASYNC'], true)) $schedType = 'LEC';

        if ($subjectId <= 0 || $day === '' || $timeStart === '' || $timeEnd === '' || $room === '') {
            flash('error', 'Please fill in all required fields.');
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        if (time_to_minutes($timeStart) >= time_to_minutes($timeEnd)) {
            flash('error', 'Time start must be before time end.');
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        $conflictData = [
            'section_id'    => $sectionId,
            'instructor_id' => $instructorId > 0 ? $instructorId : null,
            'day'           => $day,
            'time_start'    => $timeStart,
            'time_end'      => $timeEnd,
            'room'          => $room,
            'term_id'       => $termId,
        ];
        $conflicts = find_class_schedule_conflicts($conflictData);

        if ($conflicts !== []) {
            foreach ($conflicts as $c) { flash('error', $c); }
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        $offeringCode = fetch_one(
            'SELECT sched_code FROM section_subject_offerings WHERE subject_id = :subject_id AND section_id = :section_id AND term_id = :term_id LIMIT 1',
            ['subject_id' => $subjectId, 'section_id' => $sectionId, 'term_id' => $termId]
        );
        $scheduleCode = $offeringCode ? $offeringCode['sched_code'] : generate_class_schedule_code($termId, (int) $section['program_id']);
        $timeRange = format_time_12h($timeStart) . ' – ' . format_time_12h($timeEnd);

        execute_sql(
            'INSERT INTO class_schedules (schedule_code, schedule_type, subject_id, section_id, term_id, instructor_id, day, start_time, end_time, time_range, room, status, created_by, created_at)
             VALUES (:code, :sched_type, :subject_id, :section_id, :term_id, :instructor_id, :day, :time_start, :time_end, :time_range, :room, "draft", :created_by, NOW())',
            [
                'code'          => $scheduleCode,
                'sched_type'    => $schedType,
                'subject_id'    => $subjectId,
                'section_id'    => $sectionId,
                'term_id'       => $termId,
                'instructor_id' => $instructorDb,
                'day'           => $day,
                'time_start'    => $timeStart,
                'time_end'      => $timeEnd,
                'time_range'    => $timeRange,
                'room'          => $room,
                'created_by'    => (int) $staff['staff_id'],
            ]
        );

        if ($instructorDb !== null) {
            execute_sql(
                'UPDATE section_subject_offerings SET instructor_id = :iid WHERE subject_id = :sid AND section_id = :secid AND term_id = :tid',
                ['iid' => $instructorDb, 'sid' => $subjectId, 'secid' => $sectionId, 'tid' => $termId]
            );
        }

        flash('success', 'Schedule added for ' . ($_POST['subject_code'] ?? 'subject') . '.');
        redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
    }

    if ($action === 'submit_schedule') {
        $schedId = (int) ($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $sched = fetch_one('SELECT * FROM class_schedules WHERE id = :id AND section_id = :sid', ['id' => $schedId, 'sid' => $sectionId]);
            if ($sched && $sched['status'] === 'draft') {
                execute_sql('UPDATE class_schedules SET status = "submitted", submitted_at = NOW() WHERE id = :id', ['id' => $schedId]);
                flash('success', 'Schedule submitted for review.');
            } else {
                flash('error', 'Only draft schedules can be submitted.');
            }
        }
        redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
    }

    if ($action === 'submit_all_drafts') {
        execute_sql(
            'UPDATE class_schedules SET status = "submitted", submitted_at = NOW()
             WHERE section_id = :sid AND term_id = :tid AND status = "draft"',
            ['sid' => $sectionId, 'tid' => $termId]
        );
        flash('success', 'All draft schedules submitted for review.');
        redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
    }

    if ($action === 'delete_schedule') {
        $schedId = (int) ($_POST['schedule_id'] ?? 0);
        if ($schedId > 0) {
            $schedCode = fetch_one('SELECT schedule_code FROM class_schedules WHERE id = :id', ['id' => $schedId]);
            if ($schedCode && !empty($schedCode['schedule_code'])) {
                $referenced = fetch_one(
                    'SELECT 1 FROM enrollment_request_items WHERE schedule_code = :sc LIMIT 1',
                    ['sc' => $schedCode['schedule_code']]
                );
                if ($referenced) {
                    flash('error', 'Cannot delete this schedule — it is referenced by enrollment request(s).');
                    redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
                }
            }
            execute_sql('DELETE FROM class_schedules WHERE id = :id AND section_id = :sid AND status = "draft"', ['id' => $schedId, 'sid' => $sectionId]);
            flash('success', 'Schedule deleted.');
        }
        redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
    }

    if ($action === 'edit_schedule') {
        $schedId      = (int) ($_POST['schedule_id'] ?? 0);
        $instructorId = (int) ($_POST['instructor_id'] ?? 0);
        $instructorDb = $instructorId > 0 ? $instructorId : null;
        $day          = trim($_POST['day'] ?? '');
        $timeStart    = trim($_POST['start_time'] ?? '');
        $timeEnd      = trim($_POST['end_time'] ?? '');
        $room         = trim($_POST['room'] ?? '');
        $schedType    = trim($_POST['schedule_type'] ?? 'LEC');
        if (!in_array($schedType, ['LEC', 'LAB', 'ASYNC'], true)) $schedType = 'LEC';

        if ($schedId <= 0 || $day === '' || $timeStart === '' || $timeEnd === '' || $room === '') {
            flash('error', 'Please fill in all required fields.');
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        if (time_to_minutes($timeStart) >= time_to_minutes($timeEnd)) {
            flash('error', 'Time start must be before time end.');
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        $existing = fetch_one('SELECT * FROM class_schedules WHERE id = :id AND section_id = :sid', ['id' => $schedId, 'sid' => $sectionId]);
        if ($existing === null) {
            flash('error', 'Schedule not found.');
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        $conflictData = [
            'section_id'    => $sectionId,
            'instructor_id' => $instructorId > 0 ? $instructorId : null,
            'day'           => $day,
            'time_start'    => $timeStart,
            'time_end'      => $timeEnd,
            'room'          => $room,
            'term_id'       => $termId,
        ];
        $conflicts = find_class_schedule_conflicts($conflictData, $schedId);

        if ($conflicts !== []) {
            foreach ($conflicts as $c) { flash('error', $c); }
            redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
        }

        $timeRange = format_time_12h($timeStart) . ' – ' . format_time_12h($timeEnd);

        execute_sql(
            'UPDATE class_schedules SET instructor_id = :instructor_id, day = :day, start_time = :time_start, end_time = :time_end, time_range = :time_range, room = :room, schedule_type = :sched_type WHERE id = :id',
            [
                'instructor_id' => $instructorDb,
                'day'           => $day,
                'time_start'    => $timeStart,
                'time_end'      => $timeEnd,
                'time_range'    => $timeRange,
                'room'          => $room,
                'sched_type'    => $schedType,
                'id'            => $schedId,
            ]
        );

        flash('success', 'Schedule updated.');
        redirect('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId);
    }
}

$semesterMap = ['1' => '1st', '2' => '2nd', 'mid' => 'mid'];
$termSemester = $semesterMap[(string) ($term['semester'] ?? '')] ?? (string) ($term['semester'] ?? '');

$offerings = fetch_all(
    'SELECT o.id, o.subject_id, o.instructor_id AS offering_instructor_id,
            sub.subject_code, sub.subject_description,
            sub.lec_credit, sub.lab_credit,
            (sub.lec_credit + sub.lab_credit) AS units,
            sub.teaching_department_id,
            st.full_name AS default_instructor_name,
            o.sched_code
     FROM section_subject_offerings o
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
     LEFT JOIN staff st ON st.staff_id = o.instructor_id
     WHERE o.section_id = :section_id AND o.term_id = :term_id
       AND pc.semester = :semester
     ORDER BY sub.subject_code',
    ['section_id' => $sectionId, 'term_id' => $termId, 'semester' => $termSemester]
);

$offeringIds = array_column($offerings, 'id');
$schedulesBySubject = [];
if ($offeringIds !== []) {
    $db = db();
    $placeholders = implode(',', array_fill(0, count($offeringIds), '?'));
    $stmt = $db->prepare(
        "SELECT cs.*, st.full_name AS instructor_name, sub.subject_code, sub.subject_description, sub.teaching_department_id
         FROM class_schedules cs
         LEFT JOIN staff st ON st.staff_id = cs.instructor_id
         INNER JOIN subjects sub ON sub.subject_id = cs.subject_id
         WHERE cs.subject_id IN (
             SELECT o.subject_id FROM section_subject_offerings o WHERE o.id IN ($placeholders)
         ) AND cs.section_id = ? AND cs.term_id = ?
         ORDER BY FIELD(cs.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), cs.start_time"
    );
    $params = array_merge($offeringIds, [$sectionId, $termId]);
    $stmt->execute($params);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $sid = (int) $row['subject_id'];
        $row['instructor_short'] = format_instructor_short($row['instructor_name'] ?? '');
        $schedulesBySubject[$sid][] = $row;
    }
}

// Build timetable entries
$timetableEntries = [];
foreach ($schedulesBySubject as $subId => $scheds) {
    foreach ($scheds as $sc) {
        $instrShort = format_instructor_short($sc['instructor_name'] ?? '');
        $timetableEntries[] = [
            'day'                 => (string) ($sc['day'] ?? ''),
            'start_time'          => (string) ($sc['start_time'] ?? ''),
            'end_time'            => (string) ($sc['end_time'] ?? ''),
            'subject_code'        => (string) ($sc['subject_code'] ?? '') . ' ' . (string) ($sc['schedule_type'] ?? 'LEC'),
            'subject_description' => (string) ($sc['subject_description'] ?? ''),
            'instructor_name'     => $instrShort,
            'room'                => (string) ($sc['room'] ?? ''),
            'section_label'       => $sectionLabel,
        ];
    }
}

$timetableModal = '';
if ($timetableEntries !== []) {
    $totalUnits = 0;
    $totalLec = 0;
    $totalLab = 0;
    $totalWeeklyHours = 0;
    foreach ($offerings as $o) {
        $totalUnits += (float) $o['units'];
        $totalLec += (float) $o['lec_credit'];
        $totalLab += (float) $o['lab_credit'];
    }
    foreach ($timetableEntries as $te) {
        if (!empty($te['start_time']) && !empty($te['end_time'])) {
            $totalWeeklyHours += (time_to_minutes((string) $te['end_time']) - time_to_minutes((string) $te['start_time'])) / 60;
        }
    }

    $progCode = h($section['program_code'] ?? '');
    $yearLevel = h((string) ($section['year_level'] ?? ''));
    $secName = h($section['section_name'] ?? '');

    $timetableHeader = '<div style="background:linear-gradient(135deg,#1e293b,#334155);color:#fff;padding:16px 20px;border-radius:8px 8px 0 0;text-align:center;">'
        . '<div style="font-size:18px;font-weight:700;letter-spacing:0.5px;">' . $progCode . '</div>'
        . '<div style="font-size:14px;opacity:0.9;margin-top:2px;">Year ' . $yearLevel . ' &mdash; Section ' . $secName . '</div>'
        . '<div style="margin-top:8px;display:flex;justify-content:center;gap:24px;font-size:12px;opacity:0.85;">'
        . '<span>Total Units: <strong>' . h((string) $totalUnits) . '</strong></span>'
        . '<span>Weekly Contact Hours: <strong>' . h(number_format($totalWeeklyHours, 1)) . ' hrs</strong></span>'
        . '</div>'
        . '</div>';

    $timetableGrid = render_weekly_timetable($timetableEntries, ['compact' => true, 'fixed_range' => true]);

    $summaryRows = '';
    foreach ($offerings as $o) {
        $subCode = h($o['subject_code']);
        $subDesc = h($o['subject_description']);
        $lec = h((string) $o['lec_credit']);
        $lab = h((string) $o['lab_credit']);
        $tot = h((string) $o['units']);
        $room = '—';
        $subId = (int) $o['subject_id'];
        if (!empty($schedulesBySubject[$subId])) {
            $room = h($schedulesBySubject[$subId][0]['room'] ?? '—');
        }
        $summaryRows .= '<tr>'
            . '<td style="font-family:monospace;font-weight:600;">' . $subCode . '</td>'
            . '<td>' . $subDesc . '</td>'
            . '<td style="text-align:center;">' . $lec . '</td>'
            . '<td style="text-align:center;">' . $lab . '</td>'
            . '<td style="text-align:center;font-weight:600;">' . $tot . '</td>'
            . '<td>' . $room . '</td>'
            . '</tr>';
    }

    $summaryTable = '<div style="margin-top:16px;">'
        . '<table style="width:100%;border-collapse:collapse;font-size:12px;">'
        . '<thead><tr style="background:#f1f5f9;">'
        . '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #cbd5e1;">Course Code</th>'
        . '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #cbd5e1;">Course Title</th>'
        . '<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #cbd5e1;">Lec Hrs</th>'
        . '<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #cbd5e1;">Lab Hrs</th>'
        . '<th style="padding:8px 10px;text-align:center;border-bottom:2px solid #cbd5e1;">Total Hrs</th>'
        . '<th style="padding:8px 10px;text-align:left;border-bottom:2px solid #cbd5e1;">Room</th>'
        . '</tr></thead><tbody>'
        . $summaryRows
        . '</tbody></table></div>';

    $timetableInner = '<div id="timetablePrintArea">'
        . $timetableHeader
        . $timetableGrid
        . $summaryTable
        . '</div>';
    $timetableInner .= '
    <div style="text-align:center;margin-top:12px;display:flex;gap:8px;justify-content:center;">
        <button class="btn small" type="button" onclick="downloadTimetableImg()">
            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">image</span> Download Image
        </button>
        <button class="btn small secondary" type="button" onclick="downloadTimetablePdf()">
            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">picture_as_pdf</span> Download PDF
        </button>
    </div>';
    $timetableModal = render_modal('modal-timetable', h('Weekly Timetable — ' . $sectionLabel), $timetableInner, true);
}

$instructors = fetch_all(
    'SELECT st.staff_id, st.full_name, st.dept_id
     FROM staff st
     INNER JOIN users u ON u.users_id = st.users_id
     INNER JOIN user_roles ur ON ur.user_id = u.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     WHERE st.status = "active"
     ORDER BY st.full_name'
);

$instructorMap = [];
foreach ($instructors as $instr) {
    $instructorMap[(int) $instr['staff_id']] = $instr;
}

$rooms = fetch_all('SELECT DISTINCT room FROM class_schedules WHERE room IS NOT NULL AND room <> "" ORDER BY room');

$timeOptions = '<option value="">--</option>';
for ($h = 7; $h <= 20; $h++) {
    foreach (['00', '30'] as $m) {
        $t = sprintf('%02d:%02d', $h, $m);
        $timeOptions .= '<option value="' . $t . '">' . format_time_12h($t) . '</option>';
    }
}

$instructorOptions = '<option value="0" data-dept-id="0">TBA (To Be Assigned)</option>';
foreach ($instructors as $instr) {
    $instructorOptions .= '<option value="' . h((string) $instr['staff_id']) . '" data-dept-id="' . h((string) $instr['dept_id']) . '">' . h($instr['full_name']) . '</option>';
}

$roomDatalist = '';
foreach ($rooms as $rm) {
    $roomDatalist .= '<option value="' . h($rm['room']) . '">';
}

$totalStudents = section_enrollment_count($sectionId, $termId);

$totalSubjects  = count($offerings);
$totalScheduled = 0;
$totalApproved  = 0;
$totalDraft     = 0;
$totalSub = 0;
foreach ($schedulesBySubject as $scheds) {
    foreach ($scheds as $s) {
        $totalScheduled++;
        if ($s['status'] === 'approved') $totalApproved++;
        if ($s['status'] === 'draft') $totalDraft++;
        if ($s['status'] === 'submitted') $totalSub++;
    }
}

ob_start();
?>

<div class="page-header">
    <div>
        <h1><?= h($sectionLabel) ?></h1>
        <p><?= h($termLabel) ?> · <?= h((string) $totalStudents) ?> enrolled student(s)</p>
    </div>
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($timetableEntries !== []): ?>
            <button class="btn secondary" type="button" data-open="modal-timetable">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">calendar_view_week</span>
                Timetable
            </button>
        <?php endif; ?>
        <?php if ($totalDraft > 0): ?>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="submit_all_drafts">
                <button class="btn" type="submit" onclick="return confirm('Submit all <?= $totalDraft ?> draft schedule(s) for review?')">
                    <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">send</span>
                    Submit All Drafts (<?= $totalDraft ?>)
                </button>
            </form>
        <?php endif; ?>
        <a class="btn secondary" href="<?= h(app_url('chair/class_schedules.php?term_id=' . $termId)) ?>">&larr; Back to Sections</a>
    </div>
</div>

<?php $flashes = get_flashes(); if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid" style="grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:16px;">
    <div class="card slim" style="text-align:center;border-top:3px solid var(--color-primary);">
        <div style="font-size:22px;font-weight:700;"><?= $totalSubjects ?></div>
        <div style="font-size:11px;color:var(--muted);">Subjects</div>
    </div>
    <div class="card slim" style="text-align:center;border-top:3px solid #3b82f6;">
        <div style="font-size:22px;font-weight:700;color:#3b82f6;"><?= $totalDraft ?></div>
        <div style="font-size:11px;color:var(--muted);">Drafts</div>
    </div>
    <div class="card slim" style="text-align:center;border-top:3px solid #f59e0b;">
        <div style="font-size:22px;font-weight:700;color:#f59e0b;"><?= $totalSub ?></div>
        <div style="font-size:11px;color:var(--muted);">Submitted</div>
    </div>
    <div class="card slim" style="text-align:center;border-top:3px solid #10b981;">
        <div style="font-size:22px;font-weight:700;color:#10b981;"><?= $totalApproved ?></div>
        <div style="font-size:11px;color:var(--muted);">Approved</div>
    </div>
</div>

<?php if ($offerings === []): ?>
    <div class="card" style="text-align:center;padding:40px 16px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);">menu_book</span>
        <p style="margin-top:8px;color:var(--muted);">No subjects are assigned to this section for this term.<br>Add subjects via <a href="<?= h(app_url('registrar/offerings.php')) ?>">Subject Offerings</a> first.</p>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Sched Code</th>
                    <th>Subject Code</th>
                    <th>Description</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th style="width:90px;" data-dt-no-sort>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($offerings as $o): ?>
                <?php
                    $oid = (int) $o['id'];
                    $subId = (int) $o['subject_id'];
                    $scheds = $schedulesBySubject[$subId] ?? [];
                    $rowCount = max(count($scheds), 1);
                ?>
                <?php if ($scheds === []): ?>
                <tr>
                    <td style="font-family:monospace;font-size:12px;font-weight:600;"><?= h($o['sched_code'] ?? '—') ?></td>
                    <td><span class="badge" style="font-family:monospace;"><?= h($o['subject_code']) ?></span></td>
                    <td style="font-size:12px;"><?= h($o['subject_description']) ?></td>
                    <td style="color:var(--muted);font-style:italic;">No schedule added yet</td>
                    <td><span class="badge" style="font-size:10px;background:#fee2e2;color:#b91c1c;">None</span></td>
                    <td>
                        <button class="icon-btn" type="button" title="Add Schedule"
                            data-subject-id="<?= h((string) $subId) ?>"
                            data-subject-code="<?= h((string) $o['subject_code']) ?>"
                            data-subject-desc="<?= h((string) $o['subject_description']) ?>"
                            data-instructor-id="<?= h((string) ($o['offering_instructor_id'] ?? 0)) ?>"
                            data-teach-dept-id="<?= h((string) ($o['teaching_department_id'] ?? 0)) ?>"
                            onclick="openAddModal(this)">
                            <span class="material-symbols-outlined" style="font-size:16px;">add</span>
                        </button>
                    </td>
                </tr>
                <?php else: ?>
                    <?php foreach ($scheds as $i => $sc): ?>
                    <tr>
                        <?php if ($i === 0): ?>
                        <td rowspan="<?= $rowCount ?>" style="vertical-align:top;font-family:monospace;font-size:12px;font-weight:600;border-right:1px solid #e2e8f0;">
                            <?= h($sc['schedule_code'] ?? '—') ?>
                        </td>
                        <td rowspan="<?= $rowCount ?>" style="vertical-align:top;border-right:1px solid #e2e8f0;">
                            <span class="badge" style="font-family:monospace;"><?= h($o['subject_code']) ?></span>
                            <span class="badge info" style="font-size:10px;margin-left:2px;"><?= h($sc['schedule_type'] ?? 'LEC') ?></span>
                        </td>
                        <td rowspan="<?= $rowCount ?>" style="vertical-align:top;font-size:12px;border-right:1px solid #e2e8f0;"><?= h($o['subject_description']) ?></td>
                        <?php endif; ?>
                        <td style="white-space:nowrap;">
                            <strong style="color:var(--color-primary);"><?= h($sc['day'] ?? '—') ?></strong><br>
                            <span style="font-size:12px;"><?= h(format_time_12h((string) $sc['start_time'])) ?> – <?= h(format_time_12h((string) $sc['end_time'])) ?></span><br>
                            <span style="font-size:11px;color:var(--muted);">Rm <?= h($sc['room'] ?? '—') ?> • <?= h(format_instructor_short($sc['instructor_name'] ?? '')) ?></span>
                        </td>
                        <td><span class="badge <?= schedule_status_badge_class($sc['status']) ?>" style="font-size:10px;"><?= h(ucfirst($sc['status'])) ?></span></td>
                        <td>
                            <div class="row-actions" style="gap:2px;">
                                <button class="icon-btn" type="button" title="Edit"
                                    data-id="<?= h((string) $sc['id']) ?>"
                                    data-day="<?= h((string) $sc['day'] ?? '') ?>"
                                    data-start="<?= h((string) $sc['start_time'] ?? '') ?>"
                                    data-end="<?= h((string) $sc['end_time'] ?? '') ?>"
                                    data-room="<?= h((string) $sc['room'] ?? '') ?>"
                                    data-instructor="<?= h((string) ($sc['instructor_id'] ?? 0)) ?>"
                                    data-sched-type="<?= h((string) ($sc['schedule_type'] ?? 'LEC')) ?>"
                                    data-teach-dept-id="<?= h((string) ($sc['teaching_department_id'] ?? 0)) ?>"
                                    onclick="openEditModal(this)">
                                    <span class="material-symbols-outlined" style="font-size:16px;">edit</span>
                                </button>
                                <?php if ($sc['status'] === 'draft'): ?>
                                    <form class="inline-form" method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="submit_schedule">
                                        <input type="hidden" name="schedule_id" value="<?= h((string) $sc['id']) ?>">
                                        <button class="icon-btn" type="submit" title="Submit" onclick="return confirm('Submit this schedule?')">
                                            <span class="material-symbols-outlined" style="font-size:16px;">send</span>
                                        </button>
                                    </form>
                                    <form class="inline-form" method="post" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_schedule">
                                        <input type="hidden" name="schedule_id" value="<?= h((string) $sc['id']) ?>">
                                        <button class="icon-btn danger" type="submit" title="Delete" onclick="return confirm('Delete this schedule?')">
                                            <span class="material-symbols-outlined" style="font-size:16px;">delete</span>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($sc['status'] === 'rejected' && !empty($sc['rejection_reason'])): ?>
                                    <span class="icon-btn" title="<?= h($sc['rejection_reason']) ?>" style="cursor:help;color:#ef4444;">
                                        <span class="material-symbols-outlined" style="font-size:16px;">error</span>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <tr>
                        <td colspan="5" style="padding:4px 8px;border-top:1px dashed #e2e8f0;">
                            <button class="icon-btn" type="button" title="Add another schedule"
                                data-subject-id="<?= h((string) $subId) ?>"
                                data-subject-code="<?= h((string) $o['subject_code']) ?>"
                                data-subject-desc="<?= h((string) $o['subject_description']) ?>"
                                data-instructor-id="<?= h((string) ($o['offering_instructor_id'] ?? 0)) ?>"
                                data-teach-dept-id="<?= h((string) ($o['teaching_department_id'] ?? 0)) ?>"
                                onclick="openAddModal(this)" style="font-size:14px;color:var(--color-primary);">
                                <span class="material-symbols-outlined" style="font-size:14px;">add</span> Add
                            </button>
                        </td>
                    </tr>
                <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$addModalBody = '
<form id="addScheduleForm" method="post" action="' . h(app_url('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId)) . '">
    ' . csrf_field() . '
    <input type="hidden" name="action" value="create_schedule">
    <input type="hidden" name="subject_id" id="addSubjectId">
    <input type="hidden" name="subject_code" id="addSubjectCode">

    <div style="margin-bottom:12px;padding:8px 12px;background:var(--bg-secondary,#f8fafc);border-radius:6px;">
        <strong id="addSubjectLabel"></strong>
    </div>

    <div class="form-grid cols-3">
        <div>
            <label>Schedule Type *</label>
            <select name="schedule_type" required>
                <option value="LEC">LEC (Lecture)</option>
                <option value="LAB">LAB (Laboratory)</option>
                <option value="ASYNC">ASYNC (Asynchronous)</option>
            </select>
        </div>
        <div>
            <label>Day *</label>
            <select name="day" required>
                <option value="">-- Select Day --</option>';
foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d) {
    $addModalBody .= '<option value="' . $d . '">' . $d . '</option>';
}
$addModalBody .= '
            </select>
        </div>
        <div>
            <label>Time Start *</label>
            <select name="start_time" required>' . $timeOptions . '</select>
        </div>
    </div>
    <div class="form-grid cols-3">
        <div>
            <label>Time End *</label>
            <select name="end_time" required>' . $timeOptions . '</select>
        </div>
        <div>
            <label>Room *</label>
            <input type="text" name="room" required list="roomListAdd" placeholder="e.g. Room 201">
            <datalist id="roomListAdd">' . $roomDatalist . '</datalist>
        </div>
        <div>
            <label>Instructor *</label>
            <select name="instructor_id">' . $instructorOptions . '</select>
        </div>
    </div>
    <div class="form-actions" style="margin-top:16px;">
        <button class="btn" type="submit">Add Schedule</button>
        <button class="btn secondary" type="button" data-close="modal-add-schedule">Cancel</button>
    </div>
</form>';

$addModal = render_modal('modal-add-schedule', 'Add Schedule', $addModalBody);

$editModalBody = '
<form id="editScheduleForm" method="post" action="' . h(app_url('chair/section_schedule.php?section_id=' . $sectionId . '&term_id=' . $termId)) . '">
    ' . csrf_field() . '
    <input type="hidden" name="action" value="edit_schedule">
    <input type="hidden" name="schedule_id" id="editScheduleId">

    <div class="form-grid cols-3">
        <div>
            <label>Schedule Type *</label>
            <select name="schedule_type" id="editSchedType" required>
                <option value="LEC">LEC (Lecture)</option>
                <option value="LAB">LAB (Laboratory)</option>
                <option value="ASYNC">ASYNC (Asynchronous)</option>
            </select>
        </div>
        <div>
            <label>Day *</label>
            <select name="day" id="editDay" required>
                <option value="">-- Select Day --</option>';
foreach (['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $d) {
    $editModalBody .= '<option value="' . $d . '">' . $d . '</option>';
}
$editModalBody .= '
            </select>
        </div>
        <div>
            <label>Time Start *</label>
            <select name="start_time" id="editStartTime" required>' . $timeOptions . '</select>
        </div>
    </div>
    <div class="form-grid cols-3">
        <div>
            <label>Time End *</label>
            <select name="end_time" id="editEndTime" required>' . $timeOptions . '</select>
        </div>
        <div>
            <label>Room *</label>
            <input type="text" name="room" id="editRoom" required list="roomListEdit" placeholder="e.g. Room 201">
            <datalist id="roomListEdit">' . $roomDatalist . '</datalist>
        </div>
        <div>
            <label>Instructor *</label>
            <select name="instructor_id" id="editInstructor">' . $instructorOptions . '</select>
        </div>
    </div>
    <div class="form-actions" style="margin-top:16px;">
        <button class="btn" type="submit">Save Changes</button>
        <button class="btn secondary" type="button" data-close="modal-edit-schedule">Cancel</button>
    </div>
</form>';

$editModal = render_modal('modal-edit-schedule', 'Edit Schedule', $editModalBody);

$jsonSectionLabel = json_encode($sectionLabel);
$jsonTermLabel = json_encode($termLabel);
$jsonSectionLabelSafe = addslashes(str_replace("'", '', $sectionLabel));

$addModalJs = <<<SCRIPT
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
function openAddModal(btn) {
    document.getElementById("addSubjectId").value = btn.dataset.subjectId;
    document.getElementById("addSubjectCode").value = btn.dataset.subjectCode;
    document.getElementById("addSubjectLabel").textContent = btn.dataset.subjectCode + " - " + btn.dataset.subjectDesc;
    var instrSel = document.querySelector("#modal-add-schedule select[name='instructor_id']");
    if (instrSel) {
        var teachDeptId = parseInt(btn.dataset.teachDeptId) || 0;
        var defaultInstrId = btn.dataset.instructorId || "0";
        var opts = instrSel.options;
        var foundDefault = false;
        for (var i = 0; i < opts.length; i++) {
            var optDeptId = parseInt(opts[i].getAttribute("data-dept-id")) || 0;
            var show = (teachDeptId === 0) || (optDeptId === 0) || (optDeptId === teachDeptId);
            opts[i].hidden = !show;
            opts[i].disabled = !show;
            if (show && opts[i].value === defaultInstrId) foundDefault = true;
        }
        instrSel.value = foundDefault ? defaultInstrId : "0";
    }
    document.getElementById("modal-add-schedule").classList.add("active");
}

function openEditModal(btn) {
    document.getElementById("editScheduleId").value = btn.dataset.id;
    document.getElementById("editDay").value = btn.dataset.day;
    document.getElementById("editStartTime").value = btn.dataset.start;
    document.getElementById("editEndTime").value = btn.dataset.end;
    document.getElementById("editRoom").value = btn.dataset.room;
    document.getElementById("editSchedType").value = btn.dataset.schedType || "LEC";

    var instrSel = document.getElementById("editInstructor");
    if (instrSel) {
        var teachDeptId = parseInt(btn.dataset.teachDeptId) || 0;
        var currentInstrId = btn.dataset.instructor || "0";
        var opts = instrSel.options;
        var foundCurrent = false;
        for (var i = 0; i < opts.length; i++) {
            var optDeptId = parseInt(opts[i].getAttribute("data-dept-id")) || 0;
            var show = (teachDeptId === 0) || (optDeptId === 0) || (optDeptId === teachDeptId);
            opts[i].hidden = !show;
            opts[i].disabled = !show;
            if (show && opts[i].value === currentInstrId) foundCurrent = true;
        }
        instrSel.value = foundCurrent ? currentInstrId : "0";
    }

    document.getElementById("modal-edit-schedule").classList.add("active");
}

function getTimetableElement() {
    var modal = document.getElementById("modal-timetable");
    return modal ? modal.querySelector("#timetablePrintArea") || modal.querySelector(".weekly-timetable") : null;
}

function downloadTimetablePdf() {
    var el = getTimetableElement();
    if (!el) { alert("No timetable to download."); return; }

    html2canvas(el, { scale: 2, useCORS: true }).then(function(canvas) {
        var imgData = canvas.toDataURL("image/png");
        var imgW = canvas.width;
        var imgH = canvas.height;
        var pdfW = 297;
        var pdfH = (imgH / imgW) * pdfW;
        var pdf = new jspdf.jsPDF({ orientation: "landscape", unit: "mm", format: "a4" });
        pdf.addImage(imgData, "PNG", 0, 0, pdfW, pdfH);
        pdf.save("timetable_{$jsonSectionLabelSafe}.pdf");
    });
}

function downloadTimetableImg() {
    var el = getTimetableElement();
    if (!el) { alert("No timetable to download."); return; }

    html2canvas(el, { scale: 2, useCORS: true }).then(function(canvas) {
        var link = document.createElement("a");
        link.download = "timetable_{$jsonSectionLabelSafe}.png";
        link.href = canvas.toDataURL("image/png");
        link.click();
    });
}
</script>
SCRIPT;

render_page('Section Schedule — ' . $sectionLabel, 'Class Schedules', (string) ob_get_clean(), ['modals' => array_filter([$addModal, $editModal, $timetableModal ?? null]), 'extra_js' => $addModalJs]);
