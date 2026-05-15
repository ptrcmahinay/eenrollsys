<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/document_renderer.php';

$user = require_login();
$allowed = in_array($user['role'], ['student', 'registrar', 'admin'], true);
if (!$allowed) {
    http_response_code(403);
    exit('Forbidden');
}

$studentId = (int) ($_GET['student_id'] ?? ($user['role'] === 'student' ? ($user['student_id'] ?? 0) : 0));
if ($studentId <= 0) {
    exit('Student not found.');
}

$termId = ($_GET['term_id'] ?? '') !== '' ? (int) $_GET['term_id'] : null;
render_cog_document($studentId, $termId);
