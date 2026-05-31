<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('cashier');

if (!is_post()) {
    redirect('cashier/payments.php');
}

$user = current_user();
$requestId = (int) ($_POST['request_id'] ?? 0);
$amountPaid = (float) ($_POST['amount_paid'] ?? 0);
$orNumber = trim((string) ($_POST['or_number'] ?? ''));
$paymentMethod = trim((string) ($_POST['payment_method'] ?? 'cash'));
$paymentDate = trim((string) ($_POST['payment_date'] ?? date('Y-m-d')));
$remarks = trim((string) ($_POST['remarks'] ?? ''));

if ($requestId <= 0 || $amountPaid <= 0) {
    flash('error', 'Enter a valid amount.');
    redirect('cashier/process_payment.php?request_id=' . $requestId);
}

$allowedMethods = ['cash', 'check', 'bank_transfer', 'online'];
if (!in_array($paymentMethod, $allowedMethods, true)) {
    $paymentMethod = 'cash';
}

$enrollment = fetch_one(
    'SELECT er.total_amount, er.payment_status, s.id AS student_id,
            COALESCE(SUM(p.amount), 0) AS total_paid
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     LEFT JOIN payments p ON p.request_id = er.id
     WHERE er.id = :id AND er.workflow_status = "registrar_approved"',
    ['id' => $requestId]
);

if ($enrollment === null) {
    flash('error', 'Enrollment not found.');
    redirect('cashier/payments.php');
}

if ($enrollment['payment_status'] === 'paid' || $enrollment['payment_status'] === 'waived') {
    flash('error', 'This enrollment is already fully paid or waived.');
    redirect('cashier/receipt.php?request_id=' . $requestId);
}

$currentPaid = (float) $enrollment['total_paid'];
$newPaid = $currentPaid + $amountPaid;
$totalDue = (float) $enrollment['total_amount'];
$newBalance = max(0, $totalDue - $newPaid);

execute_sql(
    'INSERT INTO payments (request_id, student_id, amount, payment_method, payment_date, reference_number, remarks)
     VALUES (:request_id, :student_id, :amount, :method, :date, :ref_no, :remarks)',
    [
        'request_id' => $requestId,
        'student_id' => (int) $enrollment['student_id'],
        'amount' => $amountPaid,
        'method' => $paymentMethod,
        'date' => $paymentDate,
        'ref_no' => $orNumber !== '' ? $orNumber : null,
        'remarks' => $remarks !== '' ? $remarks : null,
    ]
);

$newStatus = $newBalance <= 0 ? 'paid' : 'partial';
execute_sql(
    'UPDATE enrollment_requests SET payment_status = :status WHERE id = :id',
    ['status' => $newStatus, 'id' => $requestId]
);

flash('success', 'Payment recorded. ' . ($newStatus === 'paid' ? 'Enrollment is now fully paid.' : 'Remaining balance: ₱' . number_format($newBalance, 2)));
redirect('cashier/receipt.php?request_id=' . $requestId);
