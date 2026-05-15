<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('registrar');

redirect('includes/settings.php?tab=enrollment');
