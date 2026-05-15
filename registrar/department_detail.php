<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
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
 * Tab data
 * --------------------------------------------------------------------- */

// TAB: Chair
$chairs = fetch_all(
    'SELECT s.*, u.username
     FROM staff s
     INNER JOIN users u       ON u.users_id  = s.users_id
     INNER JOIN user_roles ur ON ur.user_id  = s.users_id
     INNER JOIN roles r       ON r.roles_id  = ur.role_id
     WHERE r.role_name = "department_chair"
       AND s.dept_id = :dept_id
     ORDER BY s.full_name',
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
                sub.subject_code, sub.subject_description, sub.units,
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
    'SELECT s.id, s.student_number, s.full_name, s.year_level, s.status,
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
     ORDER BY p.program_code, s.year_level, s.full_name',
    ['dept_id' => $deptId]
);

$yearLabels = [1 => '1st Year', 2 => '2nd Year', 3 => '3rd Year', 4 => '4th Year'];

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
$tabs = ['chair' => 'Dept Chair', 'instructors' => 'Instructors', 'curriculum' => 'Curriculum', 'students' => 'Students'];
?>
<div class="tabs">
    <?php foreach ($tabs as $key => $label): ?>
        <a href="<?= h($baseUrl . $key) ?>"
           class="<?= $activeTab === $key ? 'active' : '' ?>">
            <?= h($label) ?>
            <?php if ($key === 'students'): ?>
                <span class="badge" style="margin-left:4px; font-weight:400;"><?= count($students) ?></span>
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