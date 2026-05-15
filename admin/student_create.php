<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

if (!is_post()) {
    redirect('admin/students.php');
}

$data = [
    'student_number' => trim($_POST['student_number'] ?? ''),
    'full_name' => trim($_POST['full_name'] ?? ''),
    'address' => trim($_POST['address'] ?? ''),
    'program_id' => (int) ($_POST['program_id'] ?? 0),
    'year_level' => (int) ($_POST['year_level'] ?? 1),
    'section_id' => ($_POST['section_id'] ?? '') !== '' ? (int) $_POST['section_id'] : null,
    'entry_year' => (int) ($_POST['entry_year'] ?? date('Y')),
    'ra10931_override' => trim($_POST['ra10931_override'] ?? 'auto'),
];

if ($data['student_number'] === '' || $data['full_name'] === '' || $data['address'] === '' || $data['program_id'] <= 0) {
    flash('error', 'Please fill out all required student fields.');
    redirect('admin/students.php');
}

$existing = fetch_one('SELECT id FROM students WHERE student_number = :student_number LIMIT 1', ['student_number' => $data['student_number']]);
if ($existing !== null) {
    flash('error', 'Student number already exists.');
    redirect('admin/students.php');
}

execute_sql(
    'INSERT INTO students (student_number, full_name, address, program_id, year_level, section_id, entry_year, ra10931_override, status, created_at)
     VALUES (:student_number, :full_name, :address, :program_id, :year_level, :section_id, :entry_year, :ra10931_override, "active", NOW())',
    $data
);
$studentId = (int) db()->lastInsertId();

$username = trim($_POST['username'] ?? '');
$email = trim($_POST['email'] ?? '');
$password = (string) ($_POST['password'] ?? '');
if ($username !== '' && $email !== '' && $password !== '') {
    execute_sql(
        'INSERT INTO users (username, email, password, student_id, created_at) VALUES (:username, :email, :password, :student_id, NOW())',
        [
            'username' => $username,
            'email' => $email,
            'password' => password_hash($password, PASSWORD_DEFAULT),
            'student_id' => $studentId,
        ]
    );
    $userId = (int) db()->lastInsertId();
    $studentRole = fetch_one('SELECT roles_id FROM roles WHERE role_name = "student" LIMIT 1');
    execute_sql('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', [
        'user_id' => $userId,
        'role_id' => (int) ($studentRole['roles_id'] ?? 1),
    ]);
}

flash('success', 'Student profile created successfully.');
redirect('admin/students.php');
