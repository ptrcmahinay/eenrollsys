<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/app.php';

if (current_user() !== null) {
    redirect('auth/redirect.php');
}

redirect('auth/login.php');
