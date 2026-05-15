<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_login();

if (!is_post()) {
    http_response_code(405);
    exit('Method not allowed');
}

$user = current_user();
$role = $user['role'] ?? '';
$verify = json_decode((string) ($_POST['verify'] ?? '[]'), true);
if (!is_array($verify)) $verify = [];
foreach ($verify as $k => $v) {
    $_POST[$k] = $v;
}
unset($_POST['verify']);
$action = trim($_POST['action'] ?? '');
$tab = trim($_POST['tab'] ?? '');

if ($action === 'update_profile') {
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $currentPassword = (string) ($_POST['current_password'] ?? '');

    $updates = [];
    if ($displayName !== '') $updates['display_name'] = $displayName;
    if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) $updates['email'] = $email;
    if ($password !== '') {
        if ($currentPassword === '') {
            echo json_encode(['success' => false, 'message' => 'Enter current password to change it.']);
            exit;
        }
        $cur = fetch_one('SELECT password FROM users WHERE users_id = :id', ['id' => (int) $user['users_id']]);
        if ($cur === null || !password_verify($currentPassword, $cur['password'])) {
            echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
            exit;
        }
        $updates['password'] = password_hash($password, PASSWORD_DEFAULT);
    }

    if ($_FILES['profile_pic']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $maxSize = 2 * 1024 * 1024; // 2 MB
        if ((int) $_FILES['profile_pic']['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'Profile picture must be under 2 MB.']);
            exit;
        }

        $ext = pathinfo($_FILES['profile_pic']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array(strtolower($ext), $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, GIF, and WebP images are allowed.']);
            exit;
        }

        $mimeType = mime_content_type($_FILES['profile_pic']['tmp_name']);
        $allowedMimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mimeType, $allowedMimes, true)) {
            echo json_encode(['success' => false, 'message' => 'File is not a valid image.']);
            exit;
        }

        $filename = 'profile_' . $user['users_id'] . '_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], $uploadDir . $filename)) {
            if (!empty($user['profile_pic']) && file_exists($uploadDir . $user['profile_pic'])) {
                @unlink($uploadDir . $user['profile_pic']);
            }
            execute_sql('UPDATE users SET profile_pic = :pic WHERE users_id = :id', ['pic' => $filename, 'id' => (int) $user['users_id']]);
            $_SESSION['user']['profile_pic'] = $filename;
        }
    }

    if ($updates !== []) {
        $setParts = [];
        $params = [];
        foreach ($updates as $k => $v) {
            $setParts[] = "$k = :$k";
            $params[$k] = $v;
        }
        $params['id'] = (int) $user['users_id'];
        execute_sql('UPDATE users SET ' . implode(', ', $setParts) . ' WHERE users_id = :id', $params);
        $_SESSION['user'] = array_merge($_SESSION['user'] ?? [], array_intersect_key($updates, array_flip(['display_name', 'email'])));
    }

    echo json_encode(['success' => true, 'message' => 'Profile updated.']);
    exit;
}

if ($action === 'update_enrollment' && in_array($role, ['admin', 'registrar'], true)) {
    $keys = ['tuition_per_unit', 'other_school_fees', 'allow_online_enrollment', 'irregular_unit_cap'];
    foreach ($keys as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if ($value !== '') set_setting($key, $value);
    }
    echo json_encode(['success' => true, 'message' => 'Enrollment settings updated.']);
    exit;
}

if ($action === 'update_academic' && in_array($role, ['admin', 'registrar'], true)) {
    $keys = ['cog_purposes', 'max_section_slots'];
    foreach ($keys as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if ($value !== '') set_setting($key, $value);
    }
    echo json_encode(['success' => true, 'message' => 'Academic settings updated.']);
    exit;
}

if ($action === 'update_institution' && $role === 'admin') {
    $keys = ['system_name', 'campus_name', 'campus_address', 'registrar_name', 'registrar_title', 'cog_purpose', 'institution_name'];
    foreach ($keys as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        if ($value !== '') set_setting($key, $value);
    }

    if (isset($_POST['remove_signature']) && $_POST['remove_signature'] === '1') {
        $current = setting('registrar_signature', '');
        if ($current !== '') {
            @unlink(__DIR__ . '/../uploads/' . $current);
            set_setting('registrar_signature', '');
        }
    }

    if (isset($_FILES['registrar_signature']) && $_FILES['registrar_signature']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/../uploads/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $maxSize = 2 * 1024 * 1024;
        if ((int) $_FILES['registrar_signature']['size'] > $maxSize) {
            echo json_encode(['success' => false, 'message' => 'Signature image must be under 2 MB.']);
            exit;
        }

        $ext = pathinfo($_FILES['registrar_signature']['name'], PATHINFO_EXTENSION);
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        if (!in_array(strtolower($ext), $allowed, true)) {
            echo json_encode(['success' => false, 'message' => 'Only PNG, JPG, and WebP images are allowed.']);
            exit;
        }

        $mimeType = mime_content_type($_FILES['registrar_signature']['tmp_name']);
        if (!in_array($mimeType, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            echo json_encode(['success' => false, 'message' => 'File is not a valid image.']);
            exit;
        }

        $oldSig = setting('registrar_signature', '');
        $filename = 'registrar_sig_' . time() . '.' . $ext;
        if (move_uploaded_file($_FILES['registrar_signature']['tmp_name'], $uploadDir . $filename)) {
            if ($oldSig !== '' && file_exists($uploadDir . $oldSig)) {
                @unlink($uploadDir . $oldSig);
            }
            set_setting('registrar_signature', $filename);
        }
    }

    echo json_encode(['success' => true, 'message' => 'Institution settings updated.']);
    exit;
}

if ($action === 'update_smtp' && $role === 'admin') {
    $keys = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password', 'smtp_from_email', 'smtp_from_name'];
    foreach ($keys as $key) {
        $value = trim((string) ($_POST[$key] ?? ''));
        set_setting($key, $value);
    }
    echo json_encode(['success' => true, 'message' => 'SMTP settings updated.']);
    exit;
}

if ($action === 'update_notifications') {
    $prefKeys = ['notif_enrollment', 'notif_grade', 'notif_system'];
    $prefix = 'pref_' . ($user['student_id'] ?? $user['staff_id'] ?? $user['users_id']);
    foreach ($prefKeys as $key) {
        $val = isset($_POST[$key]) ? '1' : '0';
        set_setting($prefix . '_' . $key, $val);
    }
    echo json_encode(['success' => true, 'message' => 'Notification preferences updated.']);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Invalid action.']);
