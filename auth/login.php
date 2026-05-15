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
    <!-- <div class="login-bg-wrapper">
        <img src="<?= h(app_url('auth/school.jpg')) ?>" alt="school" class="login-bg">
        <div class="login-bg-overlay"></div>
    </div> -->
    <div class="login-card">
        <div class="login-logo">
            <div class="login-logo-icon">
                <span class="material-symbols-outlined" style="font-size:22px;">school</span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2>Welcome back</h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:28px;">Sign in to your account to continue</p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post" action="<?= h(app_url('auth/login_process.php')) ?>">
            <div>
                <label for="identity">Username or Email</label>
                <input id="identity" type="text" name="identity" required placeholder="Enter your username or email">
            </div>
            <div style="margin-top: 16px;">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required placeholder="Enter your password">
            </div>
            <div class="form-actions" style="margin-top:24px;">
                <button class="btn" type="submit" style="flex:1;">Login</button>
                <a class="btn secondary" href="<?= h(app_url('auth/student_register.php')) ?>">Register</a>
            </div>
        </form>

        <div style="margin-top:14px;text-align:center;">
            <a href="<?= h(app_url('auth/forgot_password.php')) ?>" style="font-size:13px;color:#64748b;">Forgot password?</a>
        </div>

        <hr class="soft">
        <p class="helper" style="text-align:center;font-size:12px;">
            Online Enrollment Portal — <?= h(setting('institution_name', 'Your Institution')) ?>
        </p>

    </div>
</div>
<?php
render_page('Login', 'Login', (string) ob_get_clean(), ['show_sidebar' => false]);
