<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_role(['admin', 'registrar']);

$deptId = (int) ($_GET['id'] ?? 0);
if ($deptId <= 0) {
    flash('error', 'Invalid department.');
    redirect('registrar/departments.php');
}

$dept = fetch_one('SELECT * FROM departments WHERE dept_id = :id', ['id' => $deptId]);
if ($dept === null) {
    flash('error', 'Department not found.');
    redirect('registrar/departments.php');
}

$activeTab = trim($_GET['tab'] ?? 'chair');

/* -----------------------------------------------------------------------
 * POST handlers
 * --------------------------------------------------------------------- */
if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_section') {
        $programId   = (int) ($_POST['program_id'] ?? 0);
        $yearLevel   = (int) ($_POST['year_level'] ?? 1);
        $sectionName = trim($_POST['section_name'] ?? '');
        $maxSlots    = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($programId <= 0 || $sectionName === '') {
            flash('error', 'Program and section name are required.');
        } else {
            $prog = fetch_one('SELECT department_id FROM programs WHERE programs_id = :id', ['id' => $programId]);
            if ($prog === null || (int) $prog['department_id'] !== $deptId) {
                flash('error', 'Invalid program for this department.');
            } else {
                $exists = fetch_one(
                    'SELECT id FROM sections WHERE program_id = :pid AND section_name = :name',
                    ['pid' => $programId, 'name' => $sectionName]
                );
                if ($exists !== null) {
                    flash('error', 'A section with that name already exists for this program.');
                } else {
                    execute_sql(
                        'INSERT INTO sections (program_id, year_level, section_name, max_slots, created_at)
                         VALUES (:program_id, :year_level, :section_name, :max_slots, NOW())',
                        ['program_id' => $programId, 'year_level' => $yearLevel, 'section_name' => $sectionName, 'max_slots' => $maxSlots]
                    );
                    $newSectionId = (int) db()->lastInsertId();
                    $offeringCount = auto_generate_offerings_for_section($newSectionId);
                    if ($offeringCount > 0) {
                        flash('success', "Section created. Generated $offeringCount subject offering(s) from curriculum.");
                    } else {
                        flash('success', 'Section created.');
                    }
                }
            }
        }
        redirect('registrar/department_detail.php?id=' . $deptId . '&tab=sections');
    }

    if ($action === 'update_section') {
        $sectionId   = (int) ($_POST['section_id'] ?? 0);
        $programId   = (int) ($_POST['program_id'] ?? 0);
        $yearLevel   = (int) ($_POST['year_level'] ?? 1);
        $sectionName = trim($_POST['section_name'] ?? '');
        $maxSlots    = ($_POST['max_slots'] ?? '') !== '' ? (int) $_POST['max_slots'] : null;

        if ($sectionId <= 0 || $programId <= 0 || $sectionName === '') {
            flash('error', 'All fields are required.');
        } else {
            $prog = fetch_one('SELECT department_id FROM programs WHERE programs_id = :id', ['id' => $programId]);
            if ($prog === null || (int) $prog['department_id'] !== $deptId) {
                flash('error', 'Invalid program for this department.');
            } else {
                execute_sql(
                    'UPDATE sections SET program_id = :program_id, year_level = :year_level,
                     section_name = :section_name, max_slots = :max_slots WHERE id = :id',
                    ['program_id' => $programId, 'year_level' => $yearLevel, 'section_name' => $sectionName, 'max_slots' => $maxSlots, 'id' => $sectionId]
                );
                flash('success', 'Section updated.');
            }
        }
        redirect('registrar/department_detail.php?id=' . $deptId . '&tab=sections');
    }

    if ($action === 'delete_section') {
        $sectionId = (int) ($_POST['section_id'] ?? 0);
        if ($sectionId > 0) {
            $sec = fetch_one('SELECT sec.id FROM sections sec INNER JOIN programs p ON p.programs_id = sec.program_id WHERE sec.id = :id AND p.department_id = :dept_id', ['id' => $sectionId, 'dept_id' => $deptId]);
            if ($sec) {
                soft_delete('sections', 'id', $sectionId);
                flash('success', 'Section marked inactive.');
            } else {
                flash('error', 'Section not found in this department.');
            }
        }
        redirect('registrar/department_detail.php?id=' . $deptId . '&tab=sections');
    }

    redirect('registrar/department_detail.php?id=' . $deptId . '&tab=' . $activeTab);
}

/* -----------------------------------------------------------------------
 * Tab data
 * --------------------------------------------------------------------- */

// TAB: Chair
$chairs = fetch_all(
    'SELECT s.*, u.username
     FROM departments d
     INNER JOIN staff s ON s.staff_id = d.chair_id
     INNER JOIN users u ON u.users_id = s.users_id
     WHERE d.dept_id = :dept_id
       AND d.chair_id IS NOT NULL',
    ['dept_id' => $deptId]
);

// TAB: Instructors
$instructors = fetch_all(
    'SELECT s.*, u.username
     FROM staff s
     INNER JOIN users u       ON u.users_id  = s.users_id
     INNER JOIN user_roles ur ON ur.user_id  = s.users_id
     INNER JOIN roles r       ON r.roles_id  = ur.role_id
     WHERE r.role_name = "instructor"
       AND s.dept_id = :dept_id
     ORDER BY s.full_name',
    ['dept_id' => $deptId]
);

// TAB: Programs under this dept
$programs = fetch_all(
    'SELECT p.*,
            COUNT(pc.curriculum_id) AS subject_count
     FROM programs p
     LEFT JOIN program_curriculum pc ON pc.program_id = p.programs_id
     WHERE p.department_id = :dept_id
     GROUP BY p.programs_id
     ORDER BY p.program_code',
    ['dept_id' => $deptId]
);

// TAB: Curriculum — fetch all rows at once, then group in PHP
$curriculumByProgram = [];
if (!empty($programs)) {
    $programIds = array_column($programs, 'programs_id');
    $placeholders = implode(',', array_fill(0, count($programIds), '?'));

    $allCurriculum = fetch_all(
        'SELECT pc.curriculum_id, pc.program_id, pc.year_level, pc.semester,
                pc.prerequisite_subject_id,
                sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
                prereq.subject_code AS prereq_code
         FROM program_curriculum pc
         INNER JOIN subjects sub   ON sub.subject_id   = pc.subject_id
         LEFT JOIN subjects prereq ON prereq.subject_id = pc.prerequisite_subject_id
         WHERE pc.program_id IN (' . $placeholders . ')
         ORDER BY pc.program_id,
                  CAST(pc.year_level AS UNSIGNED),
                  FIELD(pc.semester, "1st", "2nd", "mid"),
                  sub.subject_code',
        $programIds
    );

    foreach ($allCurriculum as $row) {
        $curriculumByProgram[$row['program_id']][$row['year_level']][$row['semester']][] = $row;
    }
}

// TAB: Students
$students = fetch_all(
    'SELECT s.id, s.student_number, CONCAT(s.first_name, \' \', IFNULL(s.middle_name, \'\'), \' \', s.last_name) AS full_name, s.year_level, s.status,
            p.program_code, p.program_name,
            sec.section_name,
            (SELECT er.workflow_status
             FROM enrollment_requests er
             WHERE er.student_id = s.id
             ORDER BY er.created_at DESC LIMIT 1
            ) AS latest_enrollment_status
     FROM students s
     INNER JOIN programs p     ON p.programs_id   = s.program_id
     LEFT  JOIN sections sec   ON sec.id           = s.section_id
     WHERE p.department_id = :dept_id
     ORDER BY p.program_code, s.year_level, s.last_name, s.first_name',
    ['dept_id' => $deptId]
);

$yearLabels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];

// TAB: Sections (under this department's programs)
$deptPrograms = fetch_all(
    'SELECT programs_id, program_code, program_name FROM programs WHERE department_id = :dept_id ORDER BY program_code',
    ['dept_id' => $deptId]
);
$deptSections = [];
if (!empty($deptPrograms)) {
    $progIds = array_column($deptPrograms, 'programs_id');
    $ph = implode(',', array_fill(0, count($progIds), '?'));
    $deptSections = fetch_all(
        "SELECT sec.*, p.program_code, p.program_name,
                s.full_name AS adviser_name
         FROM sections sec
         INNER JOIN programs p ON p.programs_id = sec.program_id
         LEFT JOIN staff s ON s.staff_id = sec.adviser_id
         WHERE sec.program_id IN ({$ph})
           AND COALESCE(sec.status, 'active') = 'active'
         ORDER BY p.program_code, sec.year_level, sec.section_name",
        $progIds
    );
}

// Program options for modals
$progOptions = '';
foreach ($deptPrograms as $dp) {
    $progOptions .= '<option value="' . h($dp['programs_id']) . '">' . h($dp['program_code'] . ' — ' . $dp['program_name']) . '</option>';
}

/* -----------------------------------------------------------------------
 * View
 * --------------------------------------------------------------------- */
ob_start();
?>

<div style="margin-bottom:16px;">
    <a href="<?= h(app_url('registrar/departments.php')) ?>"
       style="font-size:13px; color:var(--muted); text-decoration:none;">
        ← Back to Departments &amp; Sections
    </a>
</div>

<div class="page-header">
    <div>
        <h1><?= h($dept['department_name']) ?></h1>
        <p>
            <span class="badge"><?= h($dept['department_code']) ?></span>
            &nbsp;
            <span class="badge <?= $dept['status'] === 'active' ? 'success' : '' ?>">
                <?= h($dept['status']) ?>
            </span>
        </p>
    </div>
</div>

<style>
.tabs { display:flex; gap:4px; border-bottom:2px solid var(--line); margin-bottom:20px; }
.tabs a {
    padding: 9px 18px;
    font-size: 13.5px;
    font-weight: 600;
    color: var(--muted);
    text-decoration: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    border-radius: 6px 6px 0 0;
    transition: color .15s, border-color .15s;
}
.tabs a:hover { color: var(--ink); }
.tabs a.active { color: var(--primary); border-bottom-color: var(--primary); }
</style>

<?php
$baseUrl = app_url('registrar/department_detail.php?id=' . $deptId . '&tab=');
$tabs = ['chair' => 'Dept Chair', 'instructors' => 'Instructors', 'sections' => 'Sections', 'curriculum' => 'Curriculum', 'students' => 'Students'];
?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= h($baseUrl . $key) ?>"
           class="<?= $activeTab === $key ? 'active' : '' ?>">
            <?= h($label) ?>
            <?php if ($key === 'students'): ?>
                <span class="badge" style="margin-left:4px; font-weight:400;"><?= count($students) ?></span>
            <?php elseif ($key === 'sections'): ?>
                <span class="badge" style="margin-left:4px; font-weight:400;"><?= count($deptSections) ?></span>
            <?php endif; ?>
        </a>
    <?php endforeach; ?>
</div>

<!-- ══════════ TAB: CHAIR ══════════ -->
<?php if ($activeTab === 'chair'): ?>
<div class="card">
    <h3>Department Chair</h3>
    <?php if (empty($chairs)): ?>
        <p class="empty">No chair assigned. Go to Departments &amp; Sections to assign one.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Employee No.</th><th>Email</th><th>Username</th></tr>
            </thead>
            <tbody>
            <?php foreach ($chairs as $c): ?>
                <tr>
                    <td><strong><?= h($c['full_name']) ?></strong></td>
                    <td><?= h($c['employee_number']) ?></td>
                    <td><?= h($c['email']) ?></td>
                    <td><?= h($c['username']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════ TAB: INSTRUCTORS ══════════ -->
<?php elseif ($activeTab === 'instructors'): ?>
<div class="card">
    <h3>Instructors <span class="badge" style="margin-left:6px; font-weight:400;"><?= count($instructors) ?></span></h3>
    <?php if (empty($instructors)): ?>
        <p class="empty">No instructors assigned to this department.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Name</th><th>Employee No.</th><th>Email</th><th>Username</th></tr>
            </thead>
            <tbody>
            <?php foreach ($instructors as $i): ?>
                <tr>
                    <td><strong><?= h($i['full_name']) ?></strong></td>
                    <td><?= h($i['employee_number']) ?></td>
                    <td><?= h($i['email']) ?></td>
                    <td><?= h($i['username']) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ══════════ TAB: SECTIONS ══════════ -->
<?php elseif ($activeTab === 'sections'): ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Sections <span class="badge" style="margin-left:6px; font-weight:400;"><?= count($deptSections) ?></span></h3>
        <?php if (!empty($deptPrograms)): ?>
        <button class="btn small" data-open="modal-new-dept-section">+ New Section</button>
        <?php endif; ?>
    </div>

    <?php if (empty($deptPrograms)): ?>
        <p class="empty">No programs under this department yet. Add programs first.</p>
    <?php elseif (empty($deptSections)): ?>
        <p class="empty">No sections yet for this department's programs.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Section</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th>Adviser</th>
                    <th>Slots</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($deptSections as $sec): ?>
                <tr>
                    <td>
                        <a href="<?= h(app_url('registrar/section_detail.php?id=' . $sec['id'])) ?>" style="text-decoration:none;">
                            <strong><?= h($sec['program_code'] . ' ' . $sec['year_level'] . '-' . $sec['section_name']) ?></strong>
                        </a>
                    </td>
                    <td><span class="badge info"><?= h($sec['program_code']) ?></span></td>
                    <td>Year <?= h($sec['year_level']) ?></td>
                    <td>
                        <?php if ($sec['adviser_name']): ?>
                            <?= h($sec['adviser_name']) ?>
                        <?php else: ?>
                            <span class="helper">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $sec['max_slots'] ? h($sec['max_slots']) : '—' ?></td>
                    <td>
                        <div class="row-actions">
                            <a class="icon-btn" title="View" href="<?= h(app_url('registrar/section_detail.php?id=' . $sec['id'])) ?>">
                                <span class="material-symbols-outlined">visibility</span>
                            </a>
                            <button class="icon-btn" type="button" title="Edit"
                                    data-open="modal-edit-dept-section"
                                    data-id="<?= h($sec['id']) ?>"
                                    data-program-id="<?= h($sec['program_id']) ?>"
                                    data-year-level="<?= h($sec['year_level']) ?>"
                                    data-section-name="<?= h($sec['section_name']) ?>"
                                    data-max-slots="<?= h($sec['max_slots'] ?? '') ?>">
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <form method="post" style="display:inline;" onsubmit="return confirm('Mark this section as inactive?');">
                                <input type="hidden" name="action" value="delete_section">
                                <input type="hidden" name="section_id" value="<?= h($sec['id']) ?>">
                                <button class="icon-btn danger" type="submit" title="Delete">
                                    <span class="material-symbols-outlined">delete</span>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('click', function(e) {
    var btn = e.target.closest('[data-open="modal-edit-dept-section"]');
    if (btn) {
        document.getElementById('edit_dept_sec_id').value = btn.dataset.id;
        document.getElementById('edit_dept_sec_program').value = btn.dataset.programId;
        document.getElementById('edit_dept_sec_year').value = btn.dataset.yearLevel;
        document.getElementById('edit_dept_sec_name').value = btn.dataset.sectionName;
        document.getElementById('edit_dept_sec_slots').value = btn.dataset.maxSlots || '';
    }
});
</script>

<?= render_modal('modal-new-dept-section', 'New Section', '
<form method="post">
    <input type="hidden" name="action" value="create_section">
    <div class="form-grid">
        <div>
            <label>Program</label>
            <select name="program_id" required>
                <option value="">— select —</option>
                ' . $progOptions . '
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level">
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
            </select>
        </div>
        <div>
            <label>Section Name</label>
            <input type="text" name="section_name" placeholder="e.g. A" required maxlength="10">
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" placeholder="e.g. 40" min="1">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Create Section</button>
    </div>
</form>
') ?>

<?= render_modal('modal-edit-dept-section', 'Edit Section', '
<form method="post">
    <input type="hidden" name="action" value="update_section">
    <input type="hidden" name="section_id" id="edit_dept_sec_id">
    <div class="form-grid">
        <div>
            <label>Program</label>
            <select name="program_id" id="edit_dept_sec_program" required>
                <option value="">— select —</option>
                ' . $progOptions . '
            </select>
        </div>
        <div>
            <label>Year Level</label>
            <select name="year_level" id="edit_dept_sec_year">
                <option value="1">1st Year</option>
                <option value="2">2nd Year</option>
                <option value="3">3rd Year</option>
                <option value="4">4th Year</option>
            </select>
        </div>
        <div>
            <label>Section Name</label>
            <input type="text" name="section_name" id="edit_dept_sec_name" required maxlength="10">
        </div>
        <div>
            <label>Max Slots</label>
            <input type="number" name="max_slots" id="edit_dept_sec_slots" placeholder="e.g. 40" min="1">
        </div>
    </div>
    <div class="form-actions">
        <button class="btn" type="submit">Save Changes</button>
    </div>
</form>
') ?>

<!-- ══════════ TAB: CURRICULUM ══════════ -->
<?php elseif ($activeTab === 'curriculum'): ?>

<?php if (empty($programs)): ?>
    <div class="card"><p class="empty">No programs under this department.</p></div>
<?php endif; ?>

<?php foreach ($programs as $prog): ?>
<div class="card" style="margin-bottom:16px;">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <div>
            <h3 style="margin:0;"><?= h($prog['program_name']) ?></h3>
            <span class="badge" style="margin-top:6px;"><?= h($prog['program_code']) ?></span>
        </div>
        <span class="helper"><?= (int) $prog['subject_count'] ?> subjects</span>
    </div>

    <?php
    $grouped = $curriculumByProgram[$prog['programs_id']] ?? [];
    if (empty($grouped)):
    ?>
        <p class="empty">No curriculum subjects configured yet.</p>
    <?php else: ?>
        <?php foreach ($grouped as $yl => $semesters): ?>
            <p style="font-size:13px; font-weight:700; color:var(--muted); margin:16px 0 6px;">
                <?= h($yearLabels[$yl] ?? 'Year ' . $yl) ?>
            </p>
            <?php foreach ($semesters as $sem => $subjects): ?>
                <p style="font-size:12px; font-weight:600; color:var(--muted); margin:8px 0 4px; padding-left:8px; border-left:3px solid var(--line);">
                    <?= h(semester_label($sem)) ?>
                </p>
                <div class="table-wrap" style="margin-bottom:8px;">
                    <table>
                        <thead>
                            <tr>
                                <th>Code</th>
                                <th>Subject</th>
                                <th>Units</th>
                                <th>Prerequisite</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($subjects as $sub): ?>
                            <tr>
                                <td><span class="badge"><?= h($sub['subject_code']) ?></span></td>
                                <td><?= h($sub['subject_description']) ?></td>
                                <td><?= h($sub['units']) ?></td>
                                <td>
                                    <?php if ($sub['prereq_code']): ?>
                                        <span class="badge info"><?= h($sub['prereq_code']) ?></span>
                                    <?php else: ?>
                                        <span class="helper">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php endforeach; ?>

<!-- ══════════ TAB: STUDENTS ══════════ -->
<?php elseif ($activeTab === 'students'): ?>
<div class="card">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
        <h3 style="margin:0;">Students</h3>
        <span class="helper"><?= count($students) ?> total</span>
    </div>

    <?php if (empty($students)): ?>
        <p class="empty">No students under this department.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student No.</th>
                    <th>Name</th>
                    <th>Program</th>
                    <th>Year</th>
                    <th>Section</th>
                    <th>Status</th>
                    <th>Latest Enrollment</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $stu): ?>
                <tr>
                    <td><span class="badge"><?= h($stu['student_number']) ?></span></td>
                    <td><strong><?= h($stu['full_name']) ?></strong></td>
                    <td><?= h($stu['program_code']) ?></td>
                    <td><?= h($yearLabels[$stu['year_level']] ?? 'Year ' . $stu['year_level']) ?></td>
                    <td><?= $stu['section_name'] ? h($stu['section_name']) : '<span class="helper">—</span>' ?></td>
                    <td>
                        <span class="badge <?= $stu['status'] === 'active' ? 'success' : '' ?>">
                            <?= h($stu['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($stu['latest_enrollment_status']): ?>
                            <span class="badge <?= h(workflow_badge_class((string) $stu['latest_enrollment_status'])) ?>">
                                <?= h(request_workflow_label((string) $stu['latest_enrollment_status'])) ?>
                            </span>
                        <?php else: ?>
                            <span class="helper">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a class="btn small secondary"
                           href="<?= h(app_url('registrar/student_detail.php?student_id=' . $stu['id'])) ?>">
                            View
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<?php
render_page(
    'Department: ' . $dept['department_name'],
    'Departments & Sections',
    (string) ob_get_clean()
);