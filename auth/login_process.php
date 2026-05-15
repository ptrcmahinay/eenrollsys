<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (!is_post()) {
    redirect('auth/login.php');
}

$identity = trim($_POST['identity'] ?? '');
$password = (string) ($_POST['password'] ?? '');

if ($identity === '' || $password === '') {
    flash('error', 'Enter your username/email and password.');
    redirect('auth/login.php');
}

$user = fetch_one(
    'SELECT users_id, username, email, password FROM users WHERE username = :username OR email = :email LIMIT 1',
    ['username' => $identity, 'email' => $identity]
);

if ($user === null || !password_verify($password, (string) $user['password'])) {
    flash('error', 'Invalid login credentials.');
    redirect('auth/login.php');
}

login_user($user);
flash('success', 'Welcome back, ' . ($_SESSION['display_name'] ?? 'User') . '.');
redirect('auth/redirect.php');