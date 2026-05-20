<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'assign_subject') {
        $instructorId = (int) ($_POST['instructor_id'] ?? 0);
        $offeringId = (int) ($_POST['offering_id'] ?? 0);
        if ($instructorId > 0 && $offeringId > 0) {
            execute_sql(
                'UPDATE section_subject_offerings SET instructor_id = :instructor_id WHERE id = :offering_id',
                ['instructor_id' => $instructorId, 'offering_id' => $offeringId]
            );
            flash('success', 'Subject assigned to instructor.');
        }
        redirect('chair/assign_instructor.php');
    }

    if ($action === 'unassign_subject') {
        $offeringId = (int) ($_POST['offering_id'] ?? 0);
        if ($offeringId > 0) {
            execute_sql(
                'UPDATE section_subject_offerings SET instructor_id = NULL WHERE id = :offering_id',
                ['offering_id' => $offeringId]
            );
            flash('success', 'Subject unassigned from instructor.');
        }
        redirect('chair/assign_instructor.php');
    }
}

$asdDepartment = fetch_one('SELECT dept_id FROM departments WHERE department_code = "ASD" LIMIT 1');
$asdDeptId = (int) ($asdDepartment['dept_id'] ?? 0);

$instructors = fetch_all(
    'SELECT st.staff_id, st.full_name, d.department_code,
            (SELECT COUNT(*) FROM section_subject_offerings o
             INNER JOIN sections sec ON sec.id = o.section_id
             INNER JOIN programs p ON p.programs_id = sec.program_id
             WHERE o.instructor_id = st.staff_id AND p.department_id = :dept_sub) AS subject_count
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     LEFT JOIN departments d ON d.dept_id = st.dept_id
     WHERE st.dept_id = :dept_main OR st.dept_id = :asd_dept_id
     ORDER BY st.full_name',
    ['dept_main' => (int) $staff['dept_id'], 'dept_sub' => (int) $staff['dept_id'], 'asd_dept_id' => $asdDeptId]
);

$unassignedOfferings = fetch_all(
    'SELECT o.id, sub.subject_code, sub.subject_description,
            p.program_code, sec.year_level, sec.section_name
     FROM section_subject_offerings o
     INNER JOIN sections sec ON sec.id = o.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN subjects sub ON sub.subject_id = o.subject_id
     WHERE p.department_id = :department_id AND o.instructor_id IS NULL
     ORDER BY sub.subject_code, sec.year_level, sec.section_name',
    ['department_id' => (int) $staff['dept_id']]
);

$instructorSubjects = [];
foreach ($instructors as $inst) {
    $instructorSubjects[$inst['staff_id']] = fetch_all(
        'SELECT o.id, sub.subject_code, sub.subject_description,
                p.program_code, sec.year_level, sec.section_name, o.day_of_week, o.time_range, o.room
         FROM section_subject_offerings o
         INNER JOIN sections sec ON sec.id = o.section_id
         INNER JOIN programs p ON p.programs_id = sec.program_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         WHERE o.instructor_id = :instructor_id AND p.department_id = :department_id
         ORDER BY sub.subject_code, sec.year_level, sec.section_name',
        ['instructor_id' => (int) $inst['staff_id'], 'department_id' => (int) $staff['dept_id']]
    );
}

$departmentOptions = '';
foreach ($instructors as $inst) {
    $dept = ($inst['department_code'] ?: 'No Dept');
    $isAsd = $inst['department_code'] === 'ASD';
    $departmentOptions .= '<option value="' . h($inst['staff_id']) . '" data-asd="' . ($isAsd ? '1' : '0') . '">'
        . h($inst['full_name']) . ' [' . $dept . ']</option>';
}

$flashes = get_flashes();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Instructor Subject Assignments</h1>
        <p>Assign subjects to instructors in your department. ASD instructors may handle general education subjects (GNED, NSTP, FITT).</p>
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
    <h3 style="margin:0 0 12px;font-size:15px;">Assign Subject to Instructor</h3>
    <form method="post" style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
        <input type="hidden" name="action" value="assign_subject">
        <div style="flex:1;min-width:200px;">
            <label for="assign_instructor">Instructor</label>
            <select id="assign_instructor" name="instructor_id" required>
                <option value="">— Select Instructor —</option>
                <?= $departmentOptions ?>
            </select>
        </div>
        <div style="flex:1;min-width:200px;">
            <label for="assign_offering">Unassigned Subject</label>
            <select id="assign_offering" name="offering_id" required>
                <option value="">— Select Subject —</option>
                <?php foreach ($unassignedOfferings as $o): ?>
                    <?php
                    $isGned = preg_match('/^(GNED|NSTP|FITT|CVSU)/i', (string) $o['subject_code']) === 1;
                    ?>
                    <option value="<?= h($o['id']) ?>" <?= $isGned ? 'data-gned="1"' : '' ?>>
                        <?= h($o['subject_code'] . ' - ' . $o['subject_description'] . ' [' . $o['program_code'] . ' Yr' . $o['year_level'] . $o['section_name'] . ']') ?>
                    </option>
                <?php endforeach; ?>
            </select>
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
        <h3 style="margin:0 0 16px;font-size:15px;">Instructors &amp; Assigned Subjects</h3>
        <div class="dt" data-dt-page-size="10">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Instructor</th>
                            <th>Department</th>
                            <th>Assigned Subjects</th>
                            <th data-dt-no-sort>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($instructors as $inst): ?>
                        <tr>
                            <td><strong><?= h($inst['full_name']) ?></strong></td>
                            <td>
                                <span class="badge <?= $inst['department_code'] === 'ASD' ? 'info' : '' ?>">
                                    <?= h($inst['department_code'] ?: 'No Dept') ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge count-badge"><?= (int) $inst['subject_count'] ?></span>
                                <?php if (!empty($instructorSubjects[$inst['staff_id']])): ?>
                                    <div style="margin-top:4px;font-size:11px;color:#64748b;">
                                        <?php
                                        $codes = array_map(fn($s) => $s['subject_code'], $instructorSubjects[$inst['staff_id']]);
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
                                    View Subjects
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

<?php
$subjectDetailRows = '';
foreach ($instructorSubjects as $instId => $subjects) {
    foreach ($subjects as $s) {
        $subjectDetailRows .= '<tr data-instructor-id="' . $instId . '">'
            . '<td>' . h($s['subject_code']) . '</td>'
            . '<td>' . h($s['subject_description']) . '</td>'
            . '<td>' . h($s['program_code'] . ' Yr' . $s['year_level'] . $s['section_name']) . '</td>'
            . '<td>' . h(($s['day_of_week'] ?? '') . ' ' . ($s['time_range'] ?? '')) . '</td>'
            . '<td>' . h($s['room'] ?? 'TBA') . '</td>'
            . '<td>'
                . '<form method="post" style="display:inline;" onsubmit="return confirm(\'Unassign this subject?\');">'
                    . '<input type="hidden" name="action" value="unassign_subject">'
                    . '<input type="hidden" name="offering_id" value="' . h($s['id']) . '">'
                    . '<button class="action-btn danger" type="submit" title="Unassign">'
                        . '<span class="material-symbols-outlined" style="font-size:18px;">remove_circle</span>'
                    . '</button>'
                . '</form>'
            . '</td>'
            . '</tr>';
    }
}
?>

<div id="modal-view-subjects" class="modal-overlay" style="display:none;">
    <div class="modal" style="max-width:700px;">
        <div class="modal-header">
            <h3 id="modal-subject-title">Assigned Subjects</h3>
            <button class="modal-close" data-close="modal-view-subjects">&times;</button>
        </div>
        <div class="modal-body">
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
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
            <p id="modal-no-subjects" style="text-align:center;color:#94a3b8;padding:20px;display:none;">No subjects assigned to this instructor.</p>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('[data-open="modal-view-subjects"]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var name = this.getAttribute('data-name');
        var instId = this.getAttribute('data-instructor-id');
        document.getElementById('modal-subject-title').textContent = 'Subjects - ' + name;
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
        document.getElementById('modal-view-subjects').style.display = 'flex';
    });
});

var assignInstructor = document.getElementById('assign_instructor');
var assignOffering = document.getElementById('assign_offering');
if (assignInstructor && assignOffering) {
    assignInstructor.addEventListener('change', function() {
        var selectedOption = this.options[this.selectedIndex];
        var isInstructorAsd = selectedOption && selectedOption.getAttribute('data-asd') === '1';
        var options = assignOffering.querySelectorAll('option');
        options.forEach(function(opt) {
            if (!opt.value) return;
            var isGned = opt.getAttribute('data-gned') === '1';
            if (isInstructorAsd) {
                opt.disabled = !isGned;
            } else {
                opt.disabled = isGned;
            }
        });
    });
}
</script>
<?php
render_page('Instructor Assignments', 'Instructor Assignments', (string) ob_get_clean());
