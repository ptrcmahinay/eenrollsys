<?php
declare(strict_types=1);

$menu = [];
$role = $user_role ?? (current_user()['role'] ?? 'student');

switch ($role) {
    case 'admin':
        $menu = [
            ['label' => 'Dashboard',       'path' => 'admin/dashboard.php',        'icon' => 'dashboard'],
            ['label' => 'Users',           'path' => 'admin/users.php',            'icon' => 'group'],
            ['label' => 'Staff',           'path' => 'admin/staff.php',            'icon' => 'badge'],
            ['label' => 'Students',        'path' => 'admin/students.php',         'icon' => 'school'],
            ['label' => 'Curriculum',      'path' => 'registrar/curriculum.php',        'icon' => 'menu_book'],
            ['label' => 'Departments & Sections', 'path' => 'registrar/departments.php', 'icon' => 'domain'],
            ['label' => 'Academic Term',   'path' => 'registrar/academic_term.php',     'icon' => 'calendar_month'],
            ['label' => 'Subject Offerings', 'path' => 'registrar/offerings.php',       'icon' => 'event_note'],
            ['label' => 'Enroll Schedule', 'path' => 'registrar/enrollment_schedule.php','icon' => 'schedule'],
            ['label' => 'Enrollment Queue', 'path' => 'registrar/enrollment.php',         'icon' => 'app_registration'],
            ['label' => 'Enrollment List', 'path' => 'admin/enrollment_list.php',    'icon' => 'list_alt'],
            ['label' => 'Enrollment Analytics', 'path' => 'admin/enrollment_analytics.php', 'icon' => 'bar_chart'],
            ['label' => 'Audit Log',       'path' => 'admin/audit_log.php',        'icon' => 'history'],
            ['label' => 'System Settings', 'path' => 'includes/settings.php',           'icon' => 'settings'],
        ];
        break;

    case 'registrar':
        $menu = [
            ['label' => 'Dashboard',        'path' => 'registrar/dashboard.php',      'icon' => 'dashboard'],
            ['label' => 'Students',         'path' => 'admin/students.php',       'icon' => 'group'],
            ['label' => 'Curriculum',       'path' => 'registrar/curriculum.php',     'icon' => 'menu_book'],
            ['label' => 'Departments & Sections', 'path' => 'registrar/departments.php', 'icon' => 'domain'],
            ['label' => 'Academic Term',    'path' => 'registrar/academic_term.php',       'icon' => 'calendar_month'],
            ['label' => 'Subject Offerings', 'path' => 'registrar/offerings.php',      'icon' => 'event_note'],
            ['label' => 'Enroll Schedule',  'path' => 'registrar/enrollment_schedule.php', 'icon' => 'schedule'],
            ['label' => 'Enrollment Queue', 'path' => 'registrar/enrollment.php',     'icon' => 'app_registration'],
            ['label' => 'Add/Drop Requests','path' => 'registrar/enrollment.php?type=add_drop', 'icon' => 'edit_note'],
            ['label' => 'Direct Enroll',    'path' => 'registrar/direct_enroll.php',  'icon' => 'person_add'],
            ['label' => 'Enrollment List',  'path' => 'admin/enrollment_list.php',         'icon' => 'list_alt'],
            ['label' => 'Grade Management', 'path' => 'registrar/upload_grades.php',  'icon' => 'grading'],
            ['label' => 'Curriculum View',  'path' => 'registrar/curriculum_view.php','icon' => 'preview'],
        ];
        break;

    case 'chair':
        $menu = [
            ['label' => 'Dashboard',           'path' => 'chair/dashboard.php',         'icon' => 'dashboard'],
            ['label' => 'Subject Offerings',   'path' => 'registrar/offerings.php',     'icon' => 'event_note'],
            ['label' => 'Enrollment Requests', 'path' => 'chair/requests.php',           'icon' => 'approval'],
            ['label' => 'Assign Adviser',      'path' => 'chair/assign_adviser.php',     'icon' => 'person_add'],
            ['label' => 'Instructor Assignments', 'path' => 'chair/assign_instructor.php',  'icon' => 'assignment_ind'],
            ['label' => 'Curriculum',   'path' => 'registrar/curriculum.php',  'icon' => 'menu_book'],
        ];
        break;

    case 'adviser':
        $menu = [
            ['label' => 'Dashboard',           'path' => 'adviser/dashboard.php',  'icon' => 'dashboard'],
            ['label' => 'Enrollment Requests', 'path' => 'adviser/requests.php',   'icon' => 'approval'],
            ['label' => 'Advisory Class',      'path' => 'adviser/class_view.php', 'icon' => 'groups'],
        ];
        break;

    case 'instructor':
        $menu = [
            ['label' => 'Dashboard',              'path' => 'instructor/dashboard.php',    'icon' => 'dashboard'],
            ['label' => 'My Subjects',            'path' => 'instructor/subjects.php',     'icon' => 'menu_book'],
            ['label' => 'Student List / Grades',  'path' => 'instructor/students.php',     'icon' => 'groups'],
            ['label' => 'Grade Input',            'path' => 'instructor/grade_input.php',  'icon' => 'edit_note'],
            ['label' => 'Upload Grades',          'path' => 'instructor/upload_grades.php','icon' => 'upload'],
        ];
        break;

    case 'cashier':
        $menu = [
            ['label' => 'Dashboard', 'path' => 'cashier/dashboard.php', 'icon' => 'dashboard'],
            ['label' => 'Payments', 'path' => 'cashier/payments.php', 'icon' => 'payments'],
        ];
        break;

    case 'student':
    default:
        $menu = [
            ['label' => 'Dashboard',         'path' => 'student/dashboard.php',        'icon' => 'dashboard'],
            ['label' => 'Current Subjects',  'path' => 'student/subjects.php',         'icon' => 'menu_book'],
            ['label' => 'Grades / COG',      'path' => 'student/grades.php',           'icon' => 'grading'],
            ['label' => 'Checklist',         'path' => 'checklist.php',                'icon' => 'checklist'],
            ['label' => 'Online Enrollment', 'path' => 'student/enrollment.php',       'icon' => 'app_registration'],
            ['label' => 'Enrollment Status', 'path' => 'student/enrollment_status.php','icon' => 'track_changes'],
        ];
        $currentUser = current_user();
        if ($currentUser !== null && $currentUser['student_id'] > 0 && student_is_irregular((int) $currentUser['student_id'])) {
            $menu[] =             ['label' => 'Add/Drop Subjects', 'path' => 'student/add_drop.php', 'icon' => 'edit_note'];
        }
        $notifCount = 0;
        try {
            $nRow = fetch_one(
                'SELECT COUNT(*) AS cnt FROM student_notifications WHERE student_id = :sid AND dismissed = 0',
                ['sid' => (int) $currentUser['student_id']]
            );
            $notifCount = (int) ($nRow['cnt'] ?? 0);
        } catch (\Throwable $e) {}
        $menu[] = ['label' => 'Notifications', 'path' => 'student/notifications.php', 'icon' => 'notifications', 'count' => $notifCount];
        $menu[] = ['label' => 'Settings', 'path' => 'includes/settings.php', 'icon' => 'settings'];
        break;
}
?>
<?php foreach ($menu as $item): ?>
<?php $count = $item['count'] ?? 0; ?>
<a class="menu-item <?= ($activePage ?? '') === $item['label'] ? 'active' : '' ?>"
   href="<?= h(app_url($item['path'])) ?>">
    <span class="material-symbols-outlined sidebar-icon"><?= h($item['icon']) ?></span>
    <span class="sidebar-text"><?= h($item['label']) ?></span>
    <?php if ($count > 0): ?>
    <span class="sidebar-badge"><?= $count > 9 ? '9+' : $count ?></span>
    <?php endif; ?>
</a>
<?php endforeach; ?>
