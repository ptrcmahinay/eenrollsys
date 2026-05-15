<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (current_user() !== null) {
    redirect('auth/redirect.php');
}

$token = trim((string) ($_GET['token'] ?? ''));
$message = '';
$success = false;

if ($token !== '') {
    $user = fetch_one(
        'SELECT users_id FROM users WHERE verification_token = :token AND verified_at IS NULL LIMIT 1',
        ['token' => $token]
    );

    if ($user === null) {
        $message = 'Invalid or expired verification link. Your account may already be verified.';
    } else {
        execute_sql(
            'UPDATE users SET verified_at = NOW(), verification_token = NULL WHERE users_id = :id',
            ['id' => (int) $user['users_id']]
        );
        $success = true;
        $message = 'Email verified successfully! You can now log in to your account.';
    }
}

ob_start();
$flashes = get_flashes();
?>
<div class="login-shell">
    <div class="login-card" style="max-width:420px;">
        <div class="login-logo">
            <div class="login-logo-icon">
                <span class="material-symbols-outlined" style="font-size:22px;"><?= $success ? 'verified' : 'warning' ?></span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2><?= $success ? 'Email Verified' : 'Verification Failed' ?></h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;"><?= h($message) ?></p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div style="margin-top:24px;">
            <a class="btn" href="<?= h(app_url('auth/login.php')) ?>" style="display:block;text-align:center;">
                <?= $success ? 'Go to Login' : 'Back to Login' ?>
            </a>
        </div>

        <hr class="soft">
        <p class="helper" style="text-align:center;font-size:12px;">
            Online Enrollment Portal &mdash; <?= h(setting('institution_name', 'Your Institution')) ?>
        </p>
    </div>
</div>
<?php
render_page('Email Verification', 'Email Verification', (string) ob_get_clean(), ['show_sidebar' => false]);
