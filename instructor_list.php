<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

$user = require_login();
$role = normalize_role_name($user['role']);

if (!in_array($role, ['chair', 'admin'], true)) {
    flash('error', 'Access denied.');
    redirect('auth/redirect.php');
}

$termId = (int) (current_term()['id'] ?? 0);

if ($role === 'admin') {
    $departments = fetch_all(
        'SELECT d.dept_id, d.department_code, d.department_name,
                s.full_name AS chair_name
         FROM departments d
         LEFT JOIN staff s ON s.staff_id = d.chair_id
         WHERE d.status = "active"
         ORDER BY d.department_code'
    );

    $instructorsByDept = [];
    foreach ($departments as $dept) {
        $instructorsByDept[$dept['dept_id']] = fetch_all(
            'SELECT st.staff_id, st.full_name, st.email, st.employee_number,
                    COUNT(DISTINCT o.id) AS subject_count,
                    COUNT(DISTINCT ss.student_id) AS student_count
             FROM staff st
             INNER JOIN user_roles ur ON ur.user_id = st.users_id
             INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
             LEFT JOIN section_subject_offerings o ON o.instructor_id = st.staff_id AND o.term_id = :term_id
             LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
             WHERE st.dept_id = :dept_id
             GROUP BY st.staff_id, st.full_name, st.email, st.employee_number
             ORDER BY st.full_name',
            ['term_id' => $termId, 'dept_id' => (int) $dept['dept_id']]
        );
    }

    $totalInstructors = 0;
    $totalStudents = 0;
    $totalSubjects = 0;
    foreach ($instructorsByDept as $deptInstructors) {
        foreach ($deptInstructors as $inst) {
            $totalInstructors++;
            $totalStudents += (int) $inst['student_count'];
            $totalSubjects += (int) $inst['subject_count'];
        }
    }

    ob_start();
    ?>
    <div class="page-header">
        <div>
            <h1>Instructors by Department</h1>
            <p>Overview of all instructors across departments for the current term.</p>
        </div>
        <div class="actions-row">
            <a class="btn" href="<?= h(app_url('admin/staff.php')) ?>">Manage Staff</a>
        </div>
    </div>

    <div class="grid cols-3">
        <div class="card slim"><div class="metric-label">Total Instructors</div><div class="metric"><?= $totalInstructors ?></div></div>
        <div class="card slim"><div class="metric-label">Assigned Subjects</div><div class="metric"><?= $totalSubjects ?></div></div>
        <div class="card slim"><div class="metric-label">Total Students</div><div class="metric"><?= $totalStudents ?></div></div>
    </div>

    <?php foreach ($departments as $dept):
        $deptInstructors = $instructorsByDept[$dept['dept_id']] ?? [];
    ?>
    <div class="card" style="margin-top:16px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
            <h3 style="margin:0;">
                <?= h($dept['department_code']) ?> — <?= h($dept['department_name']) ?>
                <span class="badge count-badge" style="margin-left:8px;"><?= count($deptInstructors) ?></span>
            </h3>
            <?php if ($dept['chair_name']): ?>
                <span class="badge">Chair: <?= h($dept['chair_name']) ?></span>
            <?php endif; ?>
        </div>
        <?php if ($deptInstructors === []): ?>
            <p style="text-align:center;color:#94a3b8;padding:20px 0;">No instructors in this department.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Employee No.</th>
                            <th>Email</th>
                            <th>Handled Subjects</th>
                            <th>Enrolled Students</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deptInstructors as $inst): ?>
                        <tr>
                            <td><strong><?= h($inst['full_name']) ?></strong></td>
                            <td><span class="badge" style="font-family:monospace;"><?= h($inst['employee_number']) ?></span></td>
                            <td><?= h($inst['email']) ?></td>
                            <td><span class="badge count-badge"><?= $inst['subject_count'] ?></span></td>
                            <td><?= $inst['student_count'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
    <?php
    render_page('Instructors by Department', 'Instructors', (string) ob_get_clean());

} else {
    $staff = current_staff();
    if ($staff === null) {
        flash('error', 'Staff profile not found.');
        redirect('auth/logout.php');
    }

    $instructors = fetch_all(
        'SELECT st.staff_id, st.full_name, st.email, st.employee_number,
                d.department_code,
                COUNT(DISTINCT o.id) AS subject_count,
                COUNT(DISTINCT ss.student_id) AS student_count
         FROM staff st
         INNER JOIN user_roles ur ON ur.user_id = st.users_id
         INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
         LEFT JOIN departments d ON d.dept_id = st.dept_id
         LEFT JOIN section_subject_offerings o ON o.instructor_id = st.staff_id AND o.term_id = :term_id
         LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
         WHERE st.dept_id = :dept_main
         GROUP BY st.staff_id, st.full_name, st.email, st.employee_number, d.department_code
         ORDER BY st.full_name',
        ['term_id' => $termId, 'dept_main' => (int) $staff['dept_id']]
    );

    $totalInstructors = count($instructors);
    $totalStudents = 0;
    $totalSubjects = 0;
    foreach ($instructors as $inst) {
        $totalStudents += (int) $inst['student_count'];
        $totalSubjects += (int) $inst['subject_count'];
    }

    ob_start();
    ?>
    <div class="page-header">
        <div>
            <h1>Department Instructors</h1>
            <p>List of instructors in <?= h($staff['department_code'] ?: 'your') ?> department for the current term.</p>
        </div>
        <div class="actions-row">
            <a class="btn" href="<?= h(app_url('chair/assign_instructor.php')) ?>">Assign Instructor</a>
        </div>
    </div>

    <div class="grid cols-3">
        <div class="card slim"><div class="metric-label">Total Instructors</div><div class="metric"><?= $totalInstructors ?></div></div>
        <div class="card slim"><div class="metric-label">Assigned Subjects</div><div class="metric"><?= $totalSubjects ?></div></div>
        <div class="card slim"><div class="metric-label">Total Students</div><div class="metric"><?= $totalStudents ?></div></div>
    </div>

    <div class="card" style="margin-top:16px;">
        <h3>Instructor List</h3>
        <?php if ($instructors === []): ?>
            <p style="text-align:center;color:#94a3b8;padding:40px 0;">No instructors found in this department.</p>
        <?php else: ?>
            <div class="dt" data-dt-page-size="10">
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Employee No.</th>
                                <th>Department</th>
                                <th>Handled Subjects</th>
                                <th>Enrolled Students</th>
                                <th data-dt-no-sort>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($instructors as $inst): ?>
                            <tr>
                                <td><strong><?= h($inst['full_name']) ?></strong></td>
                                <td><span class="badge" style="font-family:monospace;"><?= h($inst['employee_number']) ?></span></td>
                                <td>
                                    <span class="badge">
                                        <?= h($inst['department_code'] ?: 'N/A') ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge count-badge"><?= $inst['subject_count'] ?></span>
                                </td>
                                <td><?= $inst['student_count'] ?></td>
                                <td>
                                    <a class="btn secondary small" href="<?= h(app_url('chair/assign_instructor.php')) ?>">
                                        <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">visibility</span>
                                        Assign Subjects
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <?php
    render_page('Department Instructors', 'Instructors', (string) ob_get_clean());
}
