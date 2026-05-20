<?php
declare(strict_types=1);

$menu = [];
$role = $user_role ?? (current_user()['role'] ?? 'student');

switch ($role) {
    case 'admin':
        $menu = [
            ['type' => 'header', 'label' => 'Administration'],
            ['label' => 'Dashboard',       'path' => 'admin/dashboard.php',        'icon' => 'dashboard'],
            ['label' => 'Users',           'path' => 'admin/users.php',            'icon' => 'group'],
            ['label' => 'Staff',           'path' => 'admin/staff.php',            'icon' => 'badge'],
            ['label' => 'Students',        'path' => 'admin/students.php',         'icon' => 'school'],
            ['label' => 'Audit Log',       'path' => 'admin/audit_log.php',        'icon' => 'history'],
            ['type' => 'header', 'label' => 'Academic'],
            ['label' => 'Program & Curriculum',      'path' => 'registrar/curriculum.php',        'icon' => 'menu_book'],
            ['label' => 'Departments & Sections', 'path' => 'registrar/departments.php', 'icon' => 'domain'],
            ['label' => 'Academic Term',   'path' => 'registrar/academic_term.php',     'icon' => 'calendar_month'],
            ['label' => 'Subject Offerings', 'path' => 'registrar/offerings.php',       'icon' => 'event_note'],
            
            ['type' => 'group', 'label' => 'Enrollment', 'icon' => 'app_registration', 'children' => [
                ['label' => 'Enrollment Dashboard', 'path' => 'admin/enrollment_dashboard.php','icon' => 'dashboard'],
                ['label' => 'Enroll Schedule', 'path' => 'registrar/enrollment_schedule.php','icon' => 'schedule'],
                ['label' => 'Enrollment Queue', 'path' => 'registrar/enrollment.php',         'icon' => 'app_registration'],
                ['label' => 'Enrollment List', 'path' => 'admin/enrollment_list.php',    'icon' => 'list_alt'],
                ['label' => 'Enrollment Analytics', 'path' => 'admin/enrollment_analytics.php', 'icon' => 'bar_chart'],
            ]],
            ['type' => 'header', 'label' => 'Finance'],
            ['label' => 'Fee Management', 'path' => 'admin/fees.php', 'icon' => 'receipt_long'],
            ['type' => 'divider'],
        ];
        break;

    case 'registrar':
        $menu = [
            ['label' => 'Dashboard',        'path' => 'registrar/dashboard.php',      'icon' => 'dashboard'],
            ['type' => 'header', 'label' => 'Records'],
            ['label' => 'Students',         'path' => 'admin/students.php',       'icon' => 'group'],
            ['label' => 'Enrollment List',  'path' => 'admin/enrollment_list.php',         'icon' => 'list_alt'],
            ['type' => 'header', 'label' => 'Academic'],
            ['label' => 'Program & Curriculum',       'path' => 'registrar/curriculum.php',     'icon' => 'menu_book'],
            ['label' => 'Curriculum View',  'path' => 'registrar/curriculum_view.php','icon' => 'preview'],
            ['label' => 'Departments & Sections', 'path' => 'registrar/departments.php', 'icon' => 'domain'],
            ['label' => 'Academic Term',    'path' => 'registrar/academic_term.php',       'icon' => 'calendar_month'],
            ['label' => 'Subject Offerings', 'path' => 'registrar/offerings.php',      'icon' => 'event_note'],
            ['type' => 'header', 'label' => 'Enrollment'],
            ['type' => 'group', 'label' => 'Enrollment', 'icon' => 'app_registration', 'children' => [
                ['label' => 'Enrollment Dashboard', 'path' => 'admin/enrollment_dashboard.php','icon' => 'dashboard'],
                ['label' => 'Enroll Schedule',  'path' => 'registrar/enrollment_schedule.php', 'icon' => 'schedule'],
                ['label' => 'Enrollment Queue', 'path' => 'registrar/enrollment.php',     'icon' => 'app_registration'],
                ['label' => 'Add/Drop Requests','path' => 'registrar/enrollment.php?type=add_drop', 'icon' => 'edit_note'],
                ['label' => 'Direct Enroll',    'path' => 'registrar/direct_enroll.php',  'icon' => 'person_add'],
            ]],
            ['type' => 'divider'],
            ['label' => 'Grade Management', 'path' => 'registrar/upload_grades.php',  'icon' => 'grading'],
            ['type' => 'header', 'label' => 'Finance'],
            ['label' => 'Fee Management', 'path' => 'admin/fees.php', 'icon' => 'receipt_long'],
        ];
        break;

    case 'chair':
        $menu = [
            ['label' => 'Dashboard',           'path' => 'chair/dashboard.php',         'icon' => 'dashboard'],
            ['type' => 'header', 'label' => 'Academic'],
            ['label' => 'Subject Offerings',   'path' => 'registrar/offerings.php',     'icon' => 'event_note'],
            ['label' => 'Curriculum',   'path' => 'registrar/curriculum.php',  'icon' => 'menu_book'],
            ['type' => 'header', 'label' => 'Enrollment'],
            ['label' => 'Enrollment Requests', 'path' => 'chair/requests.php',           'icon' => 'approval'],
            ['label' => 'Assign Adviser',      'path' => 'chair/assign_adviser.php',     'icon' => 'person_add'],
            ['label' => 'Instructor Assignments', 'path' => 'chair/assign_instructor.php',  'icon' => 'assignment_ind'],
            // ['type' => 'divider'],
        ];
        break;

    case 'adviser':
        $menu = [
            ['label' => 'Dashboard',           'path' => 'adviser/dashboard.php',  'icon' => 'dashboard'],
            ['type' => 'header', 'label' => 'Enrollment'],
            ['label' => 'Enrollment Requests', 'path' => 'adviser/requests.php',   'icon' => 'approval'],
            ['label' => 'Advisory Class',      'path' => 'adviser/class_view.php', 'icon' => 'groups'],
            // ['type' => 'divider'],
            // ['label' => 'Settings',            'path' => 'includes/settings.php',  'icon' => 'settings'],
        ];
        break;

    case 'instructor':
        $menu = [
            ['label' => 'Dashboard',              'path' => 'instructor/dashboard.php',    'icon' => 'dashboard'],
            ['type' => 'header', 'label' => 'Teaching'],
            ['label' => 'My Subjects',            'path' => 'instructor/subjects.php',     'icon' => 'menu_book'],
            ['label' => 'Student List / Grades',  'path' => 'instructor/students.php',     'icon' => 'groups'],
            ['label' => 'Grade Input',            'path' => 'instructor/grade_input.php',  'icon' => 'edit_note'],
            ['label' => 'Upload Grades',          'path' => 'instructor/upload_grades.php','icon' => 'upload'],
            // ['type' => 'divider'],
            // ['label' => 'Settings',               'path' => 'includes/settings.php',       'icon' => 'settings'],
        ];
        break;

    case 'cashier':
        $menu = [
            ['label' => 'Dashboard', 'path' => 'cashier/dashboard.php', 'icon' => 'dashboard'],
            ['label' => 'Payments', 'path' => 'cashier/payments.php', 'icon' => 'payments'],
            // ['type' => 'divider'],
            // ['label' => 'Settings', 'path' => 'includes/settings.php', 'icon' => 'settings'],
        ];
        break;

    case 'student':
    default:
        $currentUser = current_user();
        $menu = [
            ['type' => 'header', 'label' => 'General'],
            ['label' => 'Dashboard',         'path' => 'student/dashboard.php',        'icon' => 'dashboard'],
            ['label' => 'Current Subjects',  'path' => 'student/subjects.php',         'icon' => 'menu_book'],
            ['type' => 'header', 'label' => 'Records'],
            ['label' => 'Grades / COG',      'path' => 'student/grades.php',           'icon' => 'grading'],
            ['label' => 'Checklist',         'path' => 'checklist.php',                'icon' => 'checklist'],
            ['label' => 'My Curriculum',    'path' => 'registrar/curriculum.php',     'icon' => 'menu_book'],
            ['type' => 'header', 'label' => 'Enrollment'],
            ['label' => 'Online Enrollment', 'path' => 'student/enrollment.php',       'icon' => 'app_registration'],
            ['label' => 'Enrollment Status', 'path' => 'student/enrollment_status.php','icon' => 'track_changes'],
        ];
        if ($currentUser !== null && $currentUser['student_id'] > 0 && student_is_irregular((int) $currentUser['student_id'])) {
            $menu[] = ['label' => 'Add/Drop Subjects', 'path' => 'student/add_drop.php', 'icon' => 'edit_note'];
        }
        $notifCount = 0;
        try {
            $nRow = fetch_one(
                'SELECT COUNT(*) AS cnt FROM student_notifications WHERE student_id = :sid AND dismissed = 0',
                ['sid' => (int) $currentUser['student_id']]
            );
            $notifCount = (int) ($nRow['cnt'] ?? 0);
        } catch (\Throwable $e) {}
        $menu[] = ['type' => 'divider'];
        $menu[] = ['label' => 'Notifications', 'path' => 'student/notifications.php', 'icon' => 'notifications', 'count' => $notifCount];
        $menu[] = ['label' => 'Settings', 'path' => 'includes/settings.php', 'icon' => 'settings'];
        break;
}
?>
<?php foreach ($menu as $item): ?>
<?php if (($item['type'] ?? '') === 'header'): ?>
    <div class="sidebar-section-label"><?= h($item['label']) ?></div>
<?php elseif (($item['type'] ?? '') === 'divider'): ?>
    <div class="sidebar-divider"></div>
<?php elseif (($item['type'] ?? '') === 'group'): ?>
    <div class="sidebar-group-label"><?= h($item['label']) ?></div>
    <?php foreach ($item['children'] as $child): ?>
    <?php $count = $child['count'] ?? 0; ?>
    <a class="menu-item <?= ($activePage ?? '') === $child['label'] ? 'active' : '' ?>"
       href="<?= h(app_url($child['path'])) ?>"
       title="<?= h($child['label']) ?>">
        <span class="material-symbols-outlined sidebar-icon"><?= h($child['icon']) ?></span>
        <span class="sidebar-text"><?= h($child['label']) ?></span>
        <?php if ($count > 0): ?>
        <span class="sidebar-badge"><?= $count > 9 ? '9+' : $count ?></span>
        <?php endif; ?>
    </a>
    <?php endforeach; ?>
<?php else: ?>
    <?php $count = $item['count'] ?? 0; ?>
    <a class="menu-item <?= ($activePage ?? '') === $item['label'] ? 'active' : '' ?>"
       href="<?= h(app_url($item['path'])) ?>"
       title="<?= h($item['label']) ?>">
        <span class="material-symbols-outlined sidebar-icon"><?= h($item['icon']) ?></span>
        <span class="sidebar-text"><?= h($item['label']) ?></span>
        <?php if ($count > 0): ?>
        <span class="sidebar-badge"><?= $count > 9 ? '9+' : $count ?></span>
        <?php endif; ?>
    </a>
<?php endif; ?>
<?php endforeach; ?>
