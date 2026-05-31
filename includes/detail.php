<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_login();

$type = trim($_GET['type'] ?? '');
$id   = (int) ($_GET['id'] ?? 0);
$user = current_user();
$role = $user['role'] ?? 'student';

if ($id <= 0 || $type === '') {
    flash('error', 'Missing detail parameters.');
    redirect('index.php');
}

/**
 * Configuration for each detail type:
 *   sql                 — SQL with :id placeholder returning a single row
 *   title               — page title prefix
 *   roles               — required roles
 *   fields              — array of [label, key] pairs to display
 *   back                — return URL (relative)
 */
$types = [
    'staff' => [
        'title' => 'Staff Detail',
        'roles' => ['admin', 'registrar'],
        'sql'   => 'SELECT st.*, d.department_code, d.department_name,
                           u.username, u.email AS account_email,
                           GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ", ") AS roles
                    FROM staff st
                    LEFT JOIN departments d ON d.dept_id = st.dept_id
                    LEFT JOIN users u ON u.users_id = st.users_id
                    LEFT JOIN user_roles ur ON ur.user_id = st.users_id
                    LEFT JOIN roles r ON r.roles_id = ur.role_id
                    WHERE st.staff_id = :id
                    GROUP BY st.staff_id',
        'fields' => [
            ['Employee No.', 'employee_number'],
            ['Full Name', 'full_name'],
            ['Email', 'email'],
            ['Department', 'department_code'],
            ['Department Name', 'department_name'],
            ['Username', 'username'],
            ['Account Email', 'account_email'],
            ['Roles', 'roles'],
            ['Status', 'status'],
            ['Created', 'created_at'],
        ],
        'back' => 'admin/staff.php',
    ],
    'user' => [
        'title' => 'User Detail',
        'roles' => ['admin'],
        'sql'   => 'SELECT u.*, COALESCE(s.full_name, st.full_name, u.username) AS display_name,
                           GROUP_CONCAT(r.role_name ORDER BY r.role_name SEPARATOR ", ") AS roles
                    FROM users u
                    LEFT JOIN students s ON s.id = u.student_id
                    LEFT JOIN staff st ON st.users_id = u.users_id
                    LEFT JOIN user_roles ur ON ur.user_id = u.users_id
                    LEFT JOIN roles r ON r.roles_id = ur.role_id
                    WHERE u.users_id = :id
                    GROUP BY u.users_id',
        'fields' => [
            ['Display Name', 'display_name'],
            ['Username', 'username'],
            ['Email', 'email'],
            ['Roles', 'roles'],
            ['Status', 'status'],
            ['Created', 'created_at'],
        ],
        'back' => 'admin/users.php',
    ],
    'section' => [
        'title' => 'Section Detail',
        'roles' => ['admin', 'registrar', 'chair'],
        'sql'   => 'SELECT sec.*, p.program_code, p.program_name, d.department_code,
                           st.full_name AS adviser_name
                    FROM sections sec
                    INNER JOIN programs p ON p.programs_id = sec.program_id
                    INNER JOIN departments d ON d.dept_id = p.department_id
                    LEFT JOIN staff st ON st.staff_id = sec.adviser_id
                    WHERE sec.id = :id',
        'fields' => [
            ['Program', 'program_code'],
            ['Program Name', 'program_name'],
            ['Department', 'department_code'],
            ['Year Level', 'year_level'],
            ['Section', 'section_name'],
            ['Adviser', 'adviser_name'],
            ['Max Slots', 'max_slots'],
            ['Status', 'status'],
            ['Created', 'created_at'],
        ],
        'back' => 'registrar/departments.php',
    ],
    'subject' => [
        'title' => 'Subject Detail',
        'roles' => ['admin', 'registrar', 'chair', 'instructor'],
        'sql'   => 'SELECT * FROM subjects WHERE subject_id = :id',
        'fields' => [
            ['Subject Code', 'subject_code'],
            ['Description', 'subject_description'],
            ['Units', 'units'],
        ],
        'back' => 'registrar/curriculum.php',
    ],
    'curriculum' => [
        'title' => 'Curriculum Line Detail',
        'roles' => ['admin', 'registrar', 'chair'],
        'sql'   => 'SELECT pc.*, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units,
                           p.program_code, p.program_name, pre.subject_code AS prerequisite_code
                    FROM program_curriculum pc
                    INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
                    INNER JOIN programs p ON p.programs_id = pc.program_id
                    LEFT JOIN subjects pre ON pre.subject_id = pc.prerequisite_subject_id
                    WHERE pc.curriculum_id = :id',
        'fields' => [
            ['Program', 'program_code'],
            ['Program Name', 'program_name'],
            ['Curriculum Label', 'curriculum_label'],
            ['Year Level', 'year_level'],
            ['Semester', 'semester'],
            ['Subject Code', 'subject_code'],
            ['Subject Description', 'subject_description'],
            ['Units', 'units'],
            ['Prerequisite', 'prerequisite_code'],
            ['Status', 'status'],
        ],
        'back' => 'registrar/curriculum.php',
    ],
    'offering' => [
        'title' => 'Offering Detail',
        'roles' => ['admin', 'registrar', 'chair', 'instructor'],
        'sql'   => 'SELECT o.*, sub.subject_code, sub.subject_description,
                           p.program_code, sec.year_level, sec.section_name,
                           st.full_name AS instructor_name,
                           ay.year_label, t.semester
                    FROM section_subject_offerings o
                    INNER JOIN sections sec ON sec.id = o.section_id
                    INNER JOIN programs p ON p.programs_id = sec.program_id
                    INNER JOIN subjects sub ON sub.subject_id = o.subject_id
                    INNER JOIN academic_terms t ON t.id = o.term_id
                    INNER JOIN academic_years ay ON ay.id = t.academic_year_id
                    LEFT JOIN staff st ON st.staff_id = o.instructor_id
                    WHERE o.id = :id',
        'fields' => [
            ['Term', 'year_label'],
            ['Semester', 'semester'],
            ['Subject', 'subject_code'],
            ['Description', 'subject_description'],
            ['Section', 'section_name'],
            ['Year Level', 'year_level'],
            ['Program', 'program_code'],
            ['Instructor', 'instructor_name'],
            ['Day', 'day_of_week'],
            ['Time', 'time_range'],
            ['Room', 'room'],
            ['Max Slots', 'max_slots'],
            ['Status', 'status'],
        ],
        'back' => 'registrar/curriculum.php',
    ],
    'request' => [
        'title' => 'Enrollment Request Detail',
        'roles' => ['admin', 'registrar', 'chair', 'adviser'],
        'sql'   => 'SELECT er.*, s.student_number, s.full_name, p.program_code,
                           ay.year_label, t.semester, sec.section_name AS requested_section_name
                    FROM enrollment_requests er
                    INNER JOIN students s ON s.id = er.student_id
                    INNER JOIN programs p ON p.programs_id = s.program_id
                    INNER JOIN academic_terms t ON t.id = er.term_id
                    INNER JOIN academic_years ay ON ay.id = t.academic_year_id
                    LEFT JOIN sections sec ON sec.id = er.requested_section_id
                    WHERE er.id = :id',
        'fields' => [
            ['Student', 'student_number'],
            ['Name', 'full_name'],
            ['Program', 'program_code'],
            ['Academic Year', 'year_label'],
            ['Semester', 'semester'],
            ['Requested Status', 'requested_status'],
            ['Requested Section', 'requested_section_name'],
            ['Workflow', 'workflow_status'],
            ['Adviser', 'adviser_status'],
            ['Chair', 'chair_status'],
            ['Registrar', 'registrar_status'],
            ['RA / Tuition', 'ra10931_status'],
            ['Total Units', 'total_units'],
            ['Total Amount', 'total_amount'],
            ['Adviser Remark', 'adviser_remark'],
            ['Chair Remark', 'chair_remark'],
            ['Registrar Remark', 'registrar_remark'],
        ],
        'back' => 'index.php',
    ],
];

if (!isset($types[$type])) {
    flash('error', 'Unknown detail type.');
    redirect('index.php');
}

$cfg = $types[$type];
require_role($cfg['roles']);

$row = fetch_one($cfg['sql'], ['id' => $id]);
if ($row === null) {
    flash('error', 'Record not found.');
    redirect($cfg['back']);
}

ob_start();
?>
<div class="page-header">
    <div>
        <h1><?= h($cfg['title']) ?></h1>
        <p>Read-only view of the selected record.</p>
    </div>
    <div class="actions-row">
        <a class="btn secondary" href="<?= h(app_url($cfg['back'])) ?>">
            <span class="material-symbols-outlined">arrow_back</span> Back
        </a>
    </div>
</div>

<div class="card">
    <div class="form-grid cols-2">
        <?php foreach ($cfg['fields'] as $f): ?>
            <?php $val = $row[$f[1]] ?? null; ?>
            <div>
                <label><?= h($f[0]) ?></label>
                <div style="padding:10px 12px; border:1px solid var(--line); border-radius:10px; background:#f9fffe; min-height:42px; word-break:break-word;">
                    <?= $val === null || $val === '' ? '<span class="helper">—</span>' : h((string) $val) ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
render_page($cfg['title'], $cfg['title'], (string) ob_get_clean());
