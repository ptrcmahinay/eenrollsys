<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/app.php';
require_role('student');

header('Content-Type: application/json');

$student = current_student();
$currentTerm = current_term();
$sectionId = (int) ($_GET['section_id'] ?? 0);

if (!$student || !$currentTerm || $sectionId <= 0) {
    echo json_encode(['error' => 'Invalid request']);
    exit;
}

/* REGULAR */
$regular = regular_offerings_for_student(
    (int)$student['id'],
    (int)$currentTerm['id'],
    $sectionId
);

foreach ($regular as &$r) {
    $elig = prerequisite_status_for_curriculum((int)$student['id'], $r);
    $r['eligible'] = $elig['eligible'];
    $r['reason'] = $elig['reason'] ?? '';
}

/* IRREGULAR */
$irregular = irregular_offerings_for_student(
    (int)$student['id'],
    (int)$currentTerm['id']
);

/* IMPORTANT: ensure units exist */
foreach ($irregular as &$r) {
    $r['units'] = (float) ($r['units'] ?? 0);
}

echo json_encode([
    'regular' => $regular,
    'irregular' => $irregular
]);