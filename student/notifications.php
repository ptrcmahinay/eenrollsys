<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    verify_csrf();
    $action = trim($_POST['action'] ?? '');
    if ($action === 'dismiss') {
        dismiss_notification('student', (int) ($_POST['notif_id'] ?? 0));
        redirect('student/notifications.php');
    }
    if ($action === 'dismiss_all') {
        execute_sql('UPDATE student_notifications SET dismissed = 1 WHERE student_id = :sid', ['sid' => (int) $student['id']]);
        redirect('student/notifications.php');
    }
}

try {
    execute_sql(
        'UPDATE student_notifications SET is_read = 1 WHERE student_id = :sid AND is_read = 0',
        ['sid' => (int) $student['id']]
    );
} catch (\Throwable $e) { /* table may not exist yet */ }

$notifications = [];
try {
    $notifications = fetch_all(
        'SELECT * FROM student_notifications WHERE student_id = :sid AND dismissed = 0 ORDER BY created_at DESC',
        ['sid' => (int) $student['id']]
    );
} catch (\Throwable $e) { /* table may not exist yet */ }

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Notifications</h1>
        <p>Status updates on your enrollment requests.</p>
    </div>
    <?php if (!empty($notifications)): ?>
    <form method="post" style="display:inline;">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="dismiss_all">
        <button class="btn secondary" type="submit">Dismiss All</button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>
    <div class="card" style="text-align:center;padding:40px;">
        <span class="material-symbols-outlined" style="font-size:48px;color:var(--muted);display:block;margin-bottom:10px;">notifications_none</span>
        <p class="helper">No notifications yet. You will be notified here when your enrollment request status changes.</p>
    </div>
<?php else: ?>
    <div class="grid" style="gap:10px;">
    <?php foreach ($notifications as $n): ?>
        <div class="card inline-notif <?= h(inline_notification_badge_class((string) ($n['type'] ?? 'info'))) ?>" style="margin:0;border-left-width:4px;">
            <div class="inline-notif-icon">
                <span class="material-symbols-outlined"><?= h(inline_notification_icon((string) ($n['type'] ?? 'info'))) ?></span>
            </div>
            <div class="inline-notif-body">
                <div class="inline-notif-title"><?= h($n['subject']) ?></div>
                <div class="inline-notif-text"><?= h($n['body']) ?></div>
                <div class="inline-notif-time"><?= h(date('M j, Y g:i A', strtotime($n['created_at']))) ?></div>
            </div>
            <form method="post" style="display:inline;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="dismiss">
                <input type="hidden" name="notif_id" value="<?= h($n['id']) ?>">
                <button class="inline-notif-dismiss" type="submit" title="Dismiss">
                    <span class="material-symbols-outlined">close</span>
                </button>
            </form>
        </div>
    <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php
render_page('Notifications', 'Notifications', (string) ob_get_clean());
