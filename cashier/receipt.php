<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('cashier');

$requestId = (int) ($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
    set_flash('error', 'Invalid request.');
    redirect('cashier/payments.php');
}

$enrollment = fetch_one(
    'SELECT er.id, er.total_amount, er.payment_status, er.ra10931_status,
            s.student_number, s.full_name, s.year_level, s.address, p.program_code,
            ay.year_label, t.semester
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE er.id = :id',
    ['id' => $requestId]
);

if ($enrollment === null) {
    set_flash('error', 'Enrollment not found.');
    redirect('cashier/payments.php');
}

$payments = fetch_all(
    'SELECT p.*, u.display_name AS cashier_name
     FROM payments p
     LEFT JOIN users u ON u.users_id = p.cashier_id
     WHERE p.request_id = :id
     ORDER BY p.payment_date ASC, p.id ASC',
    ['id' => $requestId]
);

$totalPaid = array_sum(array_map(fn($p) => (float) $p['amount_paid'], $payments));
$balance = max(0, (float) $enrollment['total_amount'] - $totalPaid);

ob_start();
?>
<div style="max-width:700px;margin:auto;">
    <div class="page-header">
        <div>
            <h1>Payment Receipt</h1>
            <p><?= h($enrollment['student_number'] . ' - ' . $enrollment['full_name']) ?></p>
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn" onclick="window.print()">
                <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">print</span>
                Print
            </button>
            <a href="payments.php" class="btn secondary">Back to Payments</a>
        </div>
    </div>

    <div class="receipt-container" id="receiptArea">
        <div class="receipt-header">
            <div class="receipt-logo">
                <span class="material-symbols-outlined" style="font-size:32px;color:#16a34a;">account_balance</span>
            </div>
            <div class="receipt-institution">
                <h2 style="margin:0;font-size:18px;color:#1e293b;"><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h2>
                <p style="margin:2px 0 0;font-size:12px;color:#64748b;"><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
            </div>
            <div class="receipt-title">
                <h3 style="margin:0;font-size:16px;color:#16a34a;">OFFICIAL PAYMENT RECEIPT</h3>
            </div>
        </div>

        <div class="receipt-section">
            <h4>Student Information</h4>
            <div class="receipt-grid">
                <div><strong>Student No.:</strong> <?= h($enrollment['student_number']) ?></div>
                <div><strong>Name:</strong> <?= h($enrollment['full_name']) ?></div>
                <div><strong>Program:</strong> <?= h($enrollment['program_code']) ?></div>
                <div><strong>Year Level:</strong> <?= h($enrollment['year_level']) ?></div>
                <div><strong>Academic Year:</strong> <?= h($enrollment['year_label']) ?></div>
                <div><strong>Semester:</strong> <?= h(semester_label((string) $enrollment['semester'])) ?></div>
            </div>
        </div>

        <div class="receipt-section">
            <h4>Payment Details</h4>
            <table class="receipt-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>OR No.</th>
                        <th>Method</th>
                        <th>Amount Paid</th>
                        <th>Balance</th>
                        <th>Cashier</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($payments as $pay): ?>
                    <tr>
                        <td><?= h(date('M d, Y', strtotime($pay['payment_date']))) ?></td>
                        <td><?= h($pay['or_number'] ?? '—') ?></td>
                        <td><?= h(ucfirst(str_replace('_', ' ', $pay['payment_method']))) ?></td>
                        <td style="text-align:right;font-weight:600;">₱<?= h(format_money($pay['amount_paid'])) ?></td>
                        <td style="text-align:right;">₱<?= h(format_money($pay['balance'])) ?></td>
                        <td><?= h($pay['cashier_name'] ?? '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr style="border-top:2px solid #16a34a;">
                        <td colspan="3" style="font-weight:700;">TOTAL PAID</td>
                        <td style="text-align:right;font-weight:700;color:#16a34a;">₱<?= h(format_money($totalPaid)) ?></td>
                        <td style="text-align:right;font-weight:700;color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>;">₱<?= h(format_money($balance)) ?></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="receipt-section" style="display:flex;justify-content:space-between;align-items:flex-end;margin-top:40px;">
            <div style="font-size:11px;color:#94a3b8;">
                <p style="margin:0;">Generated: <?= date('F d, Y h:i A') ?></p>
                <p style="margin:2px 0 0;">Reference: ENR-<?= str_pad($requestId, 6, '0', STR_PAD_LEFT) ?></p>
            </div>
            <div style="text-align:center;border-top:1px solid #cbd5e1;padding-top:8px;min-width:200px;">
                <p style="margin:0;font-size:12px;font-weight:600;"><?= h($payments ? ($payments[array_key_last($payments)]['cashier_name'] ?? '—') : '—') ?></p>
                <p style="margin:2px 0 0;font-size:11px;color:#64748b;">Authorized Cashier</p>
            </div>
        </div>
    </div>
</div>

<style>
.receipt-container {
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:32px;
    margin-top:16px;
}
.receipt-header {
    display:flex;
    align-items:center;
    gap:16px;
    padding-bottom:16px;
    border-bottom:2px solid #16a34a;
    margin-bottom:20px;
}
.receipt-logo {
    width:56px;
    height:56px;
    border-radius:12px;
    background:#f0fdf4;
    display:flex;
    align-items:center;
    justify-content:center;
    flex-shrink:0;
}
.receipt-institution { flex:1; }
.receipt-title { text-align:right; }
.receipt-section { margin-bottom:20px; }
.receipt-section h4 {
    margin:0 0 10px;
    font-size:13px;
    font-weight:700;
    color:#1e293b;
    text-transform:uppercase;
    letter-spacing:.5px;
}
.receipt-grid {
    display:grid;
    grid-template-columns:1fr 1fr;
    gap:6px;
    font-size:13px;
    color:#374151;
}
.receipt-table {
    width:100%;
    border-collapse:collapse;
    font-size:13px;
}
.receipt-table th {
    background:#f8fafc;
    padding:8px 10px;
    text-align:left;
    font-size:11px;
    font-weight:600;
    color:#64748b;
    text-transform:uppercase;
    border-bottom:1px solid #e2e8f0;
}
.receipt-table td {
    padding:8px 10px;
    border-bottom:1px solid #f1f5f9;
}
.receipt-table tbody tr:last-child td { border-bottom:none; }

@media print {
    .page-header { display:none !important; }
    .receipt-container { border:none; padding:0; margin:0; }
    body { background:#fff !important; }
}
</style>
<?php
render_page('Payment Receipt', 'Receipt', (string) ob_get_clean());
