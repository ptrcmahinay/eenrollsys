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
            s.student_number, s.full_name, s.year_level, p.program_code,
            ay.year_label, t.semester,
            COALESCE(SUM(pay.amount_paid), 0) AS total_paid
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     LEFT JOIN payments pay ON pay.request_id = er.id
     WHERE er.id = :id AND er.workflow_status = "registrar_approved"
     GROUP BY er.id',
    ['id' => $requestId]
);

if ($enrollment === null) {
    set_flash('error', 'Enrollment not found or not yet approved.');
    redirect('cashier/payments.php');
}

if ($enrollment['payment_status'] === 'paid' || $enrollment['payment_status'] === 'waived') {
    set_flash('info', 'This enrollment is already settled.');
    redirect('cashier/receipt.php?request_id=' . $requestId);
}

$totalPaid = (float) $enrollment['total_paid'];
$balance = max(0, (float) $enrollment['total_amount'] - $totalPaid);
$flashes = get_flashes();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Process Payment</h1>
        <p>Record a payment for <strong><?= h($enrollment['student_number'] . ' - ' . $enrollment['full_name']) ?></strong>.</p>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
    <div class="card">
        <h3 style="margin:0 0 12px;font-size:15px;">Student Details</h3>
        <div style="font-size:13px;line-height:2;color:#374151;">
            <div><strong>Student:</strong> <?= h($enrollment['student_number'] . ' - ' . $enrollment['full_name']) ?></div>
            <div><strong>Program:</strong> <?= h($enrollment['program_code']) ?></div>
            <div><strong>Year Level:</strong> <?= h($enrollment['year_level']) ?></div>
            <div><strong>Term:</strong> <?= h($enrollment['year_label'] . ' / ' . semester_label((string) $enrollment['semester'])) ?></div>
            <div><strong>Fee Type:</strong> <?= h(str_replace('_', ' ', ucwords($enrollment['ra10931_status'], '_'))) ?></div>
        </div>
    </div>

    <div class="card">
        <h3 style="margin:0 0 12px;font-size:15px;">Payment Summary</h3>
        <div style="font-size:13px;line-height:2;color:#374151;">
            <div><strong>Total Due:</strong> <span style="float:right;">₱<?= h(format_money($enrollment['total_amount'])) ?></span></div>
            <div><strong>Total Paid:</strong> <span style="float:right;color:#16a34a;">₱<?= h(format_money($totalPaid)) ?></span></div>
            <div style="border-top:1px solid #e2e8f0;padding-top:8px;margin-top:4px;">
                <strong>Remaining Balance:</strong>
                <span style="float:right;font-weight:700;color:<?= $balance > 0 ? '#dc2626' : '#16a34a' ?>;">₱<?= h(format_money($balance)) ?></span>
            </div>
        </div>
    </div>
</div>

<div class="card" style="margin-top:16px;">
    <h3 style="margin:0 0 16px;font-size:15px;">Record Payment</h3>
    <form method="post" action="process_payment_handler.php">
        <input type="hidden" name="request_id" value="<?= $requestId ?>">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div>
                <label for="amount_paid">Amount Paid (₱)</label>
                <input type="number" id="amount_paid" name="amount_paid" step="0.01" min="0.01" max="<?= h($balance) ?>" value="<?= h(number_format($balance, 2, '.', '')) ?>" required>
            </div>
            <div>
                <label for="or_number">Official Receipt No.</label>
                <input type="text" id="or_number" name="or_number" placeholder="e.g. OR-2026-00123">
            </div>
            <div>
                <label for="payment_method">Payment Method</label>
                <select id="payment_method" name="payment_method">
                    <option value="cash">Cash</option>
                    <option value="check">Check</option>
                    <option value="bank_transfer">Bank Transfer</option>
                    <option value="online">Online Payment</option>
                </select>
            </div>
            <div>
                <label for="payment_date">Payment Date</label>
                <input type="date" id="payment_date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
            </div>
            <div style="grid-column:1/-1;">
                <label for="remarks">Remarks (optional)</label>
                <textarea id="remarks" name="remarks" rows="2" placeholder="e.g. Installment 2 of 3"></textarea>
            </div>
        </div>
        <div style="margin-top:16px;display:flex;gap:8px;">
            <button class="btn" type="submit">Confirm Payment</button>
            <a href="payments.php" class="btn secondary">Cancel</a>
        </div>
    </form>
</div>
<?php
render_page('Process Payment', 'Payments', (string) ob_get_clean());
