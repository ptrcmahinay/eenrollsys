<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['chair', 'admin', 'registrar']);

$currentUser = current_user();
$isChair = ($currentUser['role'] ?? '') === 'chair';

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$offeringId = (int) ($_GET['offering_id'] ?? 0);

$offering = $offeringId > 0 ? fetch_one(
    'SELECT o.id, o.term_id, o.section_id, o.curriculum_id, o.subject_id,
            sec.year_level, sec.section_name, sec.program_id,
            p.program_code, p.program_name, p.department_id,
            sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
            sub.lec_hours, sub.lab_hours
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     WHERE o.id = :id',
    ['id' => $offeringId]
) : null;

if ($offering === null || ($isChair && (int) $offering['department_id'] !== (int) ($staff['dept_id'] ?? 0))) {
    flash('error', 'Subject offering not found' . ($isChair ? ' or not in your department.' : '.'));
    redirect('chair/class_schedules.php');
}

$term = fetch_one(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE t.id = :id',
    ['id' => (int) $offering['term_id']]
);
$termLabel = $term['label'] ?? '';

$schedCode = ensure_schedule_code_for_offering($offeringId);

$days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

$timeOptions = [];
for ($h = 7; $h <= 21; $h++) {
    foreach ([0, 30] as $m) {
        if ($h === 21 && $m === 30) continue;
        $timeOptions[] = sprintf('%02d:%02d', $h, $m);
    }
}

$instructors = $isChair
    ? fetch_all(
        'SELECT st.staff_id, st.full_name, d.department_code
         FROM staff st
         INNER JOIN user_roles ur ON ur.user_id = st.users_id
         INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
         LEFT JOIN departments d ON d.dept_id = st.dept_id
         WHERE st.dept_id = :dept_main
         ORDER BY st.full_name',
        ['dept_main' => (int) $staff['dept_id']]
    )
    : fetch_all(
        'SELECT st.staff_id, st.full_name, d.department_code
         FROM staff st
         INNER JOIN user_roles ur ON ur.user_id = st.users_id
         INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
         LEFT JOIN departments d ON d.dept_id = st.dept_id
         ORDER BY st.full_name'
    );

$rooms = [];
foreach (fetch_all('SELECT DISTINCT room FROM section_subject_offerings WHERE room IS NOT NULL AND room != \'\'') as $r) {
    $rooms[$r['room']] = true;
}
foreach (fetch_all('SELECT DISTINCT room FROM class_schedules WHERE room IS NOT NULL AND room != \'\'') as $r) {
    $rooms[$r['room']] = true;
}
$roomList = array_keys($rooms);
sort($roomList);

function schedule_entries_from_post(array $post): array
{
    $raw = $post['entries'] ?? [];
    $entries = [];
    if (!is_array($raw)) return $entries;
    foreach ($raw as $r) {
        if (!is_array($r)) continue;
        $day = trim((string) ($r['day'] ?? ''));
        $start = trim((string) ($r['start_time'] ?? ''));
        $end = trim((string) ($r['end_time'] ?? ''));
        $room = trim((string) ($r['room'] ?? ''));
        $instructorId = (($r['instructor_id'] ?? '') !== '') ? (int) $r['instructor_id'] : null;
        $scheduleId = (int) ($r['schedule_id'] ?? 0);

        if ($day === '' && $start === '' && $end === '' && $room === '' && $instructorId === null) {
            continue;
        }
        $entries[] = [
            'schedule_id' => $scheduleId,
            'day' => $day,
            'start_time' => $start,
            'end_time' => $end,
            'room' => $room,
            'instructor_id' => $instructorId,
        ];
    }
    return $entries;
}

$formEntries = null;

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'save_schedule_entries') {
        $posted = schedule_entries_from_post($_POST);
        $conflicts = [];

        foreach ($posted as $i => $e) {
            $num = $i + 1;
            if ($e['day'] === '' || $e['start_time'] === '' || $e['end_time'] === '') {
                $conflicts[] = 'Schedule entry #' . $num . ' must have a day, start time, and end time.';
                continue;
            }
            if (!in_array($e['day'], $days, true)) {
                $conflicts[] = 'Schedule entry #' . $num . ' has an invalid day.';
                continue;
            }
            if (!strtotime($e['start_time']) || !strtotime($e['end_time'])) {
                $conflicts[] = 'Schedule entry #' . $num . ' has an invalid time.';
                continue;
            }
            if (time_to_minutes($e['start_time']) >= time_to_minutes($e['end_time'])) {
                $conflicts[] = 'Schedule entry #' . $num . ' must have an end time later than the start time.';
            }
        }

        foreach ($posted as $i => $e) {
            for ($j = $i + 1; $j < count($posted); $j++) {
                $a = $posted[$i];
                $b = $posted[$j];
                if ($a['day'] === '' || $b['day'] === '' || $a['start_time'] === '' || $b['start_time'] === '' || $a['end_time'] === '' || $b['end_time'] === '') continue;
                if ($a['day'] !== $b['day']) continue;
                $s1 = time_to_minutes($a['start_time']);
                $e1 = time_to_minutes($a['end_time']);
                $s2 = time_to_minutes($b['start_time']);
                $e2 = time_to_minutes($b['end_time']);
                if ($s1 < $e2 && $s2 < $e1) {
                    $conflicts[] = 'Duplicate meeting: ' . $offering['subject_code'] . ' already has a meeting on ' . $a['day']
                        . ' from ' . format_time_range($a['start_time'], $a['end_time'])
                        . ' (entry #' . ($i + 1) . ' and #' . ($j + 1) . ').';
                }
            }
        }

        foreach ($posted as $i => $e) {
            if ($e['day'] === '' || $e['start_time'] === '' || $e['end_time'] === '') continue;
            foreach (find_schedule_conflicts([
                'section_id' => (int) $offering['section_id'],
                'offering_id' => $offeringId,
                'day' => $e['day'],
                'start_time' => $e['start_time'],
                'end_time' => $e['end_time'],
                'room' => $e['room'],
                'instructor_id' => $e['instructor_id'],
                'exclude_schedule_id' => $e['schedule_id'],
            ]) as $msg) {
                $conflicts[] = $msg;
            }
        }

        if ($conflicts !== []) {
            foreach (array_unique($conflicts) as $msg) {
                flash('error', '⚠ Schedule Conflict — ' . $msg);
            }
            $formEntries = $posted;
        } else {
            $scid = $schedCode;
            if ($scid !== '') {
                $submittedIds = [];
                foreach ($posted as $e) {
                    if ($e['schedule_id'] > 0) $submittedIds[] = $e['schedule_id'];
                }

                if ($submittedIds === []) {
                    execute_sql('DELETE FROM class_schedules WHERE schedule_code = :scid', ['scid' => $scid]);
                } else {
                    $placeholders = implode(',', array_fill(0, count($submittedIds), '?'));
                    execute_sql(
                        "DELETE FROM class_schedules WHERE schedule_code = ? AND id NOT IN ($placeholders)",
                        array_merge([$scid], $submittedIds)
                    );
                }

                foreach ($posted as $e) {
                    $timeRange = format_time_range($e['start_time'], $e['end_time']);
                    if ($e['schedule_id'] > 0) {
                        execute_sql(
                            'UPDATE class_schedules SET day = :day, start_time = :st, end_time = :et, time_range = :tr, room = :room, instructor_id = :iid, updated_at = NOW() WHERE id = :id',
                            [
                                'day' => $e['day'],
                                'st' => $e['start_time'],
                                'et' => $e['end_time'],
                                'tr' => $timeRange,
                                'room' => $e['room'],
                                'iid' => $e['instructor_id'],
                                'id' => $e['schedule_id'],
                            ]
                        );
                    } else {
                        execute_sql(
                            'INSERT INTO class_schedules (schedule_code, subject_id, section_id, term_id, day, start_time, end_time, time_range, room, instructor_id, created_at)
                             VALUES (:scid, :subject_id, :section_id, :term_id, :day, :st, :et, :tr, :room, :iid, NOW())',
                            [
                                'scid' => $scid,
                                'subject_id' => (int) $offering['subject_id'],
                                'section_id' => (int) $offering['section_id'],
                                'term_id' => (int) $offering['term_id'],
                                'day' => $e['day'],
                                'st' => $e['start_time'],
                                'et' => $e['end_time'],
                                'tr' => $timeRange,
                                'room' => $e['room'],
                                'iid' => $e['instructor_id'],
                            ]
                        );
                    }
                }

                flash('success', 'Schedule saved for ' . $offering['subject_code'] . '.');
                redirect('chair/section_schedule.php?section_id=' . (int) $offering['section_id'] . '&term_id=' . (int) $offering['term_id']);
            }

            flash('error', 'Could not save the schedule. Please contact the registrar.');
            redirect('chair/section_schedule.php?section_id=' . (int) $offering['section_id'] . '&term_id=' . (int) $offering['term_id']);
        }
    }
}

$existingEntries = [];
if ($schedCode !== '') {
    $existingEntries = fetch_all(
        'SELECT cs.id AS schedule_id, cs.day, cs.start_time, cs.end_time, cs.room, cs.instructor_id, st.full_name AS instructor_name
         FROM class_schedules cs
         LEFT JOIN staff st ON st.staff_id = cs.instructor_id
         WHERE cs.schedule_code = :scid
         ORDER BY FIELD(cs.day, "Monday","Tuesday","Wednesday","Thursday","Friday","Saturday","Sunday"), cs.start_time',
        ['scid' => $schedCode]
    );
}

$formEntries = $formEntries ?? $existingEntries;

$status = offering_schedule_status(
    [
        'lec_hours' => $offering['lec_hours'],
        'lab_hours' => $offering['lab_hours'],
    ],
    $existingEntries
);

$flashes = get_flashes();

$backUrl = app_url('chair/section_schedule.php?section_id=' . (int) $offering['section_id'] . '&term_id=' . (int) $offering['term_id']);

$timeSelectOptions = '';
foreach ($timeOptions as $t) {
    $timeSelectOptions .= '<option value="' . h($t) . '">' . h(format_time_12h($t)) . '</option>';
}
$daySelectOptions = '';
foreach ($days as $d) {
    $daySelectOptions .= '<option value="' . h($d) . '">' . h($d) . '</option>';
}
$instructorSelectOptions = '<option value="">— Select Instructor —</option>';
foreach ($instructors as $i) {
    $instructorSelectOptions .= '<option value="' . h((string) $i['staff_id']) . '">' . h($i['full_name'] . ' [' . $i['department_code'] . ']') . '</option>';
}
$roomDatalist = '';
foreach ($roomList as $r) {
    $roomDatalist .= '<option value="' . h($r) . '">';
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= h($offering['subject_code']) ?> — Schedule</h1>
        <p><?= h($offering['subject_description']) ?> • <?= h($offering['program_code'] . ' ' . $offering['year_level'] . $offering['section_name']) ?> • <?= h($termLabel) ?></p>
    </div>
    <a class="btn secondary" href="<?= h($backUrl) ?>">← Back to Section</a>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 14px;">Subject Information</h3>
    <div class="info-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;">
        <div class="info-item"><strong>Course Code:</strong> <?= h($offering['subject_code']) ?></div>
        <div class="info-item"><strong>Subject Name:</strong> <?= h($offering['subject_description']) ?></div>
        <div class="info-item"><strong>Units:</strong> <?= h((string) $offering['units']) ?></div>
        <div class="info-item"><strong>Section:</strong> <?= h($offering['program_code'] . ' ' . $offering['year_level'] . $offering['section_name']) ?></div>
        <div class="info-item"><strong>Year Level:</strong> <?= h((string) $offering['year_level']) ?> Year</div>
        <div class="info-item"><strong>Status:</strong> <?= schedule_status_badge($status) ?></div>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <h3 style="margin:0;">Schedule Details</h3>
        <button class="btn secondary small" type="button" onclick="addScheduleRow()">
            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">add</span>
            Add Another Schedule
        </button>
    </div>
    <p class="helper" style="margin-top:-6px;">A subject can have multiple meetings per week. Each row below is one schedule entry.</p>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_schedule_entries">
        <div id="sched-entries">
            <?php $rowIndex = 0; ?>
            <?php if ($formEntries === []): ?>
                <div class="sched-entry-row" style="display:grid;grid-template-columns:140px 120px 120px 1fr 200px 40px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:10px;">
                    <input type="hidden" name="entries[0][schedule_id]" value="0">
                    <div><label>Day</label><select name="entries[0][day]"><option value="">—</option><?= $daySelectOptions ?></select></div>
                    <div><label>Start Time</label><select name="entries[0][start_time]"><option value="">—</option><?= $timeSelectOptions ?></select></div>
                    <div><label>End Time</label><select name="entries[0][end_time]"><option value="">—</option><?= $timeSelectOptions ?></select></div>
                    <div><label>Room</label><input type="text" name="entries[0][room]" list="room-list" placeholder="e.g. Room 201"><datalist id="room-list"><?= $roomDatalist ?></datalist></div>
                    <div><label>Instructor</label><select name="entries[0][instructor_id]"><?= $instructorSelectOptions ?></select></div>
                    <div style="align-self:end;"></div>
                </div>
                <?php $rowIndex = 1; ?>
            <?php else: ?>
                <?php foreach ($formEntries as $i => $e): ?>
                    <?php $idx = $i; $rowIndex++; ?>
                    <div class="sched-entry-row" style="display:grid;grid-template-columns:140px 120px 120px 1fr 200px 40px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:10px;">
                        <input type="hidden" name="entries[<?= $idx ?>][schedule_id]" value="<?= h((string) $e['schedule_id']) ?>">
                        <div><label>Day</label><select name="entries[<?= $idx ?>][day]"><option value="">—</option><?php foreach ($days as $d): ?><option value="<?= h($d) ?>" <?= ($e['day'] ?? '') === $d ? 'selected' : '' ?>><?= h($d) ?></option><?php endforeach; ?></select></div>
                        <div><label>Start Time</label><select name="entries[<?= $idx ?>][start_time]"><option value="">—</option><?php foreach ($timeOptions as $t): ?><option value="<?= h($t) ?>" <?= ($e['start_time'] ?? '') === $t ? 'selected' : '' ?>><?= h(format_time_12h($t)) ?></option><?php endforeach; ?></select></div>
                        <div><label>End Time</label><select name="entries[<?= $idx ?>][end_time]"><option value="">—</option><?php foreach ($timeOptions as $t): ?><option value="<?= h($t) ?>" <?= ($e['end_time'] ?? '') === $t ? 'selected' : '' ?>><?= h(format_time_12h($t)) ?></option><?php endforeach; ?></select></div>
                        <div><label>Room</label><input type="text" name="entries[<?= $idx ?>][room]" list="room-list" value="<?= h($e['room'] ?? '') ?>" placeholder="e.g. Room 201"></div>
                        <div><label>Instructor</label><select name="entries[<?= $idx ?>][instructor_id]"><option value="">— Select Instructor —</option><?php foreach ($instructors as $inst): ?><option value="<?= h((string) $inst['staff_id']) ?>" <?= (int) ($e['instructor_id'] ?? 0) === (int) $inst['staff_id'] ? 'selected' : '' ?>><?= h($inst['full_name'] . ' [' . $inst['department_code'] . ']') ?></option><?php endforeach; ?></select></div>
                        <div style="align-self:end;"><button class="action-btn danger" type="button" title="Remove entry" onclick="this.closest('.sched-entry-row').remove()"><span class="material-symbols-outlined" style="font-size:18px;">close</span></button></div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="form-actions">
            <button class="btn" type="submit">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">save</span>
                Save Schedule
            </button>
            <a class="btn secondary" href="<?= h($backUrl) ?>">Cancel</a>
        </div>
    </form>
</div>

<template id="sched-entry-template">
    <div class="sched-entry-row" style="display:grid;grid-template-columns:140px 120px 120px 1fr 200px 40px;gap:10px;align-items:center;padding:12px;border:1px solid var(--line);border-radius:10px;margin-bottom:10px;">
        <input type="hidden" name="entries[__I__][schedule_id]" value="0">
        <div><label>Day</label><select name="entries[__I__][day]"><option value="">—</option><?= $daySelectOptions ?></select></div>
        <div><label>Start Time</label><select name="entries[__I__][start_time]"><option value="">—</option><?= $timeSelectOptions ?></select></div>
        <div><label>End Time</label><select name="entries[__I__][end_time]"><option value="">—</option><?= $timeSelectOptions ?></select></div>
        <div><label>Room</label><input type="text" name="entries[__I__][room]" list="room-list" placeholder="e.g. Room 201"></div>
        <div><label>Instructor</label><select name="entries[__I__][instructor_id]"><?= $instructorSelectOptions ?></select></div>
        <div style="align-self:end;"><button class="action-btn danger" type="button" title="Remove entry" onclick="this.closest('.sched-entry-row').remove()"><span class="material-symbols-outlined" style="font-size:18px;">close</span></button></div>
    </div>
</template>

<script>
var rowIndex = <?= (int) $rowIndex ?>;
function addScheduleRow() {
    var tpl = document.getElementById('sched-entry-template');
    var html = tpl.innerHTML.replace(/__I__/g, rowIndex);
    var wrap = document.createElement('div');
    wrap.innerHTML = html.trim();
    document.getElementById('sched-entries').appendChild(wrap.firstChild);
    rowIndex++;
}
</script>

<?php
render_page(
    'Create Schedule — ' . $offering['subject_code'],
    'Class Schedules',
    (string) ob_get_clean()
);
