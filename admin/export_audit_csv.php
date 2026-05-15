<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('admin');

$filterAction = trim($_GET['action'] ?? '');
$filterRole = trim($_GET['role'] ?? '');
$filterDate = trim($_GET['date'] ?? '');
$filterRequest = (int) ($_GET['request_id'] ?? 0);

$sql = 'SELECT al.*, s.student_number, s.full_name
        FROM enrollment_audit_log al
        LEFT JOIN enrollment_requests er ON er.id = al.request_id
        LEFT JOIN students s ON s.id = er.student_id
        WHERE 1=1';
$params = [];

if ($filterAction !== '') { $sql .= ' AND al.action = :act'; $params['act'] = $filterAction; }
if ($filterRole !== '') { $sql .= ' AND al.actor_role = :role'; $params['role'] = $filterRole; }
if ($filterDate !== '') { $sql .= ' AND DATE(al.created_at) = :date'; $params['date'] = $filterDate; }
if ($filterRequest > 0) { $sql .= ' AND al.request_id = :rid'; $params['rid'] = $filterRequest; }

$sql .= ' ORDER BY al.created_at DESC';

$logs = fetch_all($sql, $params);

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="audit_log_' . date('Y-m-d') . '.csv"');

$out = fopen('php://output', 'w');
fputcsv($out, ['Timestamp', 'Request ID', 'Student Number', 'Student Name', 'Action', 'Actor Role', 'Actor ID', 'Old Status', 'New Status', 'Remark']);

foreach ($logs as $log) {
    fputcsv($out, [
        date('Y-m-d H:i:s', strtotime($log['created_at'])),
        $log['request_id'],
        $log['student_number'] ?? '',
        $log['full_name'] ?? '',
        $log['action'],
        $log['actor_role'] ?? '',
        $log['actor_id'] ?? '',
        $log['old_status'] ?? '',
        $log['new_status'] ?? '',
        $log['remark'] ?? '',
    ]);
}

fclose($out);
exit;
