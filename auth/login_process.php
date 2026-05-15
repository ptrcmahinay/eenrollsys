<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (!is_post()) {
    redirect('auth/login.php');
}

// ── Brute-force protection (session-based) ──────────────────────────────
$maxAttempts = 5;
$lockoutMinutes = 15;

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lockout_until'] = null;
}

if ($_SESSION['login_lockout_until'] !== null) {
    $remaining = $_SESSION['login_lockout_until'] - time();
    if ($remaining > 0) {
        flash('error', 'Too many failed login attempts. Please try again in ' . ceil($remaining / 60) . ' minute(s).');
        redirect('auth/login.php');
    }
    $_SESSION['login_attempts'] = 0;
    $_SESSION['login_lockout_until'] = null;
}

// ── Credential check ─────────────────────────────────────────────────────
$identity = trim($_POST['identity'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($identity === '' || $password === '') {
    flash('error', 'Enter your username/email and password.');
    redirect('auth/login.php');
}

$user = fetch_one(
    'SELECT users_id, username, email, password, verification_token, verified_at FROM users WHERE username = :username OR email = :email LIMIT 1',
    ['username' => $identity, 'email' => $identity]
);

if ($user === null || !password_verify($password, (string) $user['password'])) {
    $_SESSION['login_attempts']++;
    $remainingAttempts = $maxAttempts - $_SESSION['login_attempts'];

    if ($_SESSION['login_attempts'] >= $maxAttempts) {
        $_SESSION['login_lockout_until'] = time() + ($lockoutMinutes * 60);
        flash('error', 'Too many failed login attempts. Account locked for ' . $lockoutMinutes . ' minutes.');
    } else {
        flash('error', 'Invalid login credentials. ' . $remainingAttempts . ' attempt(s) remaining.');
    }
    redirect('auth/login.php');
}

// ── Email verification check ─────────────────────────────────────────────
// Legacy users (no token, no verified_at) are auto-verified.
// New users must verify before first login.
if ($user['verified_at'] === null && $user['verification_token'] !== null) {
    flash('error', 'Please verify your email address first. Check your inbox for the verification link.');
    redirect('auth/login.php');
}

// Auto-verify legacy accounts on first login after this change
if ($user['verified_at'] === null && $user['verification_token'] === null) {
    execute_sql('UPDATE users SET verified_at = NOW() WHERE users_id = :id', ['id' => (int) $user['users_id']]);
}

// ── Successful login ─────────────────────────────────────────────────────
unset($_SESSION['login_attempts'], $_SESSION['login_lockout_until']);
login_user($user);
flash('success', 'Welcome back, ' . ($_SESSION['display_name'] ?? 'User') . '.');
redirect('auth/redirect.php');
