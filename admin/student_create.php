<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

if (!is_post()) {
    redirect('admin/students.php');
}

$data = [
    'student_number'    => trim($_POST['student_number'] ?? ''),
    'first_name'        => trim($_POST['first_name'] ?? ''),
    'middle_name'       => trim($_POST['middle_name'] ?? ''),
    'last_name'         => trim($_POST['last_name'] ?? ''),
    'sex'               => trim($_POST['sex'] ?? ''),
    'birth_date'        => trim($_POST['birth_date'] ?? '') ?: null,
    'contact_number'    => trim($_POST['contact_number'] ?? ''),
    'email_address'     => trim($_POST['email_address'] ?? ''),
    'address'           => trim($_POST['address'] ?? ''),
    'barangay'          => trim($_POST['barangay'] ?? ''),
    'municipality'      => trim($_POST['municipality'] ?? ''),
    'province'          => trim($_POST['province'] ?? ''),
    'civil_status'      => trim($_POST['civil_status'] ?? ''),
    'program_id'        => (int) ($_POST['program_id'] ?? 0),
    'year_level'        => (int) ($_POST['year_level'] ?? 1),
    'entry_year'        => (int) ($_POST['entry_year'] ?? date('Y')),
    'classification'    => trim($_POST['classification'] ?? ''),
    'status'            => trim($_POST['status'] ?? 'Regular'),
    'ra10931_override'  => trim($_POST['ra10931_override'] ?? 'auto'),
];

if ($data['student_number'] === '' || $data['first_name'] === '' || $data['last_name'] === '' || $data['address'] === '' || $data['program_id'] <= 0) {
    flash('error', 'Please fill out all required student fields.');
    redirect('admin/students.php');
}

$existing = fetch_one('SELECT id FROM students WHERE student_number = :student_number LIMIT 1', ['student_number' => $data['student_number']]);
if ($existing !== null) {
    flash('error', 'Student number already exists.');
    redirect('admin/students.php');
}

execute_sql(
    'INSERT INTO students (student_number, first_name, middle_name, last_name, sex, birth_date,
        contact_number, email_address, address, barangay, municipality, province, civil_status,
        program_id, year_level, entry_year, classification, status, ra10931_override, academic_status, record_status, created_at)
     VALUES (:student_number, :first_name, :middle_name, :last_name, :sex, :birth_date,
        :contact_number, :email_address, :address, :barangay, :municipality, :province, :civil_status,
        :program_id, :year_level, :entry_year, :classification, :status, :ra10931_override, "active", "active", NOW())',
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
            'username'   => $username,
            'email'      => $email,
            'password'   => password_hash($password, PASSWORD_DEFAULT),
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

flash('success', 'Student profile created successfully. Student #' . $data['student_number']);
redirect('admin/students.php');
