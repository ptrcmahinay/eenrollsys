<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}
$scope = user_department_scope_clause($staff);
$params = $scope['params'];
$currentTermId = (int) (current_term()['id'] ?? 0);
$termFilter = $currentTermId > 0 ? ' AND er.term_id = :term_id' : '';
$termParams = $currentTermId > 0 ? ['term_id' => $currentTermId] : [];

$pending = fetch_one(
    'SELECT COUNT(*) AS total
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     WHERE er.workflow_status = "adviser_approved"' . $scope['sql'] . $termFilter,
    array_merge($params, $termParams)
)['total'] ?? 0;

$instructors = fetch_all(
    'SELECT st.full_name, COUNT(DISTINCT o.id) AS offerings
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "instructor"
     LEFT JOIN section_subject_offerings o ON o.instructor_id = st.staff_id AND o.term_id = :term_id
     LEFT JOIN subjects sub ON sub.subject_id = o.subject_id
     WHERE st.dept_id = :department_id
     GROUP BY st.staff_id
     ORDER BY st.full_name',
    [
        'department_id' => (int) $staff['dept_id'],
        'term_id' => (int) (current_term()['id'] ?? 0),
    ]
);

$studentList = fetch_all(
    'SELECT s.student_number, CONCAT(s.first_name, \' \', IFNULL(s.middle_name, \'\'), \' \', s.last_name) AS full_name, p.program_code, s.year_level, sec.section_name
     FROM students s
     INNER JOIN programs p ON p.programs_id = s.program_id
     LEFT JOIN sections sec ON sec.id = s.section_id
     WHERE p.department_id = :department_id
     ORDER BY s.student_number',
    ['department_id' => (int) $staff['dept_id']]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Department Chair Dashboard</h1>
        <p>Assign advisers per section, assign instructors per subject and section, review enrollment after adviser approval, and monitor students and instructor loads in the department.</p>
    </div>
    <div class="actions-row">
        <a class="btn" href="<?= h(app_url('chair/requests.php')) ?>">Open Requests</a>
        <a class="btn secondary" href="<?= h(app_url('instructor_list.php')) ?>">Instructors</a>
        <a class="btn secondary" href="<?= h(app_url('chair/assign_instructor.php')) ?>">Assign Instructor</a>
    </div>
</div>

<div class="grid cols-3">
    <div class="card slim"><div class="metric-label">Pending Requests</div><div class="metric"><?= h((string) $pending) ?></div></div>
    <div class="card slim"><div class="metric-label">Department</div><div class="metric" style="font-size:20px;"><?= h($staff['department_code'] ?: 'N/A') ?></div></div>
    <div class="card slim"><div class="metric-label">Students in Department</div><div class="metric"><?= h((string) count($studentList)) ?></div></div>
</div>

<div class="grid cols-2" style="margin-top:16px;">
    <div class="card">
        <h3>Instructor handled subjects <a href="<?= h(app_url('instructor_list.php')) ?>" style="font-size:12px;font-weight:normal;color:#16a34a;">View All</a></h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Instructor</th><th>Offerings This Term</th></tr></thead>
                <tbody>
                <?php foreach ($instructors as $row): ?>
                    <tr>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['offerings']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <div class="card">
        <h3>Student list in department</h3>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Student Number</th><th>Name</th><th>Program</th><th>Year / Section</th></tr></thead>
                <tbody>
                <?php foreach (array_slice($studentList, 0, 12) as $row): ?>
                    <tr>
                        <td><?= h($row['student_number']) ?></td>
                        <td><?= h($row['full_name']) ?></td>
                        <td><?= h($row['program_code']) ?></td>
                        <td><?= h($row['year_level'] . ($row['section_name'] ?: '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php
render_page('Chair Dashboard', 'Dashboard', (string) ob_get_clean());
