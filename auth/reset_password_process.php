<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (!is_post()) {
    redirect('auth/login.php');
}

$token = trim((string) ($_POST['token'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$passwordConfirm = (string) ($_POST['password_confirm'] ?? '');

if ($token === '') {
    set_flash('error', 'Missing reset token.');
    redirect('auth/forgot_password.php');
}

if ($password === '' || strlen($password) < 6) {
    set_flash('error', 'Password must be at least 6 characters.');
    redirect('auth/reset_password.php?token=' . urlencode($token));
}

if ($password !== $passwordConfirm) {
    set_flash('error', 'Passwords do not match.');
    redirect('auth/reset_password.php?token=' . urlencode($token));
}

$resetRow = fetch_one(
    'SELECT t.user_id, t.expires_at
     FROM password_reset_tokens t
     WHERE t.token = :token AND t.used = 0',
    ['token' => $token]
);

if ($resetRow === null) {
    set_flash('error', 'Invalid or already-used reset link.');
    redirect('auth/forgot_password.php');
}

if (strtotime($resetRow['expires_at']) < time()) {
    set_flash('error', 'This reset link has expired. Please request a new one.');
    redirect('auth/forgot_password.php');
}

$hashed = password_hash($password, PASSWORD_DEFAULT);
execute_sql('UPDATE users SET password = :pw WHERE users_id = :id', ['pw' => $hashed, 'id' => (int) $resetRow['user_id']]);
execute_sql('UPDATE password_reset_tokens SET used = 1, used_at = NOW() WHERE token = :token', ['token' => $token]);

set_flash('success', 'Password updated. You can now log in with your new password.');
redirect('auth/login.php');
