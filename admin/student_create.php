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
    'place_of_birth'    => trim($_POST['place_of_birth'] ?? ''),
    'contact_number'    => trim($_POST['contact_number'] ?? ''),
    'landline_no'       => trim($_POST['landline_no'] ?? ''),
    'address'           => trim($_POST['address'] ?? ''),
    'religion'          => trim($_POST['religion'] ?? ''),
    'nationality'       => trim($_POST['nationality'] ?? 'Filipino'),
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
    'INSERT INTO students (student_number, first_name, middle_name, last_name, sex, birth_date, place_of_birth,
        contact_number, landline_no, address, religion, nationality,
        program_id, year_level, entry_year, classification, status, ra10931_override, academic_status, record_status, created_at)
     VALUES (:student_number, :first_name, :middle_name, :last_name, :sex, :birth_date, :place_of_birth,
        :contact_number, :landline_no, :address, :religion, :nationality,
        :program_id, :year_level, :entry_year, :classification, :status, :ra10931_override, "active", "active", NOW())',
    $data
);
$studentId = (int) db()->lastInsertId();

$elementarySchool = trim($_POST['elementary_school'] ?? '');
$elementaryYear = (int) ($_POST['elementary_year_graduated'] ?? 0);
$elementaryType = trim($_POST['elementary_school_type'] ?? '');
$highSchool = trim($_POST['high_school'] ?? '');
$highSchoolYear = (int) ($_POST['high_school_year_graduated'] ?? 0);
$highSchoolType = trim($_POST['high_school_school_type'] ?? '');

if ($elementarySchool !== '' || $highSchool !== '') {
    execute_sql(
        'INSERT INTO student_educational_background
            (student_id, elementary_school, elementary_year_graduated, elementary_school_type,
             high_school, high_school_year_graduated, high_school_school_type)
         VALUES
            (:sid, :es, :ey, :et, :hs, :hy, :ht)',
        [
            'sid' => $studentId,
            'es'  => $elementarySchool ?: null,
            'ey'  => $elementaryYear ?: null,
            'et'  => $elementaryType ?: null,
            'hs'  => $highSchool ?: null,
            'hy'  => $highSchoolYear ?: null,
            'ht'  => $highSchoolType ?: null,
        ]
    );
}

$guardianName = trim($_POST['guardian_name'] ?? '');
if ($guardianName !== '') {
    execute_sql(
        'INSERT INTO student_guardians
            (student_id, guardian_type, name, address, occupation, landline_no, cellphone_no)
         VALUES
            (:sid, "parent", :name, :addr, :occ, :land, :cell)',
        [
            'sid'  => $studentId,
            'name' => $guardianName,
            'addr' => trim($_POST['guardian_address'] ?? '') ?: null,
            'occ'  => trim($_POST['guardian_occupation'] ?? '') ?: null,
            'land' => trim($_POST['guardian_landline'] ?? '') ?: null,
            'cell' => trim($_POST['guardian_cellphone'] ?? '') ?: null,
        ]
    );
}

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
