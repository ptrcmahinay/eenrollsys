<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/migrations.php';
require_once __DIR__ . '/components/actions.php';
require_once __DIR__ . '/../vendor/autoload.php';

define('APP_ROOT', realpath(__DIR__ . '/..') ?: __DIR__ . '/..');

function db(): PDO
{
    global $pdo;
    static $migrated = false;
    if (!$migrated) {
        $migrated = true;
        ensure_soft_delete_columns();
        ensure_notifications_table();
        ensure_email_verification_columns();
        ensure_fee_items_table();
        ensure_curriculum_columns();
        ensure_drop_units_column();
        ensure_student_subjects_columns();
        ensure_fee_workflow_columns();
        ensure_add_drop_table();
        ensure_processing_columns();
        ensure_payments_table();
    }
    return $pdo;
}

function getInitials($name) {
    $words = explode(' ', trim($name));
    $initials = '';

    foreach ($words as $w) {
        $initials .= strtoupper($w[0]);
        if (strlen($initials) >= 2) break;
    }

    return $initials ?: 'U';
}

function renderBreadcrumbs(string $pageTitle, string $role, array $breadcrumbs = []): void
{
    $routes = [
        'student'    => 'student/dashboard.php',
        'admin'      => 'admin/dashboard.php',
        'registrar'  => 'registrar/dashboard.php',
        'instructor' => 'instructor/dashboard.php',
        'cashier'    => 'cashier/dashboard.php',
    ];

    $home = app_url($routes[$role] ?? 'index.php');

    echo '<nav class="text-sm text-gray-500 mb-0 flex items-center gap-2">';
    echo '<a href="' . $home . '" class="flex items-center gap-1 hover:text-gray-700">';
    echo '<span class="material-symbols-outlined text-base">home</span>';
    echo '</a>';

    if (!empty($breadcrumbs)) {
        foreach ($breadcrumbs as $crumb) {
            echo '<span class="material-symbols-outlined text-base">chevron_right</span>';
            if (isset($crumb['url'])) {
                echo '<a href="' . h($crumb['url']) . '" class="hover:text-gray-700">' . h($crumb['label']) . '</a>';
            } else {
                echo '<span class="page-title font-medium">' . h($crumb['label']) . '</span>';
            }
        }
    } else {
        echo '<span class="material-symbols-outlined text-base">chevron_right</span>';
        echo '<span class="page-title font-medium">' . h($pageTitle) . '</span>';
    }

    echo '</nav>';
}

function app_base_url(): string
{
    static $baseUrl = null;
    if ($baseUrl !== null) {
        return $baseUrl;
    }

    $scriptFile = isset($_SERVER['SCRIPT_FILENAME']) ? realpath((string) $_SERVER['SCRIPT_FILENAME']) : false;
    $scriptName = isset($_SERVER['SCRIPT_NAME']) ? str_replace('\\', '/', (string) $_SERVER['SCRIPT_NAME']) : '';

    if (!$scriptFile || $scriptName === '') {
        $baseUrl = '';
        return $baseUrl;
    }

    $scriptDirFs = str_replace('\\', '/', dirname($scriptFile));
    $appRootFs = str_replace('\\', '/', APP_ROOT);
    $urlDir = trim(str_replace('\\', '/', dirname($scriptName)), '/');

    if (str_starts_with($scriptDirFs, $appRootFs)) {
        $relativeDir = trim(substr($scriptDirFs, strlen($appRootFs)), '/');
        $levels = $relativeDir === '' ? 0 : count(explode('/', $relativeDir));
        $urlParts = $urlDir === '' ? [] : explode('/', $urlDir);
        if ($levels > 0) {
            $urlParts = array_slice($urlParts, 0, max(0, count($urlParts) - $levels));
        }
        $baseUrl = '/' . implode('/', array_filter($urlParts, static fn($part) => $part !== ''));
    } else {
        $baseUrl = '/' . $urlDir;
    }

    $baseUrl = rtrim($baseUrl, '/');
    if ($baseUrl === '/') {
        $baseUrl = '';
    }

    return $baseUrl;
}

function app_url(string $path = ''): string
{
    $path = ltrim($path, '/');
    return $path === '' ? (app_base_url() ?: '/') : (app_base_url() . '/' . $path);
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header('Location: ' . app_url($path));
    exit;
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function flash(string $type, string $message): void
{
    $_SESSION['flashes'][] = ['type' => $type, 'message' => $message];
}

function get_flashes(): array
{
    $flashes = $_SESSION['flashes'] ?? [];
    unset($_SESSION['flashes']);
    return is_array($flashes) ? $flashes : [];
}

function create_notification(string $role, int $entityId, string $type, string $subject, string $body): void
{
    try {
        if ($role === 'student') {
            execute_sql(
                'INSERT INTO student_notifications (student_id, type, subject, body, is_read, dismissed, created_at)
                 VALUES (:entity_id, :type, :subject, :body, 0, 0, NOW())',
                ['entity_id' => $entityId, 'type' => $type, 'subject' => $subject, 'body' => $body]
            );
        } elseif (in_array($role, ['adviser', 'chair', 'registrar', 'admin'], true)) {
            execute_sql(
                'INSERT INTO staff_notifications (staff_id, type, subject, body, is_read, dismissed, created_at)
                 VALUES (:entity_id, :type, :subject, :body, 0, 0, NOW())',
                ['entity_id' => $entityId, 'type' => $type, 'subject' => $subject, 'body' => $body]
            );
        }
    } catch (\Throwable $e) {
    }
}

function get_inline_notifications(string $role, int $entityId): array
{
    try {
        if ($role === 'student') {
            return fetch_all(
                'SELECT * FROM student_notifications WHERE student_id = :eid AND dismissed = 0 ORDER BY created_at DESC LIMIT 5',
                ['eid' => $entityId]
            );
        }
        return fetch_all(
            'SELECT * FROM staff_notifications WHERE staff_id = :eid AND dismissed = 0 ORDER BY created_at DESC LIMIT 5',
            ['eid' => $entityId]
        );
    } catch (\Throwable $e) {
        return [];
    }
}

function dismiss_notification(string $role, int $notificationId): void
{
    try {
        if ($role === 'student') {
            execute_sql('UPDATE student_notifications SET dismissed = 1 WHERE id = :id', ['id' => $notificationId]);
        } else {
            execute_sql('UPDATE staff_notifications SET dismissed = 1 WHERE id = :id', ['id' => $notificationId]);
        }
    } catch (\Throwable $e) {
    }
}

function mark_notification_read(string $role, int $notificationId): void
{
    try {
        if ($role === 'student') {
            execute_sql('UPDATE student_notifications SET is_read = 1 WHERE id = :id', ['id' => $notificationId]);
        } else {
            execute_sql('UPDATE staff_notifications SET is_read = 1 WHERE id = :id', ['id' => $notificationId]);
        }
    } catch (\Throwable $e) {
    }
}

function inline_notification_badge_class(string $type): string
{
    return match ($type) {
        'success' => 'notif-success',
        'error', 'danger' => 'notif-error',
        'warning' => 'notif-warning',
        default => 'notif-info',
    };
}

function inline_notification_icon(string $type): string
{
    return match ($type) {
        'success' => 'check_circle',
        'error', 'danger' => 'error',
        'warning' => 'warning',
        default => 'info',
    };
}

function fetch_one(string $sql, array $params = []): ?array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    $row = $statement->fetch();
    return $row === false ? null : $row;
}

function fetch_all(string $sql, array $params = []): array
{
    $statement = db()->prepare($sql);
    $statement->execute($params);
    return $statement->fetchAll();
}

function execute_sql(string $sql, array $params = []): bool
{
    $statement = db()->prepare($sql);
    return $statement->execute($params);
}

function setting(string $key, ?string $default = null): string
{
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        foreach (fetch_all('SELECT setting_key, setting_value FROM settings') as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    return array_key_exists($key, $settings) ? (string) $settings[$key] : (string) ($default ?? '');
}

function set_setting(string $key, string $value): void
{
    execute_sql(
        'INSERT INTO settings (setting_key, setting_value) VALUES (:key, :value)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        ['key' => $key, 'value' => $value]
    );
}

function semester_label(string $semester): string
{
    $map = [
        '1' => 'First Semester',
        '2' => 'Second Semester',
        'mid' => 'Midyear',
        'Mid' => 'Midyear',
        '1st' => 'First Semester',
        '2nd' => 'Second Semester',
    ];

    return $map[$semester] ?? $semester;
}

function role_priority(string $roleName): int
{
    $priority = [
        'admin' => 1,
        'registrar' => 2,
        'department_chair' => 3,
        'adviser' => 4,
        'instructor' => 5,
        'cashier' => 6,
        'student' => 7,
    ];

    return $priority[$roleName] ?? 99;
}

function normalize_role_name(string $roleName): string
{
    return $roleName === 'department_chair' ? 'chair' : $roleName;
}

function get_user_roles(int $userId): array
{
    $roles = fetch_all(
        'SELECT r.role_name
         FROM user_roles ur
         INNER JOIN roles r ON r.roles_id = ur.role_id
         WHERE ur.user_id = :user_id
         ORDER BY r.role_name',
        ['user_id' => $userId]
    );

    return array_map(static fn($row) => (string) $row['role_name'], $roles);
}

function primary_role(int $userId): string
{
    $roles = get_user_roles($userId);
    usort($roles, static fn($a, $b) => role_priority($a) <=> role_priority($b));
    return normalize_role_name($roles[0] ?? 'student');
}

function login_user(array $user): void
{
    $_SESSION['user_id'] = (int) $user['users_id'];
    $_SESSION['username'] = $user['username'] ?: $user['email'];
    $_SESSION['display_name'] = $user['display_name'] ?? $user['username'] ?? $user['email'];
    $_SESSION['role'] = primary_role((int) $user['users_id']);
}

function current_user(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }

    $userId = (int) $_SESSION['user_id'];
    $user = fetch_one(
        'SELECT u.users_id, u.username, u.email, u.student_id,
                COALESCE(u.display_name, s.full_name, st.full_name, u.username, u.email) AS display_name
         FROM users u
         LEFT JOIN students s ON s.id = u.student_id
         LEFT JOIN staff st ON st.users_id = u.users_id
         WHERE u.users_id = :user_id',
        ['user_id' => $userId]
    );

    if ($user === null) {
        session_destroy();
        return null;
    }

    $user['role'] = primary_role($userId);
    $user['roles'] = get_user_roles($userId);
    return $user;
}

function require_login(): array
{
    $user = current_user();
    if ($user === null) {
        flash('error', 'Please log in first.');
        redirect('auth/login.php');
    }
    return $user;
}

function require_role(string|array $roles): array
{
    $user = require_login();
    $roles = (array) $roles;
    if (!in_array($user['role'], $roles, true)) {
        http_response_code(403);
        echo '<h1>403 Forbidden</h1><p>You do not have permission to view this page.</p>';
        exit;
    }
    return $user;
}

function current_staff(): ?array
{
    $user = require_login();
    return fetch_one(
        'SELECT st.*, d.department_code, d.department_name
         FROM staff st
         LEFT JOIN departments d ON d.dept_id = st.dept_id
         WHERE st.users_id = :user_id',
        ['user_id' => (int) $user['users_id']]
    );
}

function current_student(): ?array
{
    $user = require_login();
    if (empty($user['student_id'])) {
        return null;
    }

    return fetch_one(
        'SELECT s.*, p.program_code, p.program_name, p.department_id,
                sec.section_name, sec.year_level AS section_year_level
         FROM students s
         INNER JOIN programs p ON p.programs_id = s.program_id
         LEFT JOIN sections sec ON sec.id = s.section_id
         WHERE s.id = :student_id',
        ['student_id' => (int) $user['student_id']]
    );
}

function current_academic_year(): ?array
{
    return fetch_one('SELECT * FROM academic_years WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
}

function current_term(): ?array
{
    return fetch_one(
        'SELECT t.*, ay.year_label, ay.start_year, ay.end_year
         FROM academic_terms t
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE t.is_active = 1
         ORDER BY t.id DESC
         LIMIT 1'
    );
}

function request_deadline(array $request, string $stage): ?string
{
    $daysMap = [
        'adviser' => (int) setting('adviser_approval_days', '3'),
        'chair' => (int) setting('chair_approval_days', '3'),
        'registrar' => (int) setting('registrar_approval_days', '3'),
    ];
    $days = $daysMap[$stage] ?? 3;
    if ($days <= 0) return null;

    $fromMap = [
        'adviser' => 'created_at',
        'chair' => 'adviser_processed_at',
        'registrar' => 'chair_processed_at',
    ];
    $fromCol = $fromMap[$stage] ?? 'created_at';
    $fromDate = $request[$fromCol] ?? null;
    if ($fromDate === null) return null;

    return date('Y-m-d', strtotime((string) $fromDate . " +{$days} days"));
}

function request_deadline_passed(array $request, string $stage): bool
{
    $deadline = request_deadline($request, $stage);
    if ($deadline === null) return false;
    return strtotime($deadline) < time();
}

function request_deadline_badge(array $request, string $stage): string
{
    $deadline = request_deadline($request, $stage);
    if ($deadline === null) return '';
    $label = date('M j, Y', strtotime($deadline));
    if (request_deadline_passed($request, $stage)) {
        return '<span class="badge danger">Deadline passed — ' . $label . '</span>';
    }
    $remaining = ceil((strtotime($deadline) - time()) / 86400);
    return '<span class="badge warning">' . $remaining . ' day(s) left — ' . $label . '</span>';
}

function grade_deadline_passed(): bool
{
    $days = (int) setting('grade_deadline_days', '30');
    if ($days <= 0) return false;
    $term = current_term();
    if ($term === null || empty($term['end_date'])) return false;
    $gradeDeadline = date('Y-m-d', strtotime((string) $term['end_date'] . " +{$days} days"));
    return strtotime($gradeDeadline) < time();
}

function grade_deadline_badge(): string
{
    $days = (int) setting('grade_deadline_days', '30');
    if ($days <= 0) return 'No deadline set';
    $term = current_term();
    if ($term === null || empty($term['end_date'])) return 'No term end date set';
    $deadline = date('Y-m-d', strtotime((string) $term['end_date'] . " +{$days} days"));
    $label = date('M j, Y', strtotime($deadline));
    if (grade_deadline_passed()) {
        return '<span class="badge danger">Grade deadline passed — ' . $label . '</span>';
    }
    $remaining = ceil((strtotime($deadline) - time()) / 86400);
    return '<span class="badge warning">Grade deadline: ' . $remaining . ' day(s) left — ' . $label . '</span>';
}

function enrollment_is_open(?int $yearLevel = null): bool
{
    $term = current_term();
    if ($term === null || (int) $term['enrollment_open'] !== 1 || setting('allow_online_enrollment', '1') !== '1') {
        return false;
    }

    // If no year level given, try to resolve from the current student session.
    if ($yearLevel === null) {
        $student = current_student();
        if ($student !== null) {
            $yearLevel = (int) $student['year_level'];
        }
    }

    // Check if any schedules exist for this term.
    $scheduleCount = fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_schedules WHERE term_id = :tid',
        ['tid' => (int) $term['id']]
    );
    if ($scheduleCount === null || (int) $scheduleCount['cnt'] === 0) {
        // No schedules configured — fall back to term-wide open flag.
        return true;
    }

    // If we still have no year level, enrollment is considered closed
    // (we cannot determine which schedule window applies).
    if ($yearLevel === null) {
        return false;
    }

    $schedule = fetch_one(
        'SELECT * FROM enrollment_schedules WHERE term_id = :tid AND year_level = :yl LIMIT 1',
        ['tid' => (int) $term['id'], 'yl' => $yearLevel]
    );
    if ($schedule === null) {
        return false; // No window configured for this year level.
    }

    $now       = new \DateTimeImmutable('now');
    $openDT    = new \DateTimeImmutable($schedule['open_date']  . ' ' . $schedule['open_time']);
    $closeDT   = new \DateTimeImmutable($schedule['close_date'] . ' ' . $schedule['close_time']);

    return $now >= $openDT && $now <= $closeDT;
}

/**
 * Human-readable status of the enrollment window for a given year level.
 * Returns ['open' => bool, 'message' => string].
 *
 * @return array{open: bool, message: string}
 */
function enrollment_window_status(int $yearLevel): array
{
    $term = current_term();
    if ($term === null || (int) $term['enrollment_open'] !== 1 || setting('allow_online_enrollment', '1') !== '1') {
        return ['open' => false, 'message' => 'Online enrollment is currently closed.'];
    }

    $scheduleCount = fetch_one(
        'SELECT COUNT(*) AS cnt FROM enrollment_schedules WHERE term_id = :tid',
        ['tid' => (int) $term['id']]
    );
    if ($scheduleCount === null || (int) $scheduleCount['cnt'] === 0) {
        return ['open' => true, 'message' => 'Enrollment is open.'];
    }

    $schedule = fetch_one(
        'SELECT * FROM enrollment_schedules WHERE term_id = :tid AND year_level = :yl LIMIT 1',
        ['tid' => (int) $term['id'], 'yl' => $yearLevel]
    );
    if ($schedule === null) {
        return ['open' => false, 'message' => 'No enrollment window is set for your year level.'];
    }

    $now     = new \DateTimeImmutable('now');
    $openDT  = new \DateTimeImmutable($schedule['open_date']  . ' ' . $schedule['open_time']);
    $closeDT = new \DateTimeImmutable($schedule['close_date'] . ' ' . $schedule['close_time']);

    if ($now < $openDT) {
        return [
            'open'    => false,
            'message' => 'Your enrollment window opens on ' . $openDT->format('F j, Y \a\t g:i A') . '.',
        ];
    }
    if ($now > $closeDT) {
        return [
            'open'    => false,
            'message' => 'Your enrollment window closed on ' . $closeDT->format('F j, Y \a\t g:i A') . '.',
        ];
    }
    return [
        'open'    => true,
        'message' => 'Enrollment is open until ' . $closeDT->format('F j, Y \a\t g:i A') . '.',
    ];
}

function page_title_suffix(): string
{
    return setting('system_name', 'E-EnrollSys');
}

function render_page(string $pageTitle, string $activePage, string $content, array $extra = []): void
{
    $page_title = $pageTitle;
    $activePage = $activePage;
    $main_content = $content;
    $show_sidebar = $extra['show_sidebar'] ?? true;
    $breadcrumbs = $extra['breadcrumbs'] ?? [];
    $modals = $extra['modals'] ?? [];
    include __DIR__ . '/template.php';
}

function format_money(float|int|string|null $amount): string
{
    return number_format((float) ($amount ?? 0), 2);
}

function generate_student_number(): string
{
    $yearPrefix = date('Y');
    $row = fetch_one('SELECT MAX(CAST(RIGHT(student_number, 4) AS UNSIGNED)) AS last_number FROM students WHERE student_number LIKE :prefix', [
        'prefix' => $yearPrefix . '%',
    ]);

    $next = (int) ($row['last_number'] ?? 0) + 1;
    return $yearPrefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
}

function parse_numeric_grade(?string $grade): ?float
{
    if ($grade === null) {
        return null;
    }

    $grade = strtoupper(trim($grade));
    if ($grade === '' || !is_numeric($grade)) {
        return null;
    }

    return (float) $grade;
}

function grade_is_passing(?string $grade): bool
{
    if ($grade === null) {
        return false;
    }

    $normalized = strtoupper(trim($grade));
    if (in_array($normalized, ['P', 'PASSED', 'S'], true)) {
        return true;
    }

    if (in_array($normalized, ['INC', 'DRP', 'DROP', 'FAILED', 'W'], true)) {
        return false;
    }

    $numeric = parse_numeric_grade($normalized);
    if ($numeric === null) {
        return false;
    }

    return $numeric > 0 && $numeric <= 3.0;
}

function grade_is_blocking(?string $grade): bool
{
    if ($grade === null) {
        return true;
    }

    $normalized = strtoupper(trim($grade));
    if (in_array($normalized, ['INC', 'DRP', 'DROP', 'FAILED', '5', '5.0', 'W'], true)) {
        return true;
    }

    $numeric = parse_numeric_grade($normalized);
    if ($numeric === null) {
        return !in_array($normalized, ['P', 'PASSED', 'S'], true);
    }

    return $numeric > 3.0;
}

function student_grade_lookup(int $studentId): array
{
    $rows = fetch_all(
        'SELECT subject_id, final_grade, MAX(created_at) AS latest_created_at
         FROM student_subjects
         WHERE student_id = :student_id
         GROUP BY subject_id, final_grade
         ORDER BY latest_created_at DESC',
        ['student_id' => $studentId]
    );

    $lookup = [];
    foreach ($rows as $row) {
        $subjectId = (int) $row['subject_id'];
        if (!array_key_exists($subjectId, $lookup)) {
            $lookup[$subjectId] = $row['final_grade'];
        }
    }

    return $lookup;
}

function student_is_irregular(int $studentId): bool
{
    $rows = fetch_all(
        'SELECT final_grade FROM student_subjects WHERE student_id = :student_id AND final_grade IS NOT NULL',
        ['student_id' => $studentId]
    );

    foreach ($rows as $row) {
        if (grade_is_blocking($row['final_grade'])) {
            return true;
        }
    }

    return false;
}

function student_status_recommendation(int $studentId): string
{
    return student_is_irregular($studentId) ? 'irregular' : 'regular';
}

function get_active_term_semester_key(): string
{
    $term = current_term();
    if ($term === null) {
        return '1st';
    }

    return $term['semester'] === '2' ? '2nd' : ($term['semester'] === 'mid' ? 'mid' : '1st');
}

function get_student_program_targets(array $student): array
{
    return [
        'program_id' => (int) $student['program_id'],
        'year_level' => (int) $student['year_level'],
        'semester' => get_active_term_semester_key(),
    ];
}

function prerequisite_status_for_curriculum(int $studentId, array $curriculumRow, array $gradeLookup = []): array
{
    if ($gradeLookup === []) {
        $gradeLookup = student_grade_lookup($studentId);
    }

    $prereqs = [];
    for ($i = 1; $i <= 3; $i++) {
        $key = $i === 1 ? 'prerequisite_subject_id' : 'prerequisite_subject_' . $i . '_id';
        $val = isset($curriculumRow[$key]) ? (int) $curriculumRow[$key] : 0;
        if ($val > 0) $prereqs[] = $val;
    }

    foreach ($prereqs as $prereqId) {
        $grade = $gradeLookup[$prereqId] ?? null;
        if ($grade === null) {
            $prerequisite = fetch_one('SELECT subject_code FROM subjects WHERE subject_id = :id', ['id' => $prereqId]);
            return ['eligible' => false, 'reason' => 'Missing prerequisite ' . ($prerequisite['subject_code'] ?? '#' . $prereqId)];
        }
        if (!grade_is_passing((string) $grade)) {
            $prerequisite = fetch_one('SELECT subject_code FROM subjects WHERE subject_id = :id', ['id' => $prereqId]);
            return ['eligible' => false, 'reason' => 'Prerequisite not passed: ' . ($prerequisite['subject_code'] ?? '#' . $prereqId) . ' (' . $grade . ')'];
        }
    }

    $standing = $curriculumRow['standing'] ?? null;
    if ($standing !== null && $standing !== '') {
        $student = fetch_one('SELECT year_level FROM students WHERE id = :id', ['id' => $studentId]);
        if ($student !== null) {
            $studentYear = (int) $student['year_level'];
            if (stripos($standing, '4th') !== false && $studentYear < 4) {
                return ['eligible' => false, 'reason' => 'Requires 4th year standing'];
            }
            if (stripos($standing, '3rd') !== false && $studentYear < 3) {
                return ['eligible' => false, 'reason' => 'Requires 3rd year standing'];
            }
            if (stripos($standing, '2nd') !== false && $studentYear < 2) {
                return ['eligible' => false, 'reason' => 'Requires 2nd year standing'];
            }
        }
    }

    return ['eligible' => true, 'reason' => ''];
}

function regular_offerings_for_student(int $studentId, int $termId, int $sectionId): array
{
    $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]);
    if ($student === null) {
        return [];
    }

    $targets = get_student_program_targets($student);
    return fetch_all(
         'SELECT o.*, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units, sub.lec_credit, sub.lab_credit,
                pc.curriculum_id, pc.prerequisite_subject_id,
                sec.section_name, sec.year_level,
                CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
         FROM section_subject_offerings o
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
         INNER JOIN sections sec ON sec.id = o.section_id
         LEFT JOIN staff st ON st.staff_id = o.instructor_id
         WHERE o.term_id = :term_id
           AND o.section_id = :section_id
           AND pc.program_id = :program_id
           AND pc.year_level = :year_level
           AND pc.semester = :semester
         ORDER BY sub.subject_code',
        [
            'term_id' => $termId,
            'section_id' => $sectionId,
            'program_id' => $targets['program_id'],
            'year_level' => (string) $targets['year_level'],
            'semester' => $targets['semester'],
        ]
    );
}

function irregular_offerings_for_student(int $studentId, int $termId): array
{
    $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]);
    if ($student === null) {
        return [];
    }

    $gradeLookup = student_grade_lookup($studentId);
    $rows = fetch_all(
         'SELECT o.*, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units, sub.lec_credit, sub.lab_credit,
                pc.curriculum_id, pc.prerequisite_subject_id, pc.year_level AS curriculum_year_level,
                sec.section_name, sec.year_level,
                CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
         FROM section_subject_offerings o
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
         INNER JOIN sections sec ON sec.id = o.section_id
         LEFT JOIN staff st ON st.staff_id = o.instructor_id
         WHERE o.term_id = :term_id
           AND pc.program_id = :program_id
         ORDER BY pc.year_level, sub.subject_code',
        [
            'term_id' => $termId,
            'program_id' => (int) $student['program_id'],
        ]
    );

    // Build a set of subject_ids the student already passed — skip re-enrollment
    $passedSubjectIds = [];
    foreach ($gradeLookup as $subjectId => $grade) {
        if (grade_is_passing((string) $grade)) {
            $passedSubjectIds[$subjectId] = true;
        }
    }

    $eligible = [];
    foreach ($rows as $row) {
        // Skip subjects the student already passed
        if (isset($passedSubjectIds[(int) $row['subject_id']])) {
            continue;
        }
        $status = prerequisite_status_for_curriculum($studentId, $row, $gradeLookup);
        $row['eligible'] = $status['eligible'];
        $row['eligibility_reason'] = $status['reason'];
        $eligible[] = $row;
    }

    return $eligible;
}

function section_capacity(int $sectionId): int
{
    $section = fetch_one('SELECT max_slots FROM sections WHERE id = :id', ['id' => $sectionId]);
    $defaultSlots = (int) setting('max_section_slots', '40');
    if ($section === null) {
        return $defaultSlots;
    }

    return (int) ($section['max_slots'] ?: $defaultSlots);
}

function section_enrollment_count(int $sectionId, int $termId): int
{
    $row = fetch_one(
        'SELECT COUNT(DISTINCT student_id) AS total
         FROM student_subjects
         WHERE term_id = :term_id AND section_id = :section_id AND enrollment_status = "enrolled"',
        ['term_id' => $termId, 'section_id' => $sectionId]
    );
    return (int) ($row['total'] ?? 0);
}

function section_has_slot(int $sectionId, int $termId): bool
{
    return section_enrollment_count($sectionId, $termId) < section_capacity($sectionId);
}

function financial_profile(array $student, ?array $term = null): array
{
    $term = $term ?? current_term();
    $currentStartYear = (int) ($term['start_year'] ?? date('Y'));
    $entryYear = (int) $student['entry_year'];
    $yearsInCollege = max(1, $currentStartYear - $entryYear + 1);
    $override = (string) ($student['ra10931_override'] ?? 'auto');

    if ($override !== '' && $override !== 'auto') {
        $status = $override;
    } elseif ($yearsInCollege <= 5) {
        $status = 'free';
    } else {
        $status = 'tuition';
    }

    $tuitionPerUnit = (float) setting('tuition_per_unit', '550');

    return [
        'years_in_college' => $yearsInCollege,
        'status' => $status,
        'label' => $status === 'free' ? 'RA 10931 (Free Education)' : 'Tuition Paying',
        'tuition_per_unit' => $tuitionPerUnit,
    ];
}

function calculate_request_totals(int $studentId, array $offeringIds): array
{
    if ($offeringIds === []) {
        $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]) ?? ['entry_year' => date('Y'), 'ra10931_override' => 'auto'];
        return ['units' => 0.0, 'amount' => 0.0, 'lab_fee' => 0.0, 'financial' => financial_profile($student)];
    }

    $placeholders = implode(',', array_fill(0, count($offeringIds), '?'));
    $statement = db()->prepare("SELECT SUM(sub.lec_credit + sub.lab_credit) AS total_units FROM section_subject_offerings o INNER JOIN subjects sub ON sub.subject_id = o.subject_id WHERE o.id IN ($placeholders)");
    $statement->execute($offeringIds);
    $totalUnits = (float) ($statement->fetchColumn() ?: 0);

    $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId]) ?? ['entry_year' => date('Y'), 'ra10931_override' => 'auto'];
    $financial = financial_profile($student);

    $term = current_term();
    $feeItems = fee_items_for_enrollment((int) $student['program_id'], (int) $student['year_level'], (string) $term['semester']);
    $tuitionPerUnit = 0;
    $labFeeRate = 0;
    if (isset($feeItems['assessment'])) {
        foreach ($feeItems['assessment'] as $fi) {
            if (strcasecmp($fi['fee_name'], 'tuition') === 0) {
                $tuitionPerUnit = (float) $fi['amount'];
                break;
            }
        }
    }
    if (isset($feeItems['laboratory'])) {
        foreach ($feeItems['laboratory'] as $fi) {
            $labFeeRate = (float) $fi['amount'];
            break;
        }
    }

    $tuitionAmount = ($financial['status'] === 'tuition') ? ($totalUnits * $tuitionPerUnit) : 0.0;

    $labCredits = total_lab_credits($offeringIds);
    $labFee = $labCredits * $labFeeRate;

    $totalAmount = $tuitionAmount + $labFee;

    return ['units' => $totalUnits, 'amount' => $totalAmount, 'tuition' => $tuitionAmount, 'lab_fee' => $labFee, 'lab_credits' => $labCredits, 'financial' => $financial];
}

function lab_fee_per_unit(int $programId): float
{
    $prog = fetch_one('SELECT lab_fee_per_unit FROM programs WHERE programs_id = :id', ['id' => $programId]);
    return (float) ($prog['lab_fee_per_unit'] ?? 0);
}

function total_lab_credits(array $offeringIds): float
{
    if ($offeringIds === []) return 0.0;
    $ph = implode(',', array_fill(0, count($offeringIds), '?'));
    $st = db()->prepare("SELECT SUM(sub.lab_credit) FROM section_subject_offerings o INNER JOIN subjects sub ON sub.subject_id = o.subject_id WHERE o.id IN ($ph)");
    $st->execute($offeringIds);
    return (float) ($st->fetchColumn() ?: 0);
}

function fee_items_for_enrollment(int $programId, int $yearLevel, string $semester): array
{
    $rows = fetch_all(
        'SELECT id, category, fee_name, amount, is_mandatory FROM fee_items
         WHERE is_active = 1 AND (program_id IS NULL OR program_id = :pid)
           AND (year_level IS NULL OR year_level = :yl)
           AND (semester IS NULL OR semester = :sem)
         ORDER BY category, is_mandatory DESC',
        ['pid' => $programId, 'yl' => $yearLevel, 'sem' => $semester]
    );
    $grouped = ['laboratory' => [], 'other' => [], 'assessment' => []];
    foreach ($rows as $r) {
        $cat = $r['category'];
        if (isset($grouped[$cat])) $grouped[$cat][] = $r;
    }
    return $grouped;
}

function existing_request_for_student_term(int $studentId, int $termId): ?array
{
    return fetch_one(
        'SELECT * FROM enrollment_requests WHERE student_id = :student_id AND term_id = :term_id AND workflow_status NOT IN ("cancelled") ORDER BY id DESC LIMIT 1',
        ['student_id' => $studentId, 'term_id' => $termId]
    );
}

function create_enrollment_request(int $studentId, int $termId, int $sectionId, string $studentStatus, array $offeringIds): int
{
    $studentStatus = $studentStatus === 'irregular' ? 'irregular' : 'regular';
    $totals = calculate_request_totals($studentId, $offeringIds);

    execute_sql(
        'INSERT INTO enrollment_requests (
            student_id, term_id, requested_section_id, requested_status,
            workflow_status, adviser_status, chair_status, registrar_status,
            adviser_remark, chair_remark, registrar_remark,
            ra10931_status, total_units, total_amount
         ) VALUES (
            :student_id, :term_id, :section_id, :requested_status,
            "submitted", "pending", "pending", "pending",
            "", "", "",
            :ra_status, :total_units, :total_amount
         )',
        [
            'student_id' => $studentId,
            'term_id' => $termId,
            'section_id' => $sectionId,
            'requested_status' => $studentStatus,
            'ra_status' => $totals['financial']['status'],
            'total_units' => $totals['units'],
            'total_amount' => $totals['amount'],
        ]
    );

    $requestId = (int) db()->lastInsertId();
    foreach ($offeringIds as $offeringId) {
        execute_sql(
            'INSERT INTO enrollment_request_items (request_id, offering_id, action_type) VALUES (:request_id, :offering_id, "add")',
            ['request_id' => $requestId, 'offering_id' => $offeringId]
        );
    }

    log_audit($requestId, 'student_submit', 'student', null, 'submitted', null);

    $term = fetch_one(
        'SELECT ay.year_label, t.semester FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = :tid',
        ['tid' => $termId]
    );
    $termLabel = $term ? ($term['year_label'] . ' ' . semester_label((string) $term['semester'])) : 'the current term';

    send_enrollment_notification($studentId,
        'Enrollment Request Submitted',
        'Your enrollment request for ' . $termLabel . ' has been submitted successfully with ' . $totals['units'] . ' units. It is now pending adviser review.'
    );

    return $requestId;
}

function create_enrollment_request_draft(int $studentId, int $termId, int $sectionId, string $studentStatus, array $offeringIds): int
{
    $studentStatus = $studentStatus === 'irregular' ? 'irregular' : 'regular';
    $totals = calculate_request_totals($studentId, $offeringIds);

    execute_sql(
        'INSERT INTO enrollment_requests (
            student_id, term_id, requested_section_id, requested_status,
            workflow_status, adviser_status, chair_status, registrar_status,
            adviser_remark, chair_remark, registrar_remark,
            ra10931_status, total_units, total_amount
         ) VALUES (
            :student_id, :term_id, :section_id, :requested_status,
            "draft", "pending", "pending", "pending",
            "", "", "",
            :ra_status, :total_units, :total_amount
         )',
        [
            'student_id' => $studentId,
            'term_id' => $termId,
            'section_id' => $sectionId,
            'requested_status' => $studentStatus,
            'ra_status' => $totals['financial']['status'],
            'total_units' => $totals['units'],
            'total_amount' => $totals['amount'],
        ]
    );

    $requestId = (int) db()->lastInsertId();
    foreach ($offeringIds as $offeringId) {
        execute_sql(
            'INSERT INTO enrollment_request_items (request_id, offering_id, action_type) VALUES (:request_id, :offering_id, "add")',
            ['request_id' => $requestId, 'offering_id' => $offeringId]
        );
    }

    return $requestId;
}

function enrollment_request_items(int $requestId): array
{
    return fetch_all(
        'SELECT eri.*, o.section_id, o.curriculum_id, o.subject_id, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units, sub.lec_credit, sub.lab_credit,
                pc.prerequisite_subject_id,
                sec.section_name, sec.year_level,
                o.day_of_week, o.time_range, o.room,
                CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
         FROM enrollment_request_items eri
         INNER JOIN section_subject_offerings o ON o.id = eri.offering_id
         INNER JOIN subjects sub ON sub.subject_id = o.subject_id
         INNER JOIN program_curriculum pc ON pc.curriculum_id = o.curriculum_id
         INNER JOIN sections sec ON sec.id = o.section_id
         LEFT JOIN staff st ON st.staff_id = o.instructor_id
         WHERE eri.request_id = :request_id
         ORDER BY sub.subject_code',
        ['request_id' => $requestId]
    );
}

function request_student_department_filter_sql(string $alias = 'p'): string
{
    return "$alias.department_id";
}

function save_grade(int $studentSubjectId, string $grade, int $instructorId): void
{
    $grade = trim($grade);
    $studentSubject = fetch_one('SELECT * FROM student_subjects WHERE id = :id', ['id' => $studentSubjectId]);
    if ($studentSubject === null) {
        return;
    }

    if (grade_deadline_passed()) {
        flash('error', 'The grade submission deadline has passed. Contact the registrar to submit grades.');
        return;
    }

    execute_sql(
        'UPDATE student_subjects SET final_grade = :grade, updated_at = NOW() WHERE id = :id',
        ['grade' => $grade, 'id' => $studentSubjectId]
    );

    $existing = fetch_one(
        'SELECT id FROM grades WHERE student_id = :student_id AND offering_id = :offering_id LIMIT 1',
        ['student_id' => (int) $studentSubject['student_id'], 'offering_id' => (int) $studentSubject['offering_id']]
    );

    if ($existing) {
        execute_sql(
            'UPDATE grades SET grade = :grade, instructor_id = :instructor_id, updated_at = NOW() WHERE id = :id',
            ['grade' => $grade, 'instructor_id' => $instructorId, 'id' => (int) $existing['id']]
        );
    } else {
        execute_sql(
            'INSERT INTO grades (student_id, curriculum_id, offering_id, grade, instructor_id, created_at, updated_at)
             VALUES (:student_id, :curriculum_id, :offering_id, :grade, :instructor_id, NOW(), NOW())',
            [
                'student_id' => (int) $studentSubject['student_id'],
                'curriculum_id' => (int) $studentSubject['curriculum_id'],
                'offering_id' => (int) $studentSubject['offering_id'],
                'grade' => $grade,
                'instructor_id' => $instructorId,
            ]
        );
    }
}

function request_workflow_label(string $status): string
{
    return match ($status) {
        'draft' => 'Draft',
        'submitted' => 'Submitted to Adviser',
        'adviser_approved' => 'Approved by Adviser',
        'chair_approved' => 'Approved by Department Chair',
        'registrar_forwarded' => 'Forwarded to Cashier',
        'cashier_approved' => 'Approved by Cashier',
        'registrar_approved' => 'Enrolled',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $status)),
    };
}

function sync_student_section(int $studentId, int $sectionId): void
{
    execute_sql('UPDATE students SET section_id = :section_id WHERE id = :student_id', [
        'section_id' => $sectionId,
        'student_id' => $studentId,
    ]);
}


// ── Email helpers ──────────────────────────────────────────────────────────
// Uses PHPMailer for SMTP delivery. Falls back to PHP mail() if SMTP is not
// configured.
function send_email(string $to, string $subject, string $htmlBody, ?string $plainBody = null): bool
{
    $smtpHost = setting('smtp_host', '');
    $smtpPort = (int) setting('smtp_port', '587');
    $smtpUser = setting('smtp_username', '');
    $smtpPass = setting('smtp_password', '');
    $fromEmail = setting('smtp_from_email', '');
    $fromName = setting('smtp_from_name', setting('system_name', 'E-EnrollSys'));

    if ($fromEmail === '') {
        return false;
    }

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        if ($smtpHost !== '') {
            $mail->isSMTP();
            $mail->Host = $smtpHost;
            $mail->Port = $smtpPort;
            $mail->SMTPAuth = $smtpUser !== '' && $smtpPass !== '';

            if ($mail->SMTPAuth) {
                $mail->Username = $smtpUser;
                $mail->Password = $smtpPass;
            }

            if ($smtpPort === 465) {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($smtpPort === 587) {
                $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            }
        }

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $htmlBody;

        if ($plainBody !== null) {
            $mail->AltBody = $plainBody;
        }

        $mail->send();
        return true;
    } catch (\Throwable) {
        return false;
    }
}

// ── Enrollment notification helper ─────────────────────────────────────────
// Sends a simple in-app flash-style record to a notifications table if it
// exists, and an email if SMTP is configured via settings.
function send_enrollment_notification(int $studentId, string $subject, string $body, string $type = 'info'): void
{
    create_notification('student', $studentId, $type, $subject, $body);

    $fromEmail = setting('smtp_from_email', '');
    if ($fromEmail === '' && setting('smtp_host', '') === '') {
        return;
    }
    $student = fetch_one('SELECT u.email, s.full_name FROM users u LEFT JOIN students s ON s.id = u.student_id WHERE u.student_id = :id LIMIT 1', ['id' => $studentId]);
    if ($student && !empty($student['email'])) {
        $portalName = setting('system_name', 'E-EnrollSys');
        $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;">
            <h2 style="color:#16a34a;">' . htmlspecialchars($subject) . '</h2>
            <p>' . nl2br(htmlspecialchars($body)) . '</p>
            <hr style="border:1px solid #e5e7eb;margin:20px 0;">
            <p style="color:#6b7280;font-size:12px;">This is an automated notification from ' . htmlspecialchars($portalName) . '.<br>Student: ' . htmlspecialchars($student['full_name'] ?? 'N/A') . '</p>
        </div>';
        send_email($student['email'], $subject, $htmlBody);
    }
}

function notify_staff_by_role(string $roleName, string $subject, string $body): void
{
    try {
        $staffRows = fetch_all(
            'SELECT s.staff_id FROM staff s
             INNER JOIN users u ON u.users_id = s.users_id
             INNER JOIN user_roles ur ON ur.user_id = u.users_id
             INNER JOIN roles r ON r.roles_id = ur.role_id
             WHERE r.role_name = :role AND s.status = "active"',
            ['role' => $roleName]
        );
        foreach ($staffRows as $sr) {
            execute_sql(
                'INSERT IGNORE INTO staff_notifications (staff_id, subject, body, is_read, created_at)
                 VALUES (:staff_id, :subject, :body, 0, NOW())',
                ['staff_id' => (int) $sr['staff_id'], 'subject' => $subject, 'body' => $body]
            );
            $staffUser = fetch_one('SELECT email FROM users WHERE staff_id = :sid LIMIT 1', ['sid' => (int) $sr['staff_id']]);
            if ($staffUser && !empty($staffUser['email'])) {
                $portalName = setting('system_name', 'E-EnrollSys');
                $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;">
                    <h2 style="color:#16a34a;">' . htmlspecialchars($subject) . '</h2>
                    <p>' . nl2br(htmlspecialchars($body)) . '</p>
                    <hr style="border:1px solid #e5e7eb;margin:20px 0;">
                    <p style="color:#6b7280;font-size:12px;">This is an automated notification from ' . htmlspecialchars($portalName) . '.</p>
                </div>';
                send_email($staffUser['email'], $subject, $htmlBody);
            }
        }
    } catch (\Throwable $e) {
    }
}

function log_audit(int $requestId, string $action, string $actorRole, ?string $oldStatus, ?string $newStatus, ?string $remark): void
{
    try {
        execute_sql(
            'INSERT INTO enrollment_audit_log (request_id, action, actor_id, actor_role, old_status, new_status, remark)
             VALUES (:request_id, :action, :actor_id, :actor_role, :old_status, :new_status, :remark)',
            [
                'request_id' => $requestId,
                'action' => $action,
                'actor_id' => (int) ($_SESSION['user_id'] ?? 0),
                'actor_role' => $actorRole,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'remark' => $remark,
            ]
        );
    } catch (\Throwable $e) {
    }
}

function approve_request_as_adviser(int $requestId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    execute_sql(
        'UPDATE enrollment_requests
         SET adviser_status = "approved", adviser_remark = :remark, adviser_processed_at = NOW(), adviser_processed_by = :user_id, workflow_status = "adviser_approved", updated_at = NOW()
         WHERE id = :id',
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, 'adviser_approve', 'adviser', 'submitted', 'adviser_approved', $remark);

    send_enrollment_notification((int)$req['student_id'],
        'Enrollment Approved by Adviser',
        'Your enrollment request has been approved by your adviser and forwarded to the Department Chair.' . ($remark ? "\n\nAdviser note: $remark" : '')
    );

    notify_staff_by_role('department_chair',
        'New Enrollment Request Awaiting Your Review',
        'A student enrollment request has been approved by the adviser and is now awaiting your review.'
    );
}

function approve_request_as_chair(int $requestId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    execute_sql(
        'UPDATE enrollment_requests
         SET chair_status = "approved", chair_remark = :remark, chair_processed_at = NOW(), chair_processed_by = :user_id, workflow_status = "chair_approved", updated_at = NOW()
         WHERE id = :id',
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, 'chair_approve', 'department_chair', 'adviser_approved', 'chair_approved', $remark);

    send_enrollment_notification((int)$req['student_id'],
        'Enrollment Approved by Department Chair',
        'Your enrollment request has been approved by the Department Chair and forwarded to the Registrar.' . ($remark ? "\n\nChair note: $remark" : '')
    );

    notify_staff_by_role('registrar',
        'Enrollment Request Ready for Finalization',
        'A student enrollment request has been approved by the department chair and is ready for registrar finalization.'
    );
}

function reject_request(int $requestId, string $stage, string $remark): void
{
    $remark = trim($remark) === '' ? 'No remark provided.' : trim($remark);
    $allowed = ['adviser', 'chair', 'registrar'];
    if (!in_array($stage, $allowed, true)) {
        $stage = 'registrar';
    }

    $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    $term = fetch_one(
        'SELECT ay.year_label, t.semester FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = :tid',
        ['tid' => (int) $req['term_id']]
    );
    $termLabel = $term ? ($term['year_label'] . ' ' . semester_label((string) $term['semester'])) : 'the current term';

    $colName = $stage . '_processed_at';
    $colBy = $stage . '_processed_by';

    execute_sql(
        "UPDATE enrollment_requests
         SET {$stage}_status = 'rejected', {$stage}_remark = :remark, {$colName} = NOW(), {$colBy} = :user_id, workflow_status = 'rejected', updated_at = NOW()
         WHERE id = :id",
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, $stage . '_reject', $stage, $req['workflow_status'], 'rejected', $remark);

    $body = "Dear Student,\n\n"
          . "Your enrollment request for {$termLabel} has been rejected at the " . ucfirst($stage) . " stage.\n\n"
          . "Reason: {$remark}\n\n"
          . "You may resubmit a new enrollment request with the necessary corrections.\n\n"
          . "If you have questions, please contact the " . ucfirst($stage) . " office.";

    send_enrollment_notification((int)$req['student_id'],
        'Enrollment Request Rejected',
        $body
    );
}

function cancel_request(int $requestId): void
{
    $req = fetch_one('SELECT workflow_status FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    execute_sql('UPDATE enrollment_requests SET workflow_status = "cancelled", updated_at = NOW() WHERE id = :id', ['id' => $requestId]);
    log_audit($requestId, 'student_cancel', 'student', $req ? $req['workflow_status'] : null, 'cancelled', null);
}

function forward_to_cashier(int $requestId): void
{
    $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'chair_approved') return;

    execute_sql(
        'UPDATE enrollment_requests SET workflow_status = "registrar_forwarded", updated_at = NOW() WHERE id = :id',
        ['id' => $requestId]
    );
    log_audit($requestId, 'registrar_forward', 'registrar', 'chair_approved', 'registrar_forwarded', null);

    send_enrollment_notification((int) $req['student_id'],
        'Enrollment Forwarded to Cashier',
        'Your enrollment request has been forwarded to the Cashier for fee assessment.'
    );
    notify_staff_by_role('cashier',
        'Enrollment Request Pending Fee Approval',
        'An enrollment request has been forwarded by the Registrar for fee processing.'
    );
}

function cashier_approve_request(int $requestId): void
{
    $req = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req || $req['workflow_status'] !== 'registrar_forwarded') return;

    execute_sql(
        'UPDATE enrollment_requests SET workflow_status = "cashier_approved", cashier_processed_at = NOW(), cashier_processed_by = :uid, updated_at = NOW() WHERE id = :id',
        ['id' => $requestId, 'uid' => (int) ($_SESSION['user_id'] ?? 0)]
    );
    log_audit($requestId, 'cashier_approve', 'cashier', 'registrar_forwarded', 'cashier_approved', null);

    send_enrollment_notification((int) $req['student_id'],
        'Enrollment Approved by Cashier',
        'Your enrollment has been approved by the Cashier. It is now ready for Registrar finalization.'
    );
    notify_staff_by_role('registrar',
        'Enrollment Ready for Finalization',
        'An enrollment request has been approved by the Cashier and is ready for registrar finalization.'
    );
}

function finalize_request_by_registrar(int $requestId, int $sectionId): bool
{
    $request = fetch_one('SELECT * FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if ($request === null) {
        return false;
    }

    if (!in_array($request['workflow_status'], ['cashier_approved', 'chair_approved', 'registrar_forwarded'], true)) {
        return false;
    }

    if (!section_has_slot($sectionId, (int) $request['term_id'])) {
        return false;
    }

    $items = enrollment_request_items($requestId);
    db()->beginTransaction();
    try {
        execute_sql(
            'UPDATE enrollment_requests
             SET registrar_status = "approved", workflow_status = "registrar_approved", registrar_section_id = :section_id, registrar_processed_at = NOW(), registrar_processed_by = :user_id, updated_at = NOW()
             WHERE id = :id',
            ['section_id' => $sectionId, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
        );

        $prevStatus = $request['workflow_status'];
        log_audit($requestId, 'registrar_finalize', 'registrar', $prevStatus, 'registrar_approved', null);

        sync_student_section((int) $request['student_id'], $sectionId);

        foreach ($items as $item) {
            $exists = fetch_one(
                'SELECT id FROM student_subjects WHERE student_id = :student_id AND term_id = :term_id AND offering_id = :offering_id LIMIT 1',
                [
                    'student_id' => (int) $request['student_id'],
                    'term_id' => (int) $request['term_id'],
                    'offering_id' => (int) $item['offering_id'],
                ]
            );

            if ($exists === null) {
                execute_sql(
                    'INSERT INTO student_subjects (
                        student_id, term_id, offering_id, curriculum_id, subject_id, section_id, units, enrollment_status, final_grade, created_at, updated_at
                     ) VALUES (
                        :student_id, :term_id, :offering_id, :curriculum_id, :subject_id, :section_id, :units, "enrolled", NULL, NOW(), NOW()
                     )',
                    [
                        'student_id' => (int) $request['student_id'],
                        'term_id' => (int) $request['term_id'],
                        'offering_id' => (int) $item['offering_id'],
                        'curriculum_id' => (int) $item['curriculum_id'],
                        'subject_id' => (int) $item['subject_id'],
                        'section_id' => $sectionId,
                        'units' => $item['units'],
                    ]
                );
            }
        }

        db()->commit();

        send_enrollment_notification((int) $request['student_id'],
            'Enrollment Approved — You Are Now Enrolled',
            'Congratulations! Your enrollment request has been approved by the Registrar. You are now officially enrolled for this term. You may download your Registration Form from the enrollment status page.'
        );

        notify_staff_by_role('cashier',
            'New Student Enrolled — Payment Processing Required',
            'A student has been finalized by the registrar and may require payment processing. Please check the cashier dashboard.'
        );

        return true;
    } catch (Throwable $throwable) {
        db()->rollBack();
        throw $throwable;
    }
}

function student_terms_with_enrollment(int $studentId): array
{
    return fetch_all(
        'SELECT DISTINCT t.id, t.semester, ay.year_label
         FROM student_subjects ss
         INNER JOIN academic_terms t ON t.id = ss.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE ss.student_id = :student_id
         ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid") DESC',
        ['student_id' => $studentId]
    );
}

function registration_form_data(int $studentId, int $termId): array
{
    $student = fetch_one(
        'SELECT s.*, p.program_code, p.program_name, sec.section_name, ay.year_label, t.semester
         FROM students s
         INNER JOIN programs p ON p.programs_id = s.program_id
         LEFT JOIN sections sec ON sec.id = s.section_id
         INNER JOIN academic_terms t ON t.id = :term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE s.id = :student_id',
        ['student_id' => $studentId, 'term_id' => $termId]
    ) ?? [];

    $rows = fetch_all(
        'SELECT ss.*, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units, sub.lab_credit,
                o.day_of_week, o.time_range, o.room,
                sec.section_name
         FROM student_subjects ss
         INNER JOIN section_subject_offerings o ON o.id = ss.offering_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         INNER JOIN sections sec ON sec.id = ss.section_id
         WHERE ss.student_id = :student_id AND ss.term_id = :term_id
         ORDER BY sub.subject_code',
        ['student_id' => $studentId, 'term_id' => $termId]
    );

    $totalUnits = 0.0;
    $totalLabCredits = 0.0;
    foreach ($rows as $row) {
        $totalUnits += (float) $row['units'];
        $totalLabCredits += (float) ($row['lab_credit'] ?? 0);
    }

    $financial = financial_profile($student ?: ['entry_year' => date('Y'), 'ra10931_override' => 'auto']);
    $tuition = in_array($financial['status'], ['extension_tuition', 'tuition'], true) ? ($totalUnits * $financial['tuition_per_unit']) : 0.0;
    $otherFees = (float) setting('other_school_fees', '2500');

    return [
        'student' => $student,
        'rows' => $rows,
        'total_units' => $totalUnits,
        'total_lab_credits' => $totalLabCredits,
        'tuition' => $tuition,
        'other_fees' => $otherFees,
        'total_amount' => $tuition + $otherFees,
        'financial' => $financial,
    ];
}

function cog_data(int $studentId, ?int $termId = null): array
{
    $student = fetch_one(
        'SELECT s.*, p.program_code, p.program_name FROM students s INNER JOIN programs p ON p.programs_id = s.program_id WHERE s.id = :id',
        ['id' => $studentId]
    ) ?? [];

    $params = ['student_id' => $studentId];
    $filter = '';
    if ($termId !== null) {
        $filter = ' AND ss.term_id = :term_id';
        $params['term_id'] = $termId;
    }

    $rows = fetch_all(
        'SELECT ss.final_grade, ss.units,
                sub.subject_code, sub.subject_description,
                t.semester, ay.year_label
         FROM student_subjects ss
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         INNER JOIN academic_terms t ON t.id = ss.term_id
         INNER JOIN academic_years ay ON ay.id = t.academic_year_id
         WHERE ss.student_id = :student_id AND ss.final_grade IS NOT NULL' . $filter . '
         ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid"), sub.subject_code',
        $params
    );

    $totalUnits = 0.0;
    $passingUnits = 0.0;
    $weightedSum = 0.0;
    foreach ($rows as $row) {
        $units = (float) $row['units'];
        $totalUnits += $units;
        if (grade_is_passing((string) $row['final_grade'])) {
            $passingUnits += $units;
        }
        $numeric = parse_numeric_grade((string) $row['final_grade']);
        if ($numeric !== null) {
            $weightedSum += $numeric * $units;
        }
    }

    $average = $totalUnits > 0 ? $weightedSum / $totalUnits : 0.0;

    return [
        'student' => $student,
        'rows' => $rows,
        'total_units' => $totalUnits,
        'credit_units' => $passingUnits,
        'average' => $average,
    ];
}

function checklist_data(int $studentId): array
{
    $student = fetch_one(
        'SELECT s.*, p.program_code, p.program_name FROM students s INNER JOIN programs p ON p.programs_id = s.program_id WHERE s.id = :id',
        ['id' => $studentId]
    ) ?? [];

    $gradeLookup = student_grade_lookup($studentId);
    $rows = fetch_all(
        'SELECT pc.curriculum_id, pc.year_level, pc.semester, pc.prerequisite_subject_id,
                sub.subject_id, sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS units
         FROM program_curriculum pc
         INNER JOIN subjects sub ON sub.subject_id = pc.subject_id
         WHERE pc.program_id = :program_id
         ORDER BY CAST(pc.year_level AS UNSIGNED), FIELD(pc.semester, "1st", "2nd", "mid"), sub.subject_code',
        ['program_id' => (int) ($student['program_id'] ?? 0)]
    );

    foreach ($rows as &$row) {
        $row['grade'] = $gradeLookup[(int) $row['subject_id']] ?? null;
        $row['status'] = grade_is_passing($row['grade']) ? 'Completed' : ($row['grade'] ? 'Incomplete / Failed' : 'Pending');
    }
    unset($row);

    return ['student' => $student, 'rows' => $rows];
}

function badge_class(string $type): string
{
    return match ($type) {
        'success' => 'badge success',
        'warning' => 'badge warning',
        'danger', 'error' => 'badge danger',
        default => 'badge',
    };
}

function workflow_badge_class(string $status): string
{
    return match ($status) {
        'registrar_approved' => 'success',
        'chair_approved', 'adviser_approved', 'cashier_approved' => 'warning',
        'registrar_forwarded' => 'info',
        'rejected', 'cancelled' => 'danger',
        'draft' => 'secondary',
        default => 'info',
    };
}

function can_user_cancel_request(array $request): bool
{
    // Only allow cancel while still pending adviser review.
    // Rejected requests should use 'create a new request' flow instead.
    return $request['workflow_status'] === 'submitted';
}

function user_department_scope_clause(?array $staff): array
{
    if ($staff === null || empty($staff['dept_id'])) {
        return ['sql' => '', 'params' => []];
    }

    return ['sql' => ' AND p.department_id = :department_id', 'params' => ['department_id' => (int) $staff['dept_id']]];
}

function upload_syllabus(array $file, int $offeringId): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }

    $maxSize = 5 * 1024 * 1024; // 5 MB
    if ((int) $file['size'] > $maxSize) {
        flash('error', 'Syllabus file must be under 5 MB.');
        return null;
    }

    $allowed = ['pdf', 'doc', 'docx'];
    $extension = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $allowed, true)) {
        flash('error', 'Only PDF, DOC, and DOCX files are allowed.');
        return null;
    }

    $mimeType = mime_content_type((string) $file['tmp_name']);
    $allowedMimes = [
        'pdf'  => 'application/pdf',
        'doc'  => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];
    if (isset($allowedMimes[$extension]) && $mimeType !== $allowedMimes[$extension]) {
        flash('error', 'File content does not match the expected type.');
        return null;
    }

    $directory = APP_ROOT . '/uploads/syllabus';
    if (!is_dir($directory)) {
        mkdir($directory, 0777, true);
    }

    $targetName = 'syllabus_' . $offeringId . '_' . time() . '.' . $extension;
    $targetPath = $directory . '/' . $targetName;
    if (!move_uploaded_file((string) $file['tmp_name'], $targetPath)) {
        return null;
    }

    $relativePath = 'uploads/syllabus/' . $targetName;
    execute_sql('UPDATE section_subject_offerings SET syllabus_path = :path WHERE id = :id', ['path' => $relativePath, 'id' => $offeringId]);
    return $relativePath;
}

// ── Add/Drop Request functions ──────────────────────────────────────────────

function create_add_drop_request(int $studentId, int $termId, string $actionType, ?int $offeringId, ?int $subjectId, ?int $sectionId, ?int $curriculumId, float $units): int
{
    execute_sql(
        'INSERT INTO add_drop_requests (
            student_id, term_id, action_type, offering_id, subject_id, section_id, curriculum_id, units,
            workflow_status, adviser_status, chair_status, registrar_status
        ) VALUES (
            :student_id, :term_id, :action_type, :offering_id, :subject_id, :section_id, :curriculum_id, :units,
            "submitted", "pending", "pending", "pending"
        )',
        [
            'student_id' => $studentId,
            'term_id' => $termId,
            'action_type' => $actionType === 'drop' ? 'drop' : 'add',
            'offering_id' => $offeringId,
            'subject_id' => $subjectId,
            'section_id' => $sectionId,
            'curriculum_id' => $curriculumId,
            'units' => $units,
        ]
    );

    $requestId = (int) db()->lastInsertId();
    log_audit($requestId, 'add_drop_submit', 'student', null, 'submitted', $actionType);

    $term = fetch_one(
        'SELECT ay.year_label, t.semester FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = :tid',
        ['tid' => $termId]
    );
    $termLabel = $term ? ($term['year_label'] . ' ' . semester_label((string) $term['semester'])) : 'the current term';
    $actionLabel = $actionType === 'drop' ? 'drop' : 'add';

    send_enrollment_notification($studentId,
        ucfirst($actionLabel) . ' Request Submitted',
        'Your ' . $actionLabel . ' request for ' . $termLabel . ' has been submitted and is pending adviser review.'
    );

    return $requestId;
}

function add_drop_request_items(int $studentId, int $termId, string $workflowStatus = ''): array
{
    $sql = 'SELECT adr.*,
                    sub.subject_code, sub.subject_description, (sub.lec_credit + sub.lab_credit) AS subject_units,
                    sec.section_name, sec.year_level, o.day_of_week, o.time_range,
                    CONCAT(COALESCE(st.full_name, "TBA")) AS instructor_name
             FROM add_drop_requests adr
             LEFT JOIN subjects sub ON sub.subject_id = adr.subject_id
             LEFT JOIN section_subject_offerings o ON o.id = adr.offering_id
             LEFT JOIN sections sec ON sec.id = adr.section_id
             LEFT JOIN staff st ON st.staff_id = o.instructor_id
             WHERE adr.student_id = :sid AND adr.term_id = :tid';
    $params = ['sid' => $studentId, 'tid' => $termId];
    if ($workflowStatus !== '') {
        $sql .= ' AND adr.workflow_status = :ws';
        $params['ws'] = $workflowStatus;
    }
    $sql .= ' ORDER BY adr.created_at DESC';
    return fetch_all($sql, $params);
}

function approve_add_drop_as_adviser(int $requestId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM add_drop_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    execute_sql(
        'UPDATE add_drop_requests
         SET adviser_status = "approved", adviser_remark = :remark, adviser_processed_at = NOW(), adviser_processed_by = :user_id, workflow_status = "adviser_approved", updated_at = NOW()
         WHERE id = :id',
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, 'add_drop_adviser_approve', 'adviser', 'submitted', 'adviser_approved', $remark);
    send_enrollment_notification((int)$req['student_id'],
        ucfirst($req['action_type']) . ' Request Approved by Adviser',
        'Your ' . $req['action_type'] . ' request has been approved by your adviser and forwarded to the Department Chair.' . ($remark ? "\n\nAdviser note: $remark" : '')
    );
    notify_staff_by_role('department_chair',
        'Add/Drop Request Awaiting Your Review',
        'A student add/drop request has been approved by the adviser and is awaiting your review.'
    );
}

function approve_add_drop_as_chair(int $requestId, string $remark = ''): void
{
    $req = fetch_one('SELECT * FROM add_drop_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    execute_sql(
        'UPDATE add_drop_requests
         SET chair_status = "approved", chair_remark = :remark, chair_processed_at = NOW(), chair_processed_by = :user_id, workflow_status = "chair_approved", updated_at = NOW()
         WHERE id = :id',
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, 'add_drop_chair_approve', 'department_chair', 'adviser_approved', 'chair_approved', $remark);
    send_enrollment_notification((int)$req['student_id'],
        ucfirst($req['action_type']) . ' Request Approved by Chair',
        'Your ' . $req['action_type'] . ' request has been approved by the Department Chair and forwarded to the Registrar.' . ($remark ? "\n\nChair note: $remark" : '')
    );
    notify_staff_by_role('registrar',
        'Add/Drop Request Ready for Finalization',
        'A student add/drop request has been approved by the chair and is ready for registrar finalization.'
    );
}

function finalize_add_drop_as_registrar(int $requestId, ?int $sectionId = null): bool
{
    $req = fetch_one('SELECT * FROM add_drop_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return false;

    db()->beginTransaction();
    try {
        $finalSectionId = $sectionId ?? (int) ($req['section_id'] ?? 0);

        execute_sql(
            'UPDATE add_drop_requests
             SET registrar_status = "approved", registrar_remark = "Finalized", registrar_processed_at = NOW(), registrar_processed_by = :user_id, workflow_status = "registrar_approved", updated_at = NOW()
             WHERE id = :id',
            ['id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
        );

        log_audit($requestId, 'add_drop_registrar_finalize', 'registrar', 'chair_approved', 'registrar_approved', null);

        if ($req['action_type'] === 'add' && $req['offering_id'] > 0) {
            $exists = fetch_one(
                'SELECT id FROM student_subjects WHERE student_id = :sid AND term_id = :tid AND offering_id = :oid LIMIT 1',
                ['sid' => (int) $req['student_id'], 'tid' => (int) $req['term_id'], 'oid' => (int) $req['offering_id']]
            );
            if ($exists === null) {
                execute_sql(
                    'INSERT INTO student_subjects (student_id, term_id, offering_id, curriculum_id, subject_id, section_id, units, enrollment_status, final_grade, created_at, updated_at)
                     VALUES (:sid, :tid, :oid, :cid, :subid, :secid, :units, "enrolled", NULL, NOW(), NOW())',
                    [
                        'sid' => (int) $req['student_id'], 'tid' => (int) $req['term_id'], 'oid' => (int) $req['offering_id'],
                        'cid' => (int) ($req['curriculum_id'] ?? 0), 'subid' => (int) $req['subject_id'],
                        'secid' => $finalSectionId, 'units' => $req['units'],
                    ]
                );
            }
        } elseif ($req['action_type'] === 'drop' && $req['subject_id'] > 0) {
            execute_sql(
                'UPDATE student_subjects SET enrollment_status = "dropped", updated_at = NOW()
                 WHERE student_id = :sid AND term_id = :tid AND subject_id = :subid AND enrollment_status = "enrolled"',
                ['sid' => (int) $req['student_id'], 'tid' => (int) $req['term_id'], 'subid' => (int) $req['subject_id']]
            );
        }

        db()->commit();

        send_enrollment_notification((int) $req['student_id'],
            ucfirst($req['action_type']) . ' Request Approved — Finalized',
            'Your ' . $req['action_type'] . ' request has been finalized by the Registrar.'
        );
        return true;
    } catch (Throwable $throwable) {
        db()->rollBack();
        throw $throwable;
    }
}

function reject_add_drop_request(int $requestId, string $stage, string $remark): void
{
    $remark = trim($remark) === '' ? 'No remark provided.' : trim($remark);
    $allowed = ['adviser', 'chair', 'registrar'];
    if (!in_array($stage, $allowed, true)) {
        $stage = 'registrar';
    }

    $req = fetch_one('SELECT * FROM add_drop_requests WHERE id = :id', ['id' => $requestId]);
    if ($req === null) return;

    $colName = $stage . '_processed_at';
    $colBy = $stage . '_processed_by';

    execute_sql(
        "UPDATE add_drop_requests
         SET {$stage}_status = 'rejected', {$stage}_remark = :remark, {$colName} = NOW(), {$colBy} = :user_id, workflow_status = 'rejected', updated_at = NOW()
         WHERE id = :id",
        ['remark' => $remark, 'id' => $requestId, 'user_id' => (int) ($_SESSION['user_id'] ?? 0)]
    );

    log_audit($requestId, 'add_drop_' . $stage . '_reject', $stage, $req['workflow_status'], 'rejected', $remark);

    send_enrollment_notification((int)$req['student_id'],
        ucfirst($req['action_type']) . ' Request Rejected',
        'Your ' . $req['action_type'] . ' request was rejected at the ' . ucfirst($stage) . ' stage.' . ($remark ? "\n\nReason: $remark" : '')
    );
}

function cancel_add_drop_request(int $requestId): void
{
    execute_sql('UPDATE add_drop_requests SET workflow_status = "cancelled", updated_at = NOW() WHERE id = :id', ['id' => $requestId]);
}

// ── CSRF helpers ────────────────────────────────────────────────────────────
function csrf_token(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = trim($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals(csrf_token(), $token)) {
        http_response_code(403);
        exit('Invalid CSRF token. Please go back and try again.');
    }
}
