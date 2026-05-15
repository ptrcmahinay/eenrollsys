<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (!is_post()) {
    redirect('auth/login.php');
}

$email = trim((string) ($_POST['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    set_flash('error', 'Enter a valid email address.');
    redirect('auth/forgot_password.php');
}

$user = fetch_one('SELECT users_id, display_name, email FROM users WHERE email = :email LIMIT 1', ['email' => $email]);
if ($user === null) {
    set_flash('info', 'If that email is registered, you will receive a reset link shortly.');
    redirect('auth/forgot_password.php');
}

$token = bin2hex(random_bytes(32));
$expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour'));

execute_sql(
    'INSERT INTO password_reset_tokens (user_id, token, expires_at) VALUES (:uid, :token, :exp)',
    ['uid' => (int) $user['users_id'], 'token' => $token, 'exp' => $expiresAt]
);

$portalName = setting('system_name', 'E-EnrollSys');
$baseUrl = rtrim(app_url(''), '/');
$resetUrl = $baseUrl . '/auth/reset_password.php?token=' . $token;

$htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;">
    <h2 style="color:#16a34a;">Password Reset Request</h2>
    <p>Hello ' . htmlspecialchars($user['display_name'] ?? 'User') . ',</p>
    <p>You requested a password reset for your ' . htmlspecialchars($portalName) . ' account.</p>
    <p style="margin:24px 0;">
        <a href="' . htmlspecialchars($resetUrl) . '"
           style="display:inline-block;padding:12px 28px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
            Reset My Password
        </a>
    </p>
    <p style="font-size:13px;color:#6b7280;">Or copy this link into your browser:</p>
    <p style="font-size:12px;word-break:break-all;background:#f3f4f6;padding:8px 12px;border-radius:6px;">' . htmlspecialchars($resetUrl) . '</p>
    <hr style="border:1px solid #e5e7eb;margin:20px 0;">
    <p style="color:#9ca3af;font-size:12px;">This link expires in 1 hour. If you did not request a reset, ignore this email.</p>
</div>';

send_email($user['email'], '[' . $portalName . '] Password Reset Request', $htmlBody);

set_flash('success', 'If that email is registered, a reset link has been sent. It expires in 1 hour.');
redirect('auth/forgot_password.php');
