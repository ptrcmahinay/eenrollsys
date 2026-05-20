<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
$user = current_user();

if ($user === null || empty($user['student_id'])) {
    echo json_encode(['count' => 0]);
    exit;
}

try {
    $row = fetch_one(
        'SELECT COUNT(*) AS cnt FROM student_notifications WHERE student_id = :sid AND dismissed = 0',
        ['sid' => (int) $user['student_id']]
    );
    echo json_encode(['count' => (int) ($row['cnt'] ?? 0)]);
} catch (\Throwable $e) {
    echo json_encode(['count' => 0]);
}
