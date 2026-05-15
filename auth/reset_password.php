<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (current_user() !== null) {
    redirect('auth/redirect.php');
}

$token = trim((string) ($_GET['token'] ?? ''));
if ($token === '') {
    set_flash('error', 'Invalid reset link.');
    redirect('auth/forgot_password.php');
}

$resetRow = fetch_one(
    'SELECT t.user_id, t.expires_at, u.email, u.display_name
     FROM password_reset_tokens t
     INNER JOIN users u ON u.users_id = t.user_id
     WHERE t.token = :token AND t.used = 0',
    ['token' => $token]
);

if ($resetRow === null) {
    set_flash('error', 'Invalid or already-used reset link.');
    redirect('auth/forgot_password.php');
}

if (strtotime($resetRow['expires_at']) < time()) {
    set_flash('error', 'This reset link has expired. Please request a new one.');
    redirect('auth/forgot_password.php');
}

ob_start();
$flashes = get_flashes();
?>
<div class="login-shell">
    <div class="login-card" style="max-width:420px;">
        <div class="login-logo">
            <div class="login-logo-icon">
                <span class="material-symbols-outlined" style="font-size:22px;">key</span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2>Set a new password</h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;">
            Create a new password for <strong><?= h($resetRow['email']) ?></strong>.
        </p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= h(app_url('auth/reset_password_process.php')) ?>">
            <input type="hidden" name="token" value="<?= h($token) ?>">
            <div>
                <label for="password">New password</label>
                <input id="password" type="password" name="password" required minlength="6" placeholder="At least 6 characters">
            </div>
            <div style="margin-top:14px;">
                <label for="password_confirm">Confirm password</label>
                <input id="password_confirm" type="password" name="password_confirm" required minlength="6" placeholder="Re-enter the same password">
            </div>
            <div class="form-actions" style="margin-top:24px;">
                <button class="btn" type="submit" style="flex:1;">Update password</button>
            </div>
        </form>

        <div style="margin-top:20px;text-align:center;">
            <a href="<?= h(app_url('auth/login.php')) ?>" style="font-size:13px;color:#64748b;">&larr; Back to login</a>
        </div>

        <hr class="soft">
        <p class="helper" style="text-align:center;font-size:12px;">
            Online Enrollment Portal &mdash; <?= h(setting('institution_name', 'Your Institution')) ?>
        </p>
    </div>
</div>
<?php
render_page('Reset Password', 'Reset Password', (string) ob_get_clean(), ['show_sidebar' => false]);
