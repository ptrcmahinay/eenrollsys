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

$sql .= ' ORDER BY al.created_at DESC LIMIT 200';

$logs = fetch_all($sql, $params);

$actions = fetch_all('SELECT DISTINCT action FROM enrollment_audit_log ORDER BY action');
$roles = fetch_all('SELECT DISTINCT actor_role FROM enrollment_audit_log WHERE actor_role IS NOT NULL ORDER BY actor_role');

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Audit Log</h1>
        <p>Track all enrollment actions: who approved, rejected, or modified requests and when.</p>
    </div>
    <?php if (!empty($logs)): ?>
        <form method="get" action="<?= h(app_url('admin/export_audit_csv.php')) ?>" style="display:inline;">
            <?php if ($filterAction): ?><input type="hidden" name="action" value="<?= h($filterAction) ?>"><?php endif; ?>
            <?php if ($filterRole): ?><input type="hidden" name="role" value="<?= h($filterRole) ?>"><?php endif; ?>
            <?php if ($filterDate): ?><input type="hidden" name="date" value="<?= h($filterDate) ?>"><?php endif; ?>
            <button class="btn secondary" type="submit">Export CSV</button>
        </form>
    <?php endif; ?>
</div>

<div style="display:flex;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
    <form method="get" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <select name="action" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <option value="">All actions</option>
            <?php foreach ($actions as $a): ?>
                <option value="<?= h($a['action']) ?>" <?= $filterAction === $a['action'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', $a['action'])) ?></option>
            <?php endforeach; ?>
        </select>
        <select name="role" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
            <option value="">All roles</option>
            <?php foreach ($roles as $r): ?>
                <option value="<?= h($r['actor_role']) ?>" <?= $filterRole === $r['actor_role'] ? 'selected' : '' ?>><?= h(str_replace('_', ' ', ucfirst($r['actor_role']))) ?></option>
            <?php endforeach; ?>
        </select>
        <input type="date" name="date" value="<?= h($filterDate) ?>" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;">
        <input type="number" name="request_id" value="<?= $filterRequest > 0 ? h((string) $filterRequest) : '' ?>" placeholder="Request ID" style="padding:6px 10px;border:1px solid var(--line);border-radius:8px;font-size:13px;width:120px;">
        <button class="btn small secondary" type="submit">Filter</button>
        <?php if ($filterAction || $filterRole || $filterDate || $filterRequest > 0): ?>
            <a class="btn small secondary" href="<?= h(app_url('admin/audit_log.php')) ?>">Clear</a>
        <?php endif; ?>
    </form>
</div>

<?php if (empty($logs)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">history</span>
        <p class="helper">No audit log entries found.</p>
    </div>
<?php else: ?>
<div class="card">
    <div class="table-wrap">
        <table>
            <thead><tr><th>Timestamp</th><th>Request</th><th>Student</th><th>Action</th><th>Actor</th><th>Old Status</th><th>New Status</th><th>Remark</th></tr></thead>
            <tbody>
            <?php foreach ($logs as $log): ?>
                <tr>
                    <td style="white-space:nowrap;"><?= h(date('M j, Y g:i A', strtotime($log['created_at']))) ?></td>
                    <td><a href="<?= h(app_url('registrar/enrollment.php')) ?>"><?= h('#' . $log['request_id']) ?></a></td>
                    <td><?= h($log['full_name'] ? ($log['student_number'] . ' - ' . $log['full_name']) : 'N/A') ?></td>
                    <td>
                        <?php
                        $actionType = $log['action'];
                        $actionBadge = match(true) {
                            str_contains($actionType, 'approve') || str_contains($actionType, 'finalize') => 'success',
                            str_contains($actionType, 'reject') => 'danger',
                            str_contains($actionType, 'cancel') => 'warning',
                            str_contains($actionType, 'submit') => 'info',
                            default => '',
                        };
                        ?>
                        <span class="badge <?= $actionBadge !== '' ? h($actionBadge) : '' ?>"><?= h(str_replace('_', ' ', $actionType)) ?></span>
                    </td>
                    <td><?= h(ucfirst(str_replace('_', ' ', $log['actor_role'] ?: 'system'))) ?> #<?= h($log['actor_id'] ?: '0') ?></td>
                    <td><?= $log['old_status'] ? '<span class="badge info">' . h(str_replace('_', ' ', $log['old_status'])) . '</span>' : '—' ?></td>
                    <td><span class="badge <?= h(workflow_badge_class($log['new_status'] ?? '')) ?>"><?= h(str_replace('_', ' ', $log['new_status'] ?? '—')) ?></span></td>
                    <td><?= h($log['remark'] ?: '-') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="helper" style="margin-top:8px;">Showing last <?= count($logs) ?> entries.</p>
</div>
<?php endif; ?>
<?php
render_page('Audit Log', 'Audit Log', (string) ob_get_clean());
