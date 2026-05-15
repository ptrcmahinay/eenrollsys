<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

if (!is_post()) {
    redirect('admin/users.php');
}

$displayName = trim($_POST['display_name'] ?? '');
$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$roleId = (int) ($_POST['role_id'] ?? 0);

if ($displayName === '' || $username === '' || $email === '' || $password === '' || $roleId <= 0) {
    flash('error', 'Please fill in all required user fields.');
    redirect('admin/users.php');
}

$existing = fetch_one('SELECT users_id FROM users WHERE username = :username OR email = :email LIMIT 1', [
    'username' => $username,
    'email' => $email,
]);
if ($existing !== null) {
    flash('error', 'Username or email already exists.');
    redirect('admin/users.php');
}

execute_sql(
    'INSERT INTO users (username, email, password, created_at) VALUES (:username, :email, :password, NOW())',
    ['username' => $username, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT)]
);
$userId = (int) db()->lastInsertId();
execute_sql('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $roleId]);

$role = fetch_one('SELECT role_name FROM roles WHERE roles_id = :id', ['id' => $roleId]);
if ($role !== null && $role['role_name'] !== 'student') {
    execute_sql(
        'INSERT INTO staff (users_id, employee_number, full_name, email, dept_id, created_at) VALUES (:user_id, :employee_number, :full_name, :email, NULL, NOW())',
        [
            'user_id' => $userId,
            'employee_number' => 'EMP-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT),
            'full_name' => $displayName,
            'email' => $email,
        ]
    );
}

flash('success', 'User account created.');
redirect('admin/users.php');
