<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$metrics = [
    [
        'label' => 'Total Users',
        'value' => fetch_one('SELECT COUNT(*) AS total FROM users')['total'] ?? 0,
        'icon'  => 'group',
        'change' => null, // optional for now
    ],
    [
        'label' => 'Total Staff',
        'value' => fetch_one('SELECT COUNT(*) AS total FROM staff')['total'] ?? 0,
        'icon'  => 'badge',
        'change' => null,
    ],
    [
        'label' => 'Total Students',
        'value' => fetch_one('SELECT COUNT(*) AS total FROM students')['total'] ?? 0,
        'icon'  => 'school',
        'change' => null,
    ],
    [
        'label' => 'Pending Requests',
        'value' => fetch_one('SELECT COUNT(*) AS total FROM enrollment_requests WHERE workflow_status IN ("submitted", "adviser_approved", "chair_approved")')['total'] ?? 0,
        'icon'  => 'pending_actions',
        'change' => null,
    ],
];

$instructorLoads = fetch_all(
    'SELECT st.full_name, COUNT(DISTINCT o.id) AS subject_count, COUNT(ss.id) AS student_count
     FROM staff st
     INNER JOIN users u ON u.users_id = st.users_id
     INNER JOIN user_roles ur ON ur.user_id = u.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     LEFT JOIN section_subject_offerings o ON o.instructor_id = st.staff_id AND o.term_id = :term_id
     LEFT JOIN student_subjects ss ON ss.offering_id = o.id AND ss.enrollment_status = "enrolled"
     GROUP BY st.staff_id
     ORDER BY st.full_name',
    ['term_id' => (int) (current_term()['id'] ?? 0)]
);

$advisory = fetch_all(
    'SELECT st.full_name, COUNT(sec.id) AS sections_count,
            GROUP_CONCAT(CONCAT(p.program_code, " ", sec.year_level, sec.section_name) SEPARATOR ", ") AS sections_list
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     LEFT JOIN staff st ON st.staff_id = sec.adviser_id
     GROUP BY st.staff_id
     HAVING st.staff_id IS NOT NULL
     ORDER BY st.full_name'
);

$recentGrades = fetch_all(
    'SELECT s.student_number, s.full_name, sub.subject_code, ss.final_grade, ay.year_label, t.semester
     FROM student_subjects ss
     INNER JOIN students s ON s.id = ss.student_id
     INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.final_grade IS NOT NULL
     ORDER BY ss.updated_at DESC
     LIMIT 8'
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Dashboard</h1>
        <p>Account provisioning, enrollment window control, curriculum access, instructor student lists, adviser classes, and grade visibility.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('admin/users.php')) ?>">Manage Users</a>
        <a class="btn secondary" href="<?= h(app_url('registrar/academic_term.php')) ?>">Terms & Enrollment</a>
    </div>
</div>

<div class="grid cols-4">
    <?php foreach ($metrics as $m): ?>
        <div class="card slim flex justify-between items-start">

            <!-- LEFT SIDE: TEXT -->
            <div>
                <div class="metric-label">
                    <?= h($m['label']) ?>
                </div>

                <div class="metric">
                    <?= h($m['value']) ?>
                </div>

                <?php if (isset($m['change']) && $m['change'] !== null): ?>
                    <div class="text-xs mt-1
                        <?= $m['change'] > 0 ? 'text-green-600' : ($m['change'] < 0 ? 'text-red-600' : 'text-gray-500') ?>">

                        <span class="material-symbols-outlined text-xs align-middle">
                            <?= $m['change'] > 0 ? 'trending_up' : ($m['change'] < 0 ? 'trending_down' : 'remove') ?>
                        </span>

                        <?= $m['change'] > 0 ? '+' : '' ?><?= h($m['change']) ?>% this month
                    </div>
                <?php endif; ?>
            </div>

            <!-- RIGHT SIDE: ICON -->
            <div class="flex items-center justify-center w-10 h-10 rounded-lg card-icon">
                <span class="material-symbols-outlined">
                    <?= h($m['icon'] ?? 'analytics') ?>
                </span>
            </div>

        </div>
    <?php endforeach; ?>
</div>

<div class="grid cols-2" style="margin-top: 16px;">
    <div class="card">
        <h3>Instructor student lists</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Instructor</th><th>Handled Subjects</th><th>Enrolled Students</th></tr></thead>
                <tbody>
                <?php foreach ($instructorLoads as $row): ?>
                    <tr>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['subject_count']) ?></td>
                        <td><?= h($row['student_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>Adviser advisory class list</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Adviser</th><th>Sections</th><th>Classes</th></tr></thead>
                <tbody>
                <?php foreach ($advisory as $row): ?>
                    <tr>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['sections_count']) ?></td>
                        <td><?= h($row['sections_list']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>Recent student grades</h3>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Student Number</th><th>Name</th><th>Subject</th><th>Grade</th><th>Term</th></tr></thead>
            <tbody>
            <?php foreach ($recentGrades as $row): ?>
                <tr>
                    <td><?= h($row['student_number']) ?></td>
                    <td><?= h($row['full_name']) ?></td>
                    <td><?= h($row['subject_code']) ?></td>
                    <td><?= h($row['final_grade']) ?></td>
                    <td><?= h($row['year_label']) ?> / <?= h(semester_label((string) $row['semester'])) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Admin Dashboard', 'Dashboard', (string) ob_get_clean());
