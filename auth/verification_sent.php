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
                <span class="material-symbols-outlined" style="font-size:22px;">mail</span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2>Check Your Email</h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;">
            We've sent a verification link to your email address. Click the link to activate your account before logging in.
        </p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top:24px;">
            <a class="btn" href="<?= h(app_url('auth/login.php')) ?>" style="display:block;text-align:center;">
                Go to Login
            </a>
        </div>

        <hr class="soft">
        <p class="helper" style="text-align:center;font-size:12px;">
            Online Enrollment Portal &mdash; <?= h(setting('institution_name', 'Your Institution')) ?>
        </p>
    </div>
</div>
<?php
render_page('Verification Sent', 'Verification Sent', (string) ob_get_clean(), ['show_sidebar' => false]);
