<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';

if (is_post()) {
    verify_csrf();
    $studentNumber = trim($_POST['student_number'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters long.');
        redirect('auth/student_register.php');
    }

    $student = fetch_one('SELECT * FROM students WHERE student_number = :student_number LIMIT 1', ['student_number' => $studentNumber]);
    if ($student === null) {
        flash('error', 'Student number not found. Ask the admin or registrar to create your student record first.');
        redirect('auth/student_register.php');
    }

    $existingUser = fetch_one('SELECT users_id FROM users WHERE student_id = :student_id OR email = :email OR username = :username LIMIT 1', [
        'student_id' => (int) $student['id'],
        'email' => $email,
        'username' => $username,
    ]);

    if ($existingUser !== null) {
        flash('error', 'An account already exists for this student number, username, or email.');
        redirect('auth/student_register.php');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(32));
    execute_sql(
        'INSERT INTO users (username, email, password, verification_token, student_id, created_at) VALUES (:username, :email, :password, :token, :student_id, NOW())',
        [
            'username' => $username,
            'email' => $email,
            'password' => $hash,
            'token' => $token,
            'student_id' => (int) $student['id'],
        ]
    );
    $userId = (int) db()->lastInsertId();
    $studentRole = fetch_one('SELECT roles_id FROM roles WHERE role_name = "student" LIMIT 1');
    execute_sql('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)', [
        'user_id' => $userId,
        'role_id' => (int) ($studentRole['roles_id'] ?? 1),
    ]);

    // Send verification email
    $portalName = setting('system_name', 'E-EnrollSys');
    $baseUrl = rtrim(app_url(''), '/');
    $verifyUrl = $baseUrl . '/auth/verify_email.php?token=' . $token;

    $htmlBody = '<div style="font-family:Arial,sans-serif;max-width:600px;margin:auto;padding:20px;">
        <h2 style="color:#16a34a;">Verify Your Email Address</h2>
        <p>Hello ' . htmlspecialchars($username) . ',</p>
        <p>Thank you for registering for ' . htmlspecialchars($portalName) . '.</p>
        <p>Please click the button below to verify your email address and activate your account.</p>
        <p style="margin:24px 0;">
            <a href="' . htmlspecialchars($verifyUrl) . '"
               style="display:inline-block;padding:12px 28px;background:#16a34a;color:#fff;text-decoration:none;border-radius:8px;font-weight:600;">
                Verify My Email
            </a>
        </p>
        <p style="font-size:13px;color:#6b7280;">Or copy this link into your browser:</p>
        <p style="font-size:12px;word-break:break-all;background:#f3f4f6;padding:8px 12px;border-radius:6px;">' . htmlspecialchars($verifyUrl) . '</p>
        <hr style="border:1px solid #e5e7eb;margin:20px 0;">
        <p style="color:#9ca3af;font-size:12px;">This link expires in 24 hours. If you did not create an account, ignore this email.</p>
    </div>';

    send_email($email, '[' . $portalName . '] Verify Your Email Address', $htmlBody);

    flash('success', 'Account created! Check your email for the verification link to activate your account.');
    redirect('auth/verification_sent.php');
}

ob_start();
$flashes = get_flashes();
?>
<div class="login-shell">
    <div class="login-card">

        <div class="login-logo">
            <div class="login-logo-icon">
                <span class="material-symbols-outlined" style="font-size:22px;">person_add</span>
            </div>
            <span class="login-logo-text"><?= h(setting('system_name', 'E-EnrollSys')) ?></span>
        </div>

        <h2>Student Registration</h2>
        <p style="color:var(--muted);font-size:14px;margin-bottom:24px;">
            Your student record must be created by the admin or registrar first. Then you can register your online account here.
        </p>

        <?php if ($flashes !== []): ?>
            <div class="flash-stack">
                <?php foreach ($flashes as $flash): ?>
                    <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="post">
            <?= csrf_field() ?>
            <div class="form-grid">
                <div>
                    <label>Student Number</label>
                    <input type="text" name="student_number" required placeholder="e.g. 2024-00001">
                </div>
                <div>
                    <label>Email</label>
                    <input type="email" name="email" required placeholder="your@email.com">
                </div>
                <div>
                    <label>Username</label>
                    <input type="text" name="username" required placeholder="Choose a username">
                </div>
                <div>
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="Create a password">
                </div>
            </div>
            <div class="form-actions" style="margin-top:24px;">
                <button class="btn" type="submit" style="flex:1;">Create Account</button>
                <a class="btn secondary" href="<?= h(app_url('auth/login.php')) ?>">Back to Login</a>
            </div>
        </form>

    </div>
</div>
<?php
render_page('Student Registration', 'Student Registration', (string) ob_get_clean(), ['show_sidebar' => false]);
