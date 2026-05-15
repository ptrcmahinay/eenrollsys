<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (current_user() !== null) {
    redirect('auth/redirect.php');
}

ob_start();
$flashes = get_flashes();
?>
<div class="login-shell">
    <div class="login-card" style="max-width:420px;">
        <div class="login-logo">
            <div class="login-logo-icon">
                <span class="material-symbols-outlined" style="font-size:22px;">lock_reset</span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2>Reset your password</h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;">
            Enter the email address linked to your account. We'll send you a link to reset your password.
        </p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= h(app_url('auth/forgot_password_process.php')) ?>">
            <div>
                <label for="email">Email address</label>
                <input id="email" type="email" name="email" required placeholder="you@example.com">
            </div>
            <div class="form-actions" style="margin-top:24px;">
                <button class="btn" type="submit" style="flex:1;">Send reset link</button>
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
render_page('Forgot Password', 'Forgot Password', (string) ob_get_clean(), ['show_sidebar' => false]);
