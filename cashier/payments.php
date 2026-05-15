<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('cashier');

$flashes = get_flashes();

$filter = trim((string) ($_GET['filter'] ?? 'unpaid'));
$allowedFilters = ['unpaid', 'partial', 'paid', 'waived', 'all'];
if (!in_array($filter, $allowedFilters, true)) {
    $filter = 'unpaid';
}

$where = 'er.workflow_status = "registrar_approved"';
if ($filter !== 'all') {
    $where .= ' AND er.payment_status = :filter';
    $params = ['filter' => $filter];
} else {
    $params = [];
}

$rows = fetch_all(
    "SELECT er.id, er.total_amount, er.payment_status, er.ra10931_status,
            s.student_number, s.full_name, p.program_code,
            ay.year_label, t.semester,
            COALESCE(SUM(pay.amount_paid), 0) AS total_paid,
            (er.total_amount - COALESCE(SUM(pay.amount_paid), 0)) AS remaining
     FROM enrollment_requests er
     INNER JOIN students s ON s.id = er.student_id
     INNER JOIN programs p ON p.programs_id = s.program_id
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     LEFT JOIN payments pay ON pay.request_id = er.id
     WHERE {$where}
     GROUP BY er.id
     ORDER BY er.updated_at DESC",
    $params
);

$statusLabels = [
    'unpaid' => 'Unpaid',
    'partial' => 'Partial',
    'paid' => 'Paid in Full',
    'waived' => 'Waived',
    'all' => 'All',
];

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Payments</h1>
        <p>Record payments for registrar-approved enrollments.</p>
    </div>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="card" style="margin-bottom:16px;">
    <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php foreach ($allowedFilters as $f): ?>
        <a href="?filter=<?= h($f) ?>"
           class="btn <?= $filter === $f ? '' : 'secondary' ?>"
           style="font-size:12px;padding:6px 14px;">
            <?= h($statusLabels[$f]) ?>
            <?php if ($f !== 'all'): ?>
                <span style="opacity:.7;margin-left:4px;">(<?= count(array_filter($rows, fn($r) => $r['payment_status'] === $f)) ?>)</span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Student</th>
                    <th>Program</th>
                    <th>Term</th>
                    <th>Total Due</th>
                    <th>Paid</th>
                    <th>Balance</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php if ($rows === []): ?>
                <tr><td colspan="8" style="text-align:center;color:#94a3b8;padding:32px;">No records found.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td><strong><?= h($row['student_number']) ?></strong><br><span style="font-size:12px;color:#64748b;"><?= h($row['full_name']) ?></span></td>
                    <td><?= h($row['program_code']) ?></td>
                    <td><?= h($row['year_label'] . ' / ' . semester_label((string) $row['semester'])) ?></td>
                    <td>₱<?= h(format_money($row['total_amount'])) ?></td>
                    <td>₱<?= h(format_money((float) $row['total_paid'])) ?></td>
                    <td style="font-weight:600;color:<?= ((float) $row['remaining'] > 0) ? '#dc2626' : '#16a34a' ?>;">₱<?= h(format_money(max(0, (float) $row['remaining']))) ?></td>
                    <td>
                        <?php
                        $badgeClass = match($row['payment_status']) {
                            'unpaid' => 'badge danger',
                            'partial' => 'badge warning',
                            'paid' => 'badge success',
                            'waived' => 'badge info',
                            default => 'badge',
                        };
                        ?>
                        <span class="<?= $badgeClass ?>"><?= h($statusLabels[$row['payment_status']] ?? $row['payment_status']) ?></span>
                    </td>
                    <td>
                        <?php if ($row['payment_status'] !== 'paid' && $row['payment_status'] !== 'waived'): ?>
                        <a href="process_payment.php?request_id=<?= (int) $row['id'] ?>" class="btn" style="font-size:12px;padding:6px 12px;">
                            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">payments</span>
                            Pay
                        </a>
                        <?php else: ?>
                        <a href="receipt.php?request_id=<?= (int) $row['id'] ?>" class="btn secondary" style="font-size:12px;padding:6px 12px;">
                            <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;margin-right:4px;">receipt_long</span>
                            Receipt
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
render_page('Payments', 'Payments', (string) ob_get_clean());
