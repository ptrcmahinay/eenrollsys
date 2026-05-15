<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

if (is_post()) {
    $user = current_user();
    if ($user === null) {
        http_response_code(401);
        exit('Unauthorized');
    }
    $notifId = (int) ($_POST['notif_id'] ?? 0);
    if ($notifId > 0) {
        $role = $user['role'] ?? '';
        if ($role === 'student' && !empty($user['student_id'])) {
            dismiss_notification('student', $notifId);
        } elseif (!empty($user['staff_id'])) {
            dismiss_notification($role, $notifId);
        }
    }
    http_response_code(200);
}

