<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('cashier');

$summary = fetch_one(
    'SELECT
        COUNT(*) AS total_approved,
        SUM(CASE WHEN payment_status = "unpaid" THEN 1 ELSE 0 END) AS unpaid_count,
        SUM(CASE WHEN payment_status = "partial" THEN 1 ELSE 0 END) AS partial_count,
        SUM(CASE WHEN payment_status = "paid" THEN 1 ELSE 0 END) AS paid_count,
        SUM(CASE WHEN payment_status = "waived" THEN 1 ELSE 0 END) AS waived_count,
        COALESCE(SUM(total_amount), 0) AS total_due,
        COALESCE(SUM(CASE WHEN payment_status = "unpaid" THEN total_amount ELSE 0 END), 0) AS total_unpaid
     FROM enrollment_requests
     WHERE workflow_status = "registrar_approved"'
);

// $recentPayments = fetch_all(
//     'SELECT p.amount AS amount_paid, p.reference_number AS or_number, p.payment_date, p.payment_method,
//             s.student_number, s.full_name, er.payment_status
//      FROM payments p
//      INNER JOIN students s ON s.id = p.student_id
//      INNER JOIN enrollment_requests er ON er.id = p.request_id
//      ORDER BY p.created_at DESC
//      LIMIT 10'
// );

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Cashier Dashboard</h1>
        <p>Overview of enrollment payments and recent transactions.</p>
    </div>
</div>

<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;"><span class="material-symbols-outlined" style="color:#dc2626;">receipt_long</span></div>
        <div class="stat-info">
            <span class="stat-label">Unpaid</span>
            <span class="stat-value"><?= (int) $summary['unpaid_count'] ?></span>
            <span class="stat-sub">₱<?= h(format_money((float) $summary['total_unpaid'])) ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;"><span class="material-symbols-outlined" style="color:#d97706;">pending</span></div>
        <div class="stat-info">
            <span class="stat-label">Partial</span>
            <span class="stat-value"><?= (int) $summary['partial_count'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;"><span class="material-symbols-outlined" style="color:#16a34a;">check_circle</span></div>
        <div class="stat-info">
            <span class="stat-label">Paid</span>
            <span class="stat-value"><?= (int) $summary['paid_count'] ?></span>
        </div>
    </div>
    <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff;"><span class="material-symbols-outlined" style="color:#2563eb;">payments</span></div>
        <div class="stat-info">
            <span class="stat-label">Total Approved</span>
            <span class="stat-value"><?= (int) $summary['total_approved'] ?></span>
            <span class="stat-sub">₱<?= h(format_money((float) $summary['total_due'])) ?></span>
        </div>
    </div>
</div>

<div class="card" style="margin-top:20px;">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
        <h3 style="margin:0;font-size:16px;font-weight:700;">Recent Transactions</h3>
        <a href="payments.php" class="btn" style="font-size:12px;padding:6px 14px;">View All Payments</a>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Student</th>
                    <th>OR No.</th>
                    <th>Method</th>
                    <th>Amount</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
            <?php if ($recentPayments === []): ?>
                <tr><td colspan="6" style="text-align:center;color:#94a3b8;padding:24px;">No transactions yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($recentPayments as $pay): ?>
                <tr>
                    <td><?= h(date('M d, Y', strtotime($pay['payment_date']))) ?></td>
                    <td><strong><?= h($pay['student_number']) ?></strong><br><span style="font-size:12px;color:#64748b;"><?= h($pay['full_name']) ?></span></td>
                    <td><?= h($pay['or_number'] ?? '—') ?></td>
                    <td><?= h(ucfirst(str_replace('_', ' ', $pay['payment_method']))) ?></td>
                    <td style="font-weight:600;">₱<?= h(format_money($pay['amount_paid'])) ?></td>
                    <td>
                        <span class="badge <?= $pay['payment_status'] === 'paid' ? 'success' : 'warning' ?>"><?= h(ucfirst($pay['payment_status'])) ?></span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<style>
.stat-grid { display:grid; grid-template-columns:repeat(4, 1fr); gap:16px; margin-top:16px; }
.stat-card { background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px; display:flex; gap:14px; align-items:center; }
.stat-icon { width:48px; height:48px; border-radius:12px; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.stat-info { display:flex; flex-direction:column; }
.stat-label { font-size:11px; color:#64748b; font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.stat-value { font-size:24px; font-weight:700; color:#1e293b; }
.stat-sub { font-size:11px; color:#94a3b8; }
@media (max-width:900px) { .stat-grid { grid-template-columns:repeat(2, 1fr); } }
</style>
<?php
render_page('Cashier Dashboard', 'Dashboard', (string) ob_get_clean());
