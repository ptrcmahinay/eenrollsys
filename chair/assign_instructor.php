<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

$chairDeptId = (int) $staff['dept_id'];
$currentTerm = current_term();

if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'assign_instructor') {
        $instructorId = (int) ($_POST['instructor_id'] ?? 0);
        $schedCode = trim($_POST['sched_code'] ?? '');

        if ($instructorId <= 0 || $schedCode === '') {
            flash('error', 'Please select both an instructor and a sched code.');
            redirect('chair/assign_instructor.php');
        }

        // Validate the sched code belongs to the current term and chair's department
        $offering = db()->prepare(
            "SELECT o.id, o.sched_code, o.subject_id, o.instructor_id, sub.teaching_department_id
             FROM section_subject_offerings o
             INNER JOIN subjects sub ON sub.subject_id = o.subject_id
             WHERE o.sched_code = :sched_code AND o.term_id = :term_id AND o.status = 'active'"
        );
        $offering->execute(['sched_code' => $schedCode, 'term_id' => (int) $currentTerm['id']]);
        $offering = $offering->fetch(PDO::FETCH_ASSOC);

        if ($offering === null) {
            flash('error', 'Sched code not found for the current term.');
            redirect('chair/assign_instructor.php');
        }
        if ((int) $offering['teaching_department_id'] !== $chairDeptId) {
            flash('error', 'You can only assign instructors to subjects taught by your department.');
            redirect('chair/assign_instructor.php');
        }

        // Validate instructor belongs to chair's department
        $instructor = db()->prepare(
            "SELECT st.staff_id, st.dept_id, st.status
             FROM staff st
             INNER JOIN user_roles ur ON ur.user_id = st.users_id
             INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = 'instructor'
             WHERE st.staff_id = :sid AND st.status = 'active'"
        );
        $instructor->execute(['sid' => $instructorId]);
        $instructor = $instructor->fetch(PDO::FETCH_ASSOC);

        if ($instructor === null) {
            flash('error', 'Selected instructor is not valid or not active.');
            redirect('chair/assign_instructor.php');
        }
        if ((int) $instructor['dept_id'] !== $chairDeptId) {
            flash('error', 'Instructor must belong to your department.');
            redirect('chair/assign_instructor.php');
        }

        // Check if class_schedules entry exists for this sched_code
        $existing = db()->prepare(
            "SELECT id, instructor_id FROM class_schedules WHERE schedule_code = :sched_code LIMIT 1"
        );
        $existing->execute(['sched_code' => $schedCode]);
        $existing = $existing->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            // Update existing class_schedules entry
            db()->prepare(
                "UPDATE class_schedules SET instructor_id = :iid WHERE id = :id"
            )->execute(['iid' => $instructorId, 'id' => (int) $existing['id']]);
        } else {
            // Create new class_schedules entry
            db()->prepare(
                "INSERT INTO class_schedules (schedule_code, subject_id, section_id, term_id, instructor_id, created_by, created_at)
                 SELECT :sched_code, o.subject_id, o.section_id, o.term_id, :iid, :created_by, NOW()
                 FROM section_subject_offerings o
                 WHERE o.sched_code = :sched_code2"
            )->execute([
                'sched_code' => $schedCode,
                'sched_code2' => $schedCode,
                'iid' => $instructorId,
                'created_by' => (int) $staff['staff_id'],
            ]);
        }

        // Also update the legacy instructor_id on the offering
        db()->prepare(
            "UPDATE section_subject_offerings SET instructor_id = :iid WHERE id = :oid"
        )->execute(['iid' => $instructorId, 'oid' => (int) $offering['id']]);

        flash('success', "Instructor assigned to sched code {$schedCode}.");
        redirect('chair/assign_instructor.php');
    }

    if ($action === 'unassign_instructor') {
        $schedCode = trim($_POST['sched_code'] ?? '');
        if ($schedCode !== '') {
            // Validate ownership
            $offering = db()->prepare(
                "SELECT o.id, sub.teaching_department_id
                 FROM section_subject_offerings o
                 INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                 WHERE o.sched_code = :sched_code AND o.term_id = :term_id AND o.status = 'active'"
            );
            $offering->execute(['sched_code' => $schedCode, 'term_id' => (int) $currentTerm['id']]);
            $offering = $offering->fetch(PDO::FETCH_ASSOC);

            if ($offering !== null && (int) $offering['teaching_department_id'] === $chairDeptId) {
                // Clear instructor from class_schedules
                db()->prepare(
                    "UPDATE class_schedules SET instructor_id = NULL WHERE schedule_code = :sched_code"
                )->execute(['sched_code' => $schedCode]);

                // Clear legacy instructor_id on offering
                db()->prepare(
                    "UPDATE section_subject_offerings SET instructor_id = NULL WHERE id = :oid"
                )->execute(['oid' => (int) $offering['id']]);

                flash('success', "Instructor unassigned from sched code {$schedCode}.");
            } else {
                flash('error', 'You can only unassign subjects taught by your department.');
            }
        }
        redirect('chair/assign_instructor.php');
    }
}

// Get instructors in chair's department
$instructors = db()->prepare(
    "SELECT st.staff_id, st.full_name, d.department_code,
            (SELECT COUNT(DISTINCT cs2.schedule_code)
             FROM class_schedules cs2
             INNER JOIN section_subject_offerings o2 ON o2.sched_code = cs2.schedule_code
             INNER JOIN subjects sub2 ON sub2.subject_id = o2.subject_id
             WHERE cs2.instructor_id = st.staff_id
               AND sub2.teaching_department_id = :dept_teaching
               AND o2.term_id = :term_id) AS subject_count
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = 'instructor'
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     WHERE st.dept_id = :dept_chair AND st.status = 'active'
     ORDER BY st.full_name"
);
$instructors->execute([
    'dept_chair' => $chairDeptId,
    'dept_teaching' => $chairDeptId,
    'term_id' => (int) $currentTerm['id'],
]);
$instructors = $instructors->fetchAll(PDO::FETCH_ASSOC);

// Get unassigned offerings (sched codes) for current term in chair's department
$unassignedOfferings = db()->prepare(
    "SELECT o.id, o.sched_code,
            sub.subject_code, sub.subject_description,
            p.program_code, sec.year_level, sec.section_name,
            COALESCE(cs.instructor_id, o.instructor_id) AS current_instructor_id
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
     WHERE sub.teaching_department_id = :dept_teaching
       AND o.term_id = :term_id
       AND o.status = 'active'
       AND COALESCE(cs.instructor_id, o.instructor_id) IS NULL
     ORDER BY sub.subject_code, p.program_code, sec.year_level, sec.section_name"
);
$unassignedOfferings->execute([
    'dept_teaching' => $chairDeptId,
    'term_id' => (int) $currentTerm['id'],
]);
$unassignedOfferings = $unassignedOfferings->fetchAll(PDO::FETCH_ASSOC);

// Get all offerings for current term in chair's department with instructor info
$allOfferings = db()->prepare(
    "SELECT o.id, o.sched_code,
            sub.subject_code, sub.subject_description,
            p.program_code, sec.year_level, sec.section_name,
            COALESCE(cs.instructor_id, o.instructor_id) AS assigned_instructor_id,
            COALESCE(inst.full_name, 'TBA') AS instructor_name
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     LEFT JOIN class_schedules cs ON cs.schedule_code = o.sched_code
     LEFT JOIN staff inst ON inst.staff_id = COALESCE(cs.instructor_id, o.instructor_id)
     WHERE sub.teaching_department_id = :dept_teaching
       AND o.term_id = :term_id
       AND o.status = 'active'
     ORDER BY sub.subject_code, p.program_code, sec.year_level, sec.section_name"
);
$allOfferings->execute([
    'dept_teaching' => $chairDeptId,
    'term_id' => (int) $currentTerm['id'],
]);
$allOfferings = $allOfferings->fetchAll(PDO::FETCH_ASSOC);

// Get assigned subjects per instructor
$instructorSubjects = [];
foreach ($instructors as $inst) {
    $stmt = db()->prepare(
        "SELECT o.sched_code,
                sub.subject_code, sub.subject_description,
                p.program_code, sec.year_level, sec.section_name,
                COALESCE(cs.day, '') AS day_of_week,
                COALESCE(cs.time_range, '') AS time_range,
                COALESCE(cs.room, '') AS room
         FROM class_schedules cs
         INNER JOIN section_subject_offerings o ON o.sched_code = cs.schedule_code
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         WHERE cs.instructor_id = :instructor_id
           AND sub.teaching_department_id = :dept_teaching
           AND o.term_id = :term_id
           AND o.status = 'active'
         ORDER BY sub.subject_code, sec.year_level, sec.section_name"
    );
    $stmt->execute([
        'instructor_id' => (int) $inst['staff_id'],
        'dept_teaching' => $chairDeptId,
        'term_id' => (int) $currentTerm['id'],
    ]);
    $instructorSubjects[$inst['staff_id']] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$departmentOptions = '';
foreach ($instructors as $inst) {
    $dept = ($inst['department_code'] ?: 'No Dept');
    $departmentOptions .= '<option value="' . h($inst['staff_id']) . '">'
        . h($inst['full_name']) . ' [' . $dept . ']</option>';
}

$flashes = get_flashes();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Instructor Assignments by Sched Code</h1>
        <p>Assign instructors to sched codes for the current term. Assignments reset when a new term is created.</p>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <h3 style="margin:0 0 12px;font-size:15px;">Assign Instructor to Sched Code</h3>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="assign_instructor">
        <div style="flex:1;min-width:200px;">
            <label for="assign_instructor">Instructor</label>
            <select id="assign_instructor" name="instructor_id" required>
                <option value="">— Select Instructor —</option>
                <?= $departmentOptions ?>
            </select>
        </div>
        <div style="flex:2;min-width:300px;position:relative;z-index:10000;">
            <label for="assign_sched_code">Unassigned Sched Code</label>
            <div style="position:relative;">
                <input type="text" id="offering_search" placeholder="Search by sched code, subject, or section..." autocomplete="off"
                       style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid var(--border);border-radius:6px;">
                <input type="hidden" name="sched_code" id="assign_sched_code" required>
                <div id="offering_dropdown" style="display:none;position:absolute;top:100%;left:0;right:0;max-height:300px;overflow-y:auto;background:#ffffff;border:1px solid #cbd5e1;border-radius:6px;z-index:99999;box-shadow:0 4px 12px rgba(0,0,0,0.2);">
                    <?php foreach ($unassignedOfferings as $o): ?>
                        <div class="offering-option" data-sched="<?= h($o['sched_code']) ?>"
                             data-search="<?= h(strtolower(($o['sched_code'] ?? '') . ' ' . ($o['subject_code'] ?? '') . ' ' . ($o['subject_description'] ?? '') . ' ' . ($o['program_code'] ?? '') . ' ' . ($o['section_name'] ?? ''))) ?>"
                             style="padding:8px 12px;cursor:pointer;border-bottom:1px solid var(--line);font-size:13px;">
                            <strong><?= h($o['sched_code']) ?></strong> — <?= h($o['subject_code']) ?> <?= h($o['subject_description']) ?>
                            <br><span style="color:#64748b;font-size:11px;"><?= h($o['program_code'] . ' Yr' . $o['year_level'] . $o['section_name']) ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if ($unassignedOfferings === []): ?>
                        <div style="padding:12px;color:#94a3b8;text-align:center;font-size:13px;">No unassigned sched codes.</div>
                    <?php endif; ?>
                </div>
            </div>
            <div id="offering_selected" style="margin-top:4px;font-size:12px;color:#64748b;min-height:18px;"></div>
        </div>
        <button class="btn" type="submit" style="height:38px;">
            <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">person_add</span>
            Assign
        </button>
    </form>
</div>

<?php if ($instructors === []): ?>
    <div class="card" style="text-align:center;padding:40px;color:#94a3b8;">
        No instructors found in this department.
    </div>
<?php else: ?>
    <div class="card">
        <h3 style="margin:0 0 16px;font-size:15px;">Instructors &amp; Assigned Sched Codes</h3>
        <div class="dt" data-dt-page-size="10">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Instructor</th>
                            <th>Department</th>
                            <th>Assigned Sched Codes</th>
                            <th data-dt-no-sort>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($instructors as $inst): ?>
                        <tr>
                            <td><strong><?= h($inst['full_name']) ?></strong></td>
                            <td>
                                <span class="badge"><?= h($inst['department_code'] ?: 'No Dept') ?></span>
                            </td>
                            <td>
                                <span class="badge count-badge"><?= (int) $inst['subject_count'] ?></span>
                                <?php if (!empty($instructorSubjects[$inst['staff_id']])): ?>
                                    <div style="margin-top:4px;font-size:11px;color:#64748b;">
                                        <?php
                                        $codes = array_map(fn($s) => $s['sched_code'], $instructorSubjects[$inst['staff_id']]);
                                        echo h(implode(', ', array_unique($codes)));
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button class="btn secondary small" type="button" data-open="modal-view-subjects"
                                        data-name="<?= h($inst['full_name']) ?>"
                                        data-instructor-id="<?= h($inst['staff_id']) ?>">
                                    <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">visibility</span>
                                    View Sched Codes
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- All Sched Codes Overview -->
<div class="card" style="margin-top:16px;">
    <h3 style="margin:0 0 16px;font-size:15px;">All Sched Codes — Current Term</h3>
    <div class="dt" data-dt-page-size="15">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Sched Code</th>
                        <th>Subject</th>
                        <th>Section</th>
                        <th>Instructor</th>
                        <th data-dt-no-sort>Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($allOfferings as $o): ?>
                    <tr>
                        <td><strong><?= h($o['sched_code']) ?></strong></td>
                        <td><?= h($o['subject_code'] . ' - ' . $o['subject_description']) ?></td>
                        <td><?= h($o['program_code'] . ' ' . $o['year_level'] . $o['section_name']) ?></td>
                        <td>
                            <?php if ($o['assigned_instructor_id']): ?>
                                <span class="badge success"><?= h($o['instructor_name']) ?></span>
                            <?php else: ?>
                                <span class="badge secondary">TBA</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($o['assigned_instructor_id']): ?>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Unassign instructor from <?= h($o['sched_code']) ?>?');">
                                    <input type="hidden" name="action" value="unassign_instructor">
                                    <input type="hidden" name="sched_code" value="<?= h($o['sched_code']) ?>">
                                    <button class="action-btn danger" type="submit" title="Unassign">
                                        <span class="material-symbols-outlined" style="font-size:18px;">remove_circle</span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$subjectDetailRows = '';
foreach ($instructorSubjects as $instId => $subjects) {
    foreach ($subjects as $s) {
        $subjectDetailRows .= '<tr data-instructor-id="' . $instId . '">'
            . '<td style="font-family:monospace;font-size:11px;">' . h($s['sched_code'] ?? '—') . '</td>'
            . '<td>' . h($s['subject_code']) . '</td>'
            . '<td>' . h($s['subject_description']) . '</td>'
            . '<td>' . h($s['program_code'] . ' Yr' . $s['year_level'] . $s['section_name']) . '</td>'
            . '<td>' . h(($s['day_of_week'] ?? '') . ' ' . ($s['time_range'] ?? '')) . '</td>'
            . '<td>' . h($s['room'] ?: 'TBA') . '</td>'
            . '<td>'
                . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Unassign instructor from ' . h($s['sched_code']) . '?\');">'
                    . '<input type="hidden" name="action" value="unassign_instructor">'
                    . '<input type="hidden" name="sched_code" value="' . h($s['sched_code']) . '">'
                    . '<button class="action-btn danger" type="submit" title="Unassign">'
                        . '<span class="material-symbols-outlined" style="font-size:18px;">remove_circle</span>'
                    . '</button>'
                . '</form>'
            . '</td>'
            . '</tr>';
    }
}
?>

<div id="modal-view-subjects" class="modal">
    <div class="modal-box" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="modal-subject-title">Assigned Sched Codes</h3>
            <button class="modal-close" data-close="modal-view-subjects">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Sched Code</th>
                            <th>Code</th>
                            <th>Subject</th>
                            <th>Section</th>
                            <th>Schedule</th>
                            <th>Room</th>
                            <th data-dt-no-sort></th>
                        </tr>
                    </thead>
                    <tbody id="modal-subjects-body">
                    <?= $subjectDetailRows ?>
                    </tbody>
                </table>
            </div>
            <p id="modal-no-subjects" style="text-align:center;color:#94a3b8;padding:20px;display:none;">No sched codes assigned to this instructor.</p>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-open="modal-view-subjects"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var name = this.getAttribute('data-name');
        var instId = this.getAttribute('data-instructor-id');
        document.getElementById('modal-subject-title').textContent = 'Sched Codes — ' + name;
        var allRows = document.querySelectorAll('#modal-subjects-body tr');
        var hasRows = false;
        allRows.forEach(function(row) {
            if (row.getAttribute('data-instructor-id') === instId) {
                row.style.display = '';
                hasRows = true;
            } else {
                row.style.display = 'none';
            }
        });
        document.getElementById('modal-no-subjects').style.display = hasRows ? 'none' : 'block';
        document.getElementById('modal-view-subjects').classList.add('active');
    });
});

(function() {
    var searchInput = document.getElementById('offering_search');
    var dropdown = document.getElementById('offering_dropdown');
    var hiddenInput = document.getElementById('assign_sched_code');
    var selectedDisplay = document.getElementById('offering_selected');
    if (!searchInput || !dropdown) return;

    var options = dropdown.querySelectorAll('.offering-option');

    searchInput.addEventListener('focus', function() {
        dropdown.style.display = 'block';
    });

    searchInput.addEventListener('input', function() {
        var q = this.value.toLowerCase();
        dropdown.style.display = 'block';
        options.forEach(function(opt) {
            var match = opt.getAttribute('data-search').indexOf(q) !== -1;
            opt.style.display = match ? '' : 'none';
        });
    });

    options.forEach(function(opt) {
        opt.addEventListener('click', function() {
            var schedCode = this.getAttribute('data-sched');
            hiddenInput.value = schedCode;
            searchInput.value = schedCode + ' — ' + this.textContent.replace(schedCode, '').replace(/^\s+—\s+/, '').replace(/^\s+/, '');
            selectedDisplay.textContent = 'Selected: ' + schedCode;
            dropdown.style.display = 'none';
        });
        opt.addEventListener('mouseenter', function() {
            this.style.background = 'var(--line)';
        });
        opt.addEventListener('mouseleave', function() {
            this.style.background = '';
        });
    });

    document.addEventListener('click', function(e) {
        if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
            dropdown.style.display = 'none';
        }
    });
})();
</script>
<?php
render_page('Instructor Assignments', 'Instructor Assignments', (string) ob_get_clean());
