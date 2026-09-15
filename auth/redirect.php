<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

$user = require_login();
$role = $user['role'];
$allRoles = $user['roles'] ?? [];

if (in_array('instructor', $allRoles, true) && in_array('adviser', $allRoles, true)) {
    redirect('instructor/dashboard.php');
}

$map = [
    'admin' => 'admin/dashboard.php',
    'registrar' => 'registrar/dashboard.php',
    'chair' => 'chair/dashboard.php',
    'adviser' => 'adviser/dashboard.php',
    'instructor' => 'instructor/dashboard.php',
    'cashier' => 'cashier/dashboard.php',
    'student' => 'student/dashboard.php',
];

redirect($map[$role] ?? 'student/dashboard.php');
