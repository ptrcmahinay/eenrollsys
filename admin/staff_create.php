<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

if (!is_post()) {
    redirect('admin/staff.php');
}

$fullName = trim($_POST['full_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$username = trim($_POST['username'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$employeeNumber = trim($_POST['employee_number'] ?? '');
$deptId = ($_POST['dept_id'] ?? '') !== '' ? (int) $_POST['dept_id'] : null;
$roleId = (int) ($_POST['role_id'] ?? 0);

if ($fullName === '' || $email === '' || $username === '' || $password === '' || $roleId <= 0) {
    flash('error', 'Please fill out all required staff fields.');
    redirect('admin/staff.php');
}

$existing = fetch_one('SELECT users_id FROM users WHERE username = :username OR email = :email LIMIT 1', [
    'username' => $username,
    'email' => $email,
]);
if ($existing !== null) {
    flash('error', 'Username or email already exists.');
    redirect('admin/staff.php');
}

$chairRole = fetch_one("SELECT roles_id FROM roles WHERE role_name = 'department_chair' LIMIT 1");
$chairRoleId = $chairRole ? (int) $chairRole['roles_id'] : 0;

if ($roleId === $chairRoleId && $deptId !== null) {
    $existingChair = fetch_one(
        'SELECT s.staff_id
         FROM staff s
         INNER JOIN user_roles ur ON ur.user_id = s.users_id AND ur.role_id = :role_id
         WHERE s.dept_id = :dept_id AND COALESCE(s.status, \'active\') = \'active\'
         LIMIT 1',
        ['role_id' => $chairRoleId, 'dept_id' => $deptId]
    );
    if ($existingChair !== null) {
        flash('error', 'This department already has a department chair assigned.');
        redirect('admin/staff.php');
    }
}

execute_sql('INSERT INTO users (username, email, password, created_at) VALUES (:username, :email, :password, NOW())', [
    'username' => $username,
    'email' => $email,
    'password' => password_hash($password, PASSWORD_DEFAULT),
]);
$userId = (int) db()->lastInsertId();
execute_sql('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', ['user_id' => $userId, 'role_id' => $roleId]);
execute_sql(
    'INSERT INTO staff (users_id, employee_number, full_name, email, dept_id, created_at)
     VALUES (:users_id, :employee_number, :full_name, :email, :dept_id, NOW())',
    [
        'users_id' => $userId,
        'employee_number' => $employeeNumber !== '' ? $employeeNumber : 'EMP-' . str_pad((string) $userId, 4, '0', STR_PAD_LEFT),
        'full_name' => $fullName,
        'email' => $email,
        'dept_id' => $deptId,
    ]
);
$staffId = (int) db()->lastInsertId();

if ($roleId === $chairRoleId && $deptId !== null) {
    execute_sql(
        'UPDATE departments SET chair_id = :chair_id WHERE dept_id = :dept_id',
        ['chair_id' => $staffId, 'dept_id' => $deptId]
    );
}

flash('success', 'Staff account created.');
redirect('admin/staff.php');
