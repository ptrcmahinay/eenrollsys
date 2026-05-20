<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
$currentUser = require_role(['admin', 'registrar', 'chair', 'instructor']);
$role = $currentUser['role'] ?? '';
$canManage = in_array($role, ['admin', 'registrar'], true);

$deptScopeId = 0;
if (!$canManage) {
    $staff = current_staff();
    $deptScopeId = (int) ($staff['dept_id'] ?? 0);
}

/* ══════════════════════════════════════════════════════════════════════════
   POST handlers
   ══════════════════════════════════════════════════════════════════════════ */
if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'add_offering') {
        $termId   = (int) ($_POST['term_id'] ?? 0);
        $secId    = (int) ($_POST['section_id'] ?? 0);
        $currId   = (int) ($_POST['curriculum_id'] ?? 0);
        $subjId   = (int) ($_POST['subject_id'] ?? 0);
        $instrId  = ($_POST['instructor_id'] ?? '') !== '' ? (int) $_POST['instructor_id'] : null;
        $room     = trim($_POST['room'] ?? 'TBA');
        $day      = trim($_POST['day_of_week'] ?? 'TBA');
        $time     = trim($_POST['time_range'] ?? 'TBA');
        $maxSlots = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($termId > 0 && $secId > 0 && $currId > 0 && $subjId > 0) {
            if ($day !== '' && $day !== 'TBA' && $time !== '' && $time !== 'TBA') {
                $conflict = fetch_one(
                    'SELECT o.id, sub.subject_code FROM section_subject_offerings o
                     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                     WHERE o.term_id = :tid AND o.section_id = :sid AND o.day_of_week = :day AND o.time_range = :time AND o.id != :oid',
                    ['tid' => $termId, 'sid' => $secId, 'day' => $day, 'time' => $time, 'oid' => 0]
                );
                if ($conflict !== null) {
                    flash('error', 'Time conflict: ' . h($conflict['subject_code']) . ' is already scheduled on ' . h($day) . ' ' . h($time) . ' for this section.');
                    redirect('registrar/offerings.php');
                }
            }

            $dup = fetch_one(
                'SELECT id FROM section_subject_offerings WHERE term_id = :tid AND section_id = :sid AND subject_id = :subid',
                ['tid' => $termId, 'sid' => $secId, 'subid' => $subjId]
            );
            if ($dup !== null) {
                flash('error', 'This subject is already offered for this section in the selected term.');
            } else {
                execute_sql(
                    'INSERT INTO section_subject_offerings
                        (term_id, section_id, curriculum_id, subject_id, instructor_id,
                         room, day_of_week, time_range, max_slots, syllabus_path, created_at)
                     VALUES (:tid, :sid, :cid, :subid, :iid, :room, :day, :time, :ms, NULL, NOW())',
                    [
                        'tid' => $termId, 'sid' => $secId, 'cid' => $currId, 'subid' => $subjId,
                        'iid' => $instrId, 'room' => $room, 'day' => $day, 'time' => $time, 'ms' => $maxSlots,
                    ]
                );
                flash('success', 'Section offering created.');
            }
        }
    }

    if ($action === 'update_offering') {
        $offId    = (int) ($_POST['offering_id'] ?? 0);
        $instrId  = ($_POST['instructor_id'] ?? '') !== '' ? (int) $_POST['instructor_id'] : null;
        $room     = trim($_POST['room'] ?? 'TBA');
        $day      = trim($_POST['day_of_week'] ?? 'TBA');
        $time     = trim($_POST['time_range'] ?? 'TBA');
        $maxSlots = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($offId > 0) {
            $off = fetch_one('SELECT term_id, section_id, subject_id FROM section_subject_offerings WHERE id = :id', ['id' => $offId]);
            if ($off !== null && $day !== '' && $day !== 'TBA' && $time !== '' && $time !== 'TBA') {
                $conflict = fetch_one(
                    'SELECT o.id, sub.subject_code FROM section_subject_offerings o
                     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                     WHERE o.term_id = :tid AND o.section_id = :sid AND o.day_of_week = :day AND o.time_range = :time AND o.id != :oid',
                    ['tid' => (int) $off['term_id'], 'sid' => (int) $off['section_id'], 'day' => $day, 'time' => $time, 'oid' => $offId]
                );
                if ($conflict !== null) {
                    flash('error', 'Time conflict: ' . h($conflict['subject_code']) . ' is already scheduled on ' . h($day) . ' ' . h($time) . ' for this section.');
                    redirect('registrar/offerings.php');
                }
            }

            execute_sql(
                'UPDATE section_subject_offerings
                 SET instructor_id = :iid, room = :room, day_of_week = :day, time_range = :time, max_slots = :ms
                 WHERE id = :oid',
                ['iid' => $instrId, 'room' => $room, 'day' => $day, 'time' => $time, 'ms' => $maxSlots, 'oid' => $offId]
            );
            flash('success', 'Offering updated.');
        }
    }

    if ($action === 'bulk_create_offerings') {
        $termId  = (int) ($_POST['bulk_term_id'] ?? 0);
        $secId   = (int) ($_POST['bulk_section_id'] ?? 0);
        $instrId = ($_POST['bulk_instructor_id'] ?? '') !== '' ? (int) $_POST['bulk_instructor_id'] : null;
        $room    = trim($_POST['bulk_room'] ?? 'TBA');
        $day     = trim($_POST['bulk_day_of_week'] ?? 'TBA');
        $time    = trim($_POST['bulk_time_range'] ?? 'TBA');
        $curriculumIds = $_POST['bulk_curriculum_ids'] ?? [];

        $created = 0; $conflicts = 0; $skipped = 0;
        foreach ((array) $curriculumIds as $cid) {
            $cid = (int) $cid;
            $cur = fetch_one('SELECT subject_id FROM program_curriculum WHERE curriculum_id = :id', ['id' => $cid]);
            if ($cur === null) { $skipped++; continue; }

            $exists = fetch_one(
                'SELECT id FROM section_subject_offerings WHERE term_id = :tid AND section_id = :sid AND subject_id = :subid',
                ['tid' => $termId, 'sid' => $secId, 'subid' => (int) $cur['subject_id']]
            );
            if ($exists !== null) { $skipped++; continue; }

            if ($day !== '' && $day !== 'TBA' && $time !== '' && $time !== 'TBA') {
                $conflict = fetch_one(
                    'SELECT id FROM section_subject_offerings WHERE term_id = :tid AND section_id = :sid AND day_of_week = :day AND time_range = :time',
                    ['tid' => $termId, 'sid' => $secId, 'day' => $day, 'time' => $time]
                );
                if ($conflict !== null) { $conflicts++; continue; }
            }

            execute_sql(
                'INSERT INTO section_subject_offerings
                    (term_id, section_id, curriculum_id, subject_id, instructor_id,
                     room, day_of_week, time_range, max_slots, syllabus_path, created_at)
                 VALUES (:tid, :sid, :cid, :subid, :iid, :room, :day, :time, NULL, NULL, NOW())',
                [
                    'tid' => $termId, 'sid' => $secId, 'cid' => $cid, 'subid' => (int) $cur['subject_id'],
                    'iid' => $instrId, 'room' => $room, 'day' => $day, 'time' => $time,
                ]
            );
            $created++;
        }

        $msg = "{$created} offering(s) created";
        if ($skipped > 0) $msg .= ", {$skipped} already exist";
        if ($conflicts > 0) $msg .= ", {$conflicts} time conflicts";
        flash('success', $msg . '.');
    }

    if ($action === 'delete_offering') {
        $oid = (int) ($_POST['offering_id'] ?? 0);
        if ($oid > 0) {
            execute_sql('DELETE FROM section_subject_offerings WHERE id = :id', ['id' => $oid]);
            flash('success', 'Offering removed.');
        }
    }

    if ($action === 'bulk_delete_offerings' && $canManage) {
        $ids = $_POST['offering_id'] ?? [];
        if (is_array($ids) && count($ids) > 0) {
            $ph = implode(',', array_fill(0, count($ids), '?'));
            execute_sql("DELETE FROM section_subject_offerings WHERE id IN ({$ph})", $ids);
            flash('success', count($ids) . ' offering(s) deleted.');
        }
    }

    redirect('registrar/offerings.php');
}

/* ══════════════════════════════════════════════════════════════════════════
   Data
   ══════════════════════════════════════════════════════════════════════════ */
$terms = fetch_all(
    'SELECT t.id, ay.year_label, t.semester,
            CONCAT(ay.year_label, " / ", CASE t.semester WHEN "1" THEN "1st" WHEN "2" THEN "2nd" WHEN "mid" THEN "Midyear" ELSE t.semester END) AS label,
            t.is_active
     FROM academic_terms t
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

$allSections = fetch_all(
    'SELECT sec.id, p.programs_id AS program_id, p.program_code, sec.year_level, sec.section_name,
            d.department_code,
            CONCAT(p.program_code, " ", sec.year_level, sec.section_name) AS label
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN departments d ON d.dept_id = p.department_id
     WHERE COALESCE(sec.status, "active") = "active"
     ORDER BY d.department_code, p.program_code, sec.year_level, sec.section_name'
);

$programs = fetch_all(
    'SELECT programs_id, program_code, program_name FROM programs ORDER BY program_code'
);

$instructors = fetch_all(
    'SELECT st.staff_id, st.full_name, d.department_code
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     ORDER BY st.full_name'
);

$filterTerm = (int) ($_GET['term_id'] ?? 0);
$filterProgram = (int) ($_GET['program_id'] ?? 0);
$filterSection = (int) ($_GET['section_id'] ?? 0);

$sql = 'SELECT o.id, ay.year_label, t.semester, t.is_active AS term_active,
               p.programs_id, p.program_code, p.program_name,
               d.department_code,
               sec.id AS section_id, sec.year_level, sec.section_name,
               sub.subject_id, sub.subject_code, sub.subject_description, sub.units,
               st.full_name AS instructor_name, st.staff_id AS instructor_id,
               o.day_of_week, o.time_range, o.room, o.max_slots, o.instructor_id AS off_instructor_id,
               o.curriculum_id, o.term_id, o.section_id AS off_section_id
        FROM section_subject_offerings o
        INNER JOIN academic_terms t ON t.id = o.term_id
        INNER JOIN academic_years ay ON ay.id = t.academic_year_id
        INNER JOIN sections sec ON sec.id = o.section_id
        INNER JOIN programs p ON p.programs_id = sec.program_id
        INNER JOIN departments d ON d.dept_id = p.department_id
        INNER JOIN subjects sub ON sub.subject_id = o.subject_id
        LEFT JOIN staff st ON st.staff_id = o.instructor_id
        WHERE 1=1';
$params = [];

if ($filterTerm > 0) {
    $sql .= ' AND o.term_id = :term_id';
    $params['term_id'] = $filterTerm;
}
if ($filterProgram > 0) {
    $sql .= ' AND p.programs_id = :program_id';
    $params['program_id'] = $filterProgram;
}
if ($filterSection > 0) {
    $sql .= ' AND o.section_id = :section_id';
    $params['section_id'] = $filterSection;
}

$sql .= ' ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), p.program_code, sec.year_level, sec.section_name, sub.subject_code';
$offerings = fetch_all($sql, $params);

$grouped = [];
foreach ($offerings as $off) {
    $key = $off['year_label'] . ' / ' . semester_label((string) $off['semester']);
    if (!isset($grouped[$key])) $grouped[$key] = [];
    $grouped[$key][] = $off;
}

$activeTerm = null;
foreach ($terms as $t) {
    if ((int) $t['is_active'] === 1) { $activeTerm = $t; break; }
}

$curriculumByProgramYear = [];
foreach ($programs as $prog) {
    $lines = fetch_all(
        'SELECT pc.curriculum_id, pc.subject_id, pc.year_level, pc.semester,
                sub.subject_code, sub.subject_description, sub.units
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :pid
         ORDER BY CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code',
        ['pid' => (int) $prog['programs_id']]
    );
    foreach ($lines as $line) {
        $k = $prog['programs_id'] . '|' . $line['year_level'];
        if (!isset($curriculumByProgramYear[$k])) $curriculumByProgramYear[$k] = [];
        $curriculumByProgramYear[$k][] = $line;
    }
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Subject Offerings</h1>
        <p>Set up subject offerings per section with schedules, rooms, and instructors.</p>
    </div>
    <?php if ($canManage): ?>
    <div class="actions-row">
        <button class="btn" data-open="addOfferingModal">Add Offering</button>
        <button class="btn secondary" data-open="bulkOfferingModal">Bulk Create</button>
    </div>
    <?php endif; ?>
</div>

<!-- Filters -->
<div class="card" style="margin-bottom:16px;">
    <form method="get">
        <div class="filter-bar">
            <div>
                <label>Academic Year / Semester</label>
                <select name="term_id">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= h((string)$t['id']) ?>" <?= $filterTerm === (int)$t['id'] ? 'selected' : '' ?>><?= h($t['label']) ?><?= (int)$t['is_active'] === 1 ? ' (Active)' : '' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Program</label>
                <select name="program_id">
                    <option value="">All Programs</option>
                    <?php foreach ($programs as $p): ?>
                        <option value="<?= h((string)$p['programs_id']) ?>" <?= $filterProgram === (int)$p['programs_id'] ? 'selected' : '' ?>><?= h($p['program_code'] . ' — ' . $p['program_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>Section</label>
                <select name="section_id">
                    <option value="">All Sections</option>
                    <?php foreach ($allSections as $s): ?>
                        <option value="<?= h((string)$s['id']) ?>" <?= $filterSection === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['label']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-actions">
            <button class="btn secondary" type="submit">Apply Filters</button>
            <?php if ($filterTerm > 0 || $filterProgram > 0 || $filterSection > 0): ?>
                <a class="btn small secondary" href="<?= h(app_url('registrar/offerings.php')) ?>">Clear Filters</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if (empty($grouped)): ?>
    <div class="card" style="text-align:center;padding:32px;color:var(--muted);">
        <span class="material-symbols-outlined" style="font-size:40px;">calendar_month</span>
        <p>No offerings found. Click <strong>Add Offering</strong> or <strong>Bulk Create</strong> to set up subject offerings.</p>
    </div>
<?php else: ?>

<!-- Offerings grouped by term -->
<?php foreach ($grouped as $termLabel => $termOfferings): ?>
<div class="card" style="margin-bottom:16px;">
    <h3 style="margin-bottom:12px;">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;color:var(--primary);">event</span>
        <?= h($termLabel) ?>
        <span class="badge info" style="font-size:11px;margin-left:8px;"><?= h((string)count($termOfferings)) ?> offerings</span>
    </h3>

    <div class="dt" data-dt-page-size="15"
         data-dt-bulk-delete-url="<?= h(app_url('registrar/offerings.php')) ?>"
         data-dt-bulk-id-field="offering_id"
         data-dt-bulk-action="bulk_delete_offerings"
         data-dt-bulk-confirm="Delete selected offerings?">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th data-dt-no-sort data-dt-no-export><input type="checkbox" class="dt-bulk-select-all" aria-label="Select all"></th>
                        <th data-dt-key="section" data-dt-filter="select">Section</th>
                        <th data-dt-key="code" data-dt-filter="select">Code</th>
                        <th data-dt-key="desc">Description</th>
                        <th data-dt-key="units">Units</th>
                        <th data-dt-key="instructor" data-dt-filter="select">Instructor</th>
                        <th data-dt-key="day" data-dt-filter="select">Day</th>
                        <th data-dt-key="time">Time</th>
                        <th data-dt-key="room" data-dt-filter="select">Room</th>
                        <th data-dt-key="slots">Slots</th>
                        <?php if ($canManage): ?><th data-dt-no-sort data-dt-no-export>Actions</th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($termOfferings as $off): ?>
                    <tr data-dt-row-id="<?= h((string)$off['id']) ?>">
                        <td><input type="checkbox" class="dt-bulk-row" value="<?= h((string)$off['id']) ?>" aria-label="Select row"></td>
                        <td data-label="Section"><strong><?= h($off['program_code'] . ' ' . $off['year_level'] . $off['section_name']) ?></strong></td>
                        <td data-label="Code"><span class="badge"><?= h($off['subject_code']) ?></span></td>
                        <td data-label="Description"><?= h($off['subject_description']) ?></td>
                        <td data-label="Units"><?= h((string)$off['units']) ?></td>
                        <td data-label="Instructor"><?= h($off['instructor_name'] ?: '<span class="helper">TBA</span>') ?></td>
                        <td data-label="Day"><?= h($off['day_of_week'] ?: '—') ?></td>
                        <td data-label="Time"><?= h($off['time_range'] ?: '—') ?></td>
                        <td data-label="Room"><?= h($off['room'] ?: '—') ?></td>
                        <td data-label="Slots"><?= h((string)($off['max_slots'] ?? '—')) ?></td>
                        <?php if ($canManage): ?>
                        <td>
                            <div class="row-actions">
                                <button class="icon-btn" type="button" title="Edit"
                                    onclick='openEditOffering(<?= json_encode([
                                        "id"=>$off["id"],
                                        "instructor_id"=>(string)($off["off_instructor_id"] ?? ""),
                                        "room"=>$off["room"] ?: "TBA",
                                        "day"=>$off["day_of_week"] ?: "TBA",
                                        "time"=>$off["time_range"] ?: "TBA",
                                        "max_slots"=>(string)($off["max_slots"] ?? "")
                                    ], JSON_HEX_APOS|JSON_HEX_QUOT) ?>)'>
                                    <span class="material-symbols-outlined">edit</span>
                                </button>
                                <form method="post" onsubmit="return confirm('Remove this offering?');" style="display:inline;">
                                    <input type="hidden" name="action" value="delete_offering">
                                    <input type="hidden" name="offering_id" value="<?= h($off['id']) ?>">
                                    <button class="icon-btn danger" type="submit" title="Remove"><span class="material-symbols-outlined">delete</span></button>
                                </form>
                            </div>
                        </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Timetable Overview -->
<div class="card" style="margin-bottom:16px;">
    <h3>Timetable Overview</h3>
    <p class="helper" style="margin-bottom:12px;">Visual schedule to spot time conflicts at a glance.</p>

    <?php
    $daysOfWeek = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $slotMap = [];
    foreach ($offerings as $off) {
        $day = trim($off['day_of_week'] ?? '');
        $time = trim($off['time_range'] ?? '');
        if ($day === '' || $time === '' || $day === 'TBA') continue;
        $key = $day . '|' . $time;
        if (!isset($slotMap[$key])) $slotMap[$key] = [];
        $slotMap[$key][] = $off;
    }
    ksort($slotMap);
    ?>
    <?php if (empty($slotMap)): ?>
        <p class="helper" style="text-align:center;padding:16px;">No scheduled offerings to display.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table style="font-size:12px;">
            <thead>
                <tr>
                    <th>Time Slot</th>
                    <?php foreach ($daysOfWeek as $day): ?>
                        <th><?= h($day) ?></th>
                    <?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
            <?php
            $timeSlots = [];
            foreach ($slotMap as $key => $offs) {
                $parts = explode('|', $key, 2);
                $time = $parts[1] ?? '';
                if ($time !== '' && !in_array($time, $timeSlots)) $timeSlots[] = $time;
            }
            sort($timeSlots);

            foreach ($timeSlots as $timeSlot):
            ?>
                <tr>
                    <td style="font-weight:600;white-space:nowrap;"><?= h($timeSlot) ?></td>
                    <?php foreach ($daysOfWeek as $day): ?>
                        <td>
                            <?php
                            $cellKey = $day . '|' . $timeSlot;
                            if (isset($slotMap[$cellKey])):
                            ?>
                                <div style="display:flex;flex-direction:column;gap:4px;">
                                <?php foreach ($slotMap[$cellKey] as $soff): ?>
                                    <div style="background:var(--primary-soft);border:1px solid #a7f3d0;border-radius:6px;padding:4px 6px;font-size:11px;">
                                        <strong><?= h($soff['subject_code']) ?></strong>
                                        <div style="color:var(--muted);"><?= h($soff['program_code'] . ' ' . $soff['year_level'] . $soff['section_name']) ?></div>
                                        <div style="color:var(--muted);"><?= h($soff['room']) ?> — <?= h($soff['instructor_name'] ?: 'TBA') ?></div>
                                    </div>
                                <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Add Offering Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$termOptions = '<option value="">— Select Term —</option>';
foreach ($terms as $t) {
    $termOptions .= '<option value="' . h((string)$t['id']) . '">' . h($t['label']) . '</option>';
}

$sectionOptions = '<option value="">— Select Section —</option>';
foreach ($allSections as $s) {
    $sectionOptions .= '<option value="' . h((string)$s['id']) . '" data-year="' . h((string)$s['year_level']) . '" data-program="' . h((string)$s['program_id']) . '">' . h($s['label']) . '</option>';
}
?>
<?= render_modal('addOfferingModal', 'Add Section Offering', '
<form method="post">
    <input type="hidden" name="action" value="add_offering">
    <div class="form-grid">
        <div>
            <label>Academic Year / Semester</label>
            <select name="term_id" required>
                ' . $termOptions . '
            </select>
        </div>
        <div>
            <label>Section</label>
            <select name="section_id" id="offering_section" required>
                ' . $sectionOptions . '
            </select>
        </div>
        <div>
            <label>Curriculum Line</label>
            <select name="curriculum_id" id="offering_curriculum" required>
                <option value="">Select curriculum line...</option>
            </select>
        </div>
        <div>
            <label>Subject</label>
            <input type="text" id="offering_subject_display" readonly placeholder="Auto-filled from curriculum">
            <input type="hidden" name="subject_id" id="offering_subject_id">
        </div>
        <div>
            <label>Instructor</label>
            <select name="instructor_id">
                <option value="">— TBA —</option>
                ' . implode('', array_map(fn($i) => '<option value="' . h((string)$i['staff_id']) . '">' . h($i['full_name'] . ' [' . ($i['department_code'] ?: 'No Dept') . ']') . '</option>', $instructors)) . '
            </select>
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" placeholder="Optional" min="1">
        </div>
    </div>
    <div class="form-grid cols-3" style="margin-top:14px;">
        <div>
            <label>Day</label>
            <select name="day_of_week">
                <option value="">TBA</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
                <option value="Saturday">Saturday</option>
            </select>
        </div>
        <div>
            <label>Time Range</label>
            <input type="text" name="time_range" placeholder="e.g. 8:00-10:00">
        </div>
        <div>
            <label>Room</label>
            <input type="text" name="room" placeholder="e.g. Rm 301" value="TBA">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Create Offering</button>
    </div>
</form>
<script>
(function(){
    var secSel = document.getElementById(\'offering_section\');
    var currSel = document.getElementById(\'offering_curriculum\');
    var subjDisp = document.getElementById(\'offering_subject_display\');
    var subjId = document.getElementById(\'offering_subject_id\');
    if (!secSel || !currSel) return;

    var allCurriculum = ' . json_encode($curriculumByProgramYear, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';

    function filterCurriculum() {
        var secOpt = secSel.options[secSel.selectedIndex];
        if (!secOpt || !secOpt.value) {
            currSel.innerHTML = \'<option value="">Select curriculum line...</option>\';
            return;
        }
        var progId = secOpt.dataset.program || \'\';
        var yr = secOpt.dataset.year || \'\';
        var key = progId + \'|\' + yr;
        var lines = allCurriculum[key] || [];
        currSel.innerHTML = \'<option value="">Select curriculum line...</option>\';
        lines.forEach(function(l) {
            var opt = document.createElement(\'option\');
            opt.value = l.curriculum_id;
            opt.textContent = \'Y\' + l.year_level + \' \' + l.semester + \' — \' + l.subject_code + \' \' + l.subject_description;
            opt.dataset.subject_id = l.subject_id;
            opt.dataset.subject_code = l.subject_code;
            opt.dataset.subject_desc = l.subject_description;
            currSel.appendChild(opt);
        });
    }
    secSel.addEventListener(\'change\', function() {
        filterCurriculum();
        if (subjDisp) subjDisp.value = \'\';
        if (subjId) subjId.value = \'\';
    });
    currSel.addEventListener(\'change\', function() {
        var opt = currSel.options[currSel.selectedIndex];
        if (subjDisp && opt && opt.value) {
            subjDisp.value = opt.dataset.subject_code + \' — \' + opt.dataset.subject_desc;
        }
        if (subjId && opt && opt.value) {
            subjId.value = opt.dataset.subject_id;
        }
    });
})();
</script>
') ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Edit Offering Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?= render_modal('editOfferingModal', 'Edit Offering', '
<form method="post">
    <input type="hidden" name="action" value="update_offering">
    <input type="hidden" name="offering_id" id="eo_id">
    <div class="form-grid">
        <div>
            <label>Instructor</label>
            <select name="instructor_id" id="eo_instructor">
                <option value="">— TBA —</option>
                ' . implode('', array_map(fn($i) => '<option value="' . h((string)$i['staff_id']) . '">' . h($i['full_name']) . '</option>', $instructors)) . '
            </select>
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" id="eo_max_slots" placeholder="Optional" min="1">
        </div>
    </div>
    <div class="form-grid cols-3" style="margin-top:14px;">
        <div>
            <label>Day</label>
            <select name="day_of_week" id="eo_day">
                <option value="">TBA</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
                <option value="Saturday">Saturday</option>
            </select>
        </div>
        <div>
            <label>Time Range</label>
            <input type="text" name="time_range" id="eo_time" placeholder="e.g. 8:00-10:00">
        </div>
        <div>
            <label>Room</label>
            <input type="text" name="room" id="eo_room" placeholder="e.g. Rm 301">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Update Offering</button>
    </div>
</form>
') ?>

<!-- ══════════════════════════════════════════════════════════════════════════
     Bulk Create Offerings Modal
     ══════════════════════════════════════════════════════════════════════════ -->
<?php
$curriculumListHtml = '';
foreach ($programs as $prog) {
    foreach ($curriculumByProgramYear as $key => $lines) {
        $parts = explode('|', $key);
        $progId = (int) ($parts[0] ?? 0);
        $year = $parts[1] ?? '';
        if ($progId !== (int) $prog['programs_id']) continue;
        foreach ($lines as $line) {
            $curriculumListHtml .= '<option value="' . h((string)$line['curriculum_id']) . '" data-year="' . h($year) . '" data-program="' . h((string)$progId) . '">'
                . h($prog['program_code'] . ' Y' . $year . ' ' . $line['semester'] . ' — ' . $line['subject_code'] . ' ' . $line['subject_description'])
                . '</option>';
        }
    }
}
?>
<?= render_modal('bulkOfferingModal', 'Bulk Create Offerings', '
<form method="post">
    <input type="hidden" name="action" value="bulk_create_offerings">
    <div class="form-grid">
        <div>
            <label>Academic Year / Semester</label>
            <select name="bulk_term_id" required>
                ' . $termOptions . '
            </select>
        </div>
        <div>
            <label>Section</label>
            <select name="bulk_section_id" id="bulk_section" required>
                ' . $sectionOptions . '
            </select>
        </div>
        <div>
            <label>Instructor (Optional)</label>
            <select name="bulk_instructor_id">
                <option value="">— TBA —</option>
                ' . implode('', array_map(fn($i) => '<option value="' . h((string)$i['staff_id']) . '">' . h($i['full_name']) . '</option>', $instructors)) . '
            </select>
        </div>
    </div>
    <div class="form-grid cols-3" style="margin-top:14px;">
        <div>
            <label>Day (Optional)</label>
            <select name="bulk_day_of_week">
                <option value="">TBA</option>
                <option value="Monday">Monday</option>
                <option value="Tuesday">Tuesday</option>
                <option value="Wednesday">Wednesday</option>
                <option value="Thursday">Thursday</option>
                <option value="Friday">Friday</option>
                <option value="Saturday">Saturday</option>
            </select>
        </div>
        <div>
            <label>Time Range (Optional)</label>
            <input type="text" name="bulk_time_range" placeholder="e.g. 8:00-10:00">
        </div>
        <div>
            <label>Room (Optional)</label>
            <input type="text" name="bulk_room" placeholder="e.g. Rm 301" value="TBA">
        </div>
    </div>
    <div style="margin-top:18px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
            <label style="margin:0;">Select Curriculum Lines</label>
            <div>
                <button type="button" class="btn small secondary" onclick="selectAllBulk()">Select All</button>
                <button type="button" class="btn small secondary" onclick="deselectAllBulk()">Deselect All</button>
                <span id="bulk_selected_count" class="helper" style="margin-left:8px;">0 selected</span>
            </div>
        </div>
        <div id="bulk_curriculum_list" style="max-height:300px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;padding:8px;">
            <p class="helper">Select a section to load curriculum lines.</p>
        </div>
        <input type="hidden" name="bulk_year_level" id="bulk_year_level">
        <input type="hidden" name="bulk_semester" id="bulk_semester">
    </div>
    <div class="form-actions" style="margin-top:14px;">
        <button class="btn" type="submit">Create All Selected Offerings</button>
    </div>
</form>
<script>
var bulkCurriculumData = ' . json_encode($curriculumByProgramYear, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';
var allPrograms = ' . json_encode($programs, JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT) . ';

document.getElementById(\'bulk_section\').addEventListener(\'change\', function() {
    var secOpt = this.options[this.selectedIndex];
    if (!secOpt || !secOpt.value) {
        document.getElementById(\'bulk_curriculum_list\').innerHTML = \'<p class="helper">Select a section to load curriculum lines.</p>\';
        return;
    }
    var progId = secOpt.dataset.program || \'\';
    var yr = secOpt.dataset.year || \'\';
    var key = progId + \'|\' + yr;
    var lines = bulkCurriculumData[key] || [];
    var progName = \'\';
    allPrograms.forEach(function(p) { if (p.programs_id == progId) progName = p.program_code; });
    var listDiv = document.getElementById(\'bulk_curriculum_list\');
    listDiv.innerHTML = \'\';
    if (lines.length === 0) {
        listDiv.innerHTML = \'<p class="helper">No curriculum lines for \' + progName + \' Year \' + yr + \'.</p>\';
        return;
    }
    lines.forEach(function(l) {
        var div = document.createElement(\'div\');
        div.style.cssText = \'display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);\';
        div.innerHTML = \'<input type="checkbox" class="bulk-curr-check" name="bulk_curriculum_ids[]" value="\' + l.curriculum_id + \'" onchange="updateCount()"><span style="font-size:13px;"><strong>\' + l.subject_code + \'</strong> \' + l.subject_description + \' (\' + l.units + \'u)</span>\';
        listDiv.appendChild(div);
    });
    updateCount();
});

function selectAllBulk() {
    document.querySelectorAll(\'.bulk-curr-check\').forEach(function(c) { c.checked = true; });
    updateCount();
}
function deselectAllBulk() {
    document.querySelectorAll(\'.bulk-curr-check\').forEach(function(c) { c.checked = false; });
    updateCount();
}
function updateCount() {
    var n = document.querySelectorAll(\'.bulk-curr-check:checked\').length;
    document.getElementById(\'bulk_selected_count\').textContent = n + \' selected\';
}
</script>
') ?>

<?php
$content = ob_get_clean();
render_page('Subject Offerings', 'Subject Offerings', (string) $content, [
    'modals' => []
]);
?>
<script>
function openEditOffering(o) {
    document.getElementById('eo_id').value = o.id;
    document.getElementById('eo_instructor').value = o.instructor_id || '';
    document.getElementById('eo_room').value = o.room || 'TBA';
    document.getElementById('eo_day').value = o.day || 'TBA';
    document.getElementById('eo_time').value = o.time || 'TBA';
    document.getElementById('eo_max_slots').value = o.max_slots || '';
    document.getElementById('editOfferingModal').classList.add('active');
}
</script>
