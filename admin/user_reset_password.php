<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

if (!is_post()) {
    redirect('admin/users.php');
}

$userId = (int) ($_POST['user_id'] ?? 0);
$newPassword = trim($_POST['new_password'] ?? '');
if ($userId <= 0 || $newPassword === '') {
    flash('error', 'Provide a password and target user.');
    redirect('admin/users.php');
}

execute_sql('UPDATE users SET password = :password WHERE users_id = :id', [
    'password' => password_hash($newPassword, PASSWORD_DEFAULT),
    'id' => $userId,
]);

flash('success', 'Password reset completed.');
redirect('admin/users.php');
