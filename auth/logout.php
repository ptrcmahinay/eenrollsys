<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

session_unset();
session_destroy();
session_start();
flash('success', 'You have been logged out.');
redirect('auth/login.php');
