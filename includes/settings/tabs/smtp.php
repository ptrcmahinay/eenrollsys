<?php
$smtpHost = setting('smtp_host', '');
$smtpPort = setting('smtp_port', '587');
$smtpUsername = setting('smtp_username', '');
$smtpPassword = setting('smtp_password', '');
$smtpFromEmail = setting('smtp_from_email', '');
$smtpFromName = setting('smtp_from_name', '');
$isConfigured = $smtpFromEmail !== '' && $smtpHost !== '';
?>

<div class="settings-card">
    <h3>SMTP Configuration</h3>
    <p class="settings-card-desc">Configure email delivery for enrollment notifications and system alerts.</p>

    <div style="margin-bottom:16px;">
        <?php if ($isConfigured): ?>
            <span class="badge success">SMTP Configured &middot; Emails will be sent</span>
        <?php else: ?>
            <span class="badge warning">Not Configured &middot; Notifications are stored in-app only</span>
        <?php endif; ?>
    </div>

    <form id="smtpForm" onsubmit="return submitSettingsForm('smtpForm', 'update_smtp')">
        <div class="settings-form-grid cols-2">
            <div class="settings-field">
                <label for="smtp_host">SMTP Host</label>
                <input type="text" id="smtp_host" name="smtp_host" value="<?= h($smtpHost) ?>" placeholder="e.g. smtp.gmail.com">
            </div>
            <div class="settings-field">
                <label for="smtp_port">SMTP Port</label>
                <input type="number" id="smtp_port" name="smtp_port" value="<?= h($smtpPort) ?>" placeholder="587">
                <div class="settings-field-hint">Use 587 for TLS, 465 for SSL, or 25 for unencrypted.</div>
            </div>
            <div class="settings-field">
                <label for="smtp_username">SMTP Username</label>
                <input type="text" id="smtp_username" name="smtp_username" value="<?= h($smtpUsername) ?>" placeholder="your@email.com">
            </div>
            <div class="settings-field">
                <label for="smtp_password">SMTP Password</label>
                <input type="password" id="smtp_password" name="smtp_password" value="<?= h($smtpPassword) ?>" placeholder="App password or account password">
            </div>
            <div class="settings-field">
                <label for="smtp_from_email">From Email</label>
                <input type="email" id="smtp_from_email" name="smtp_from_email" value="<?= h($smtpFromEmail) ?>" placeholder="noreply@your-domain.com">
                <div class="settings-field-hint">This email address will appear as the sender on all outgoing emails.</div>
            </div>
            <div class="settings-field">
                <label for="smtp_from_name">From Name</label>
                <input type="text" id="smtp_from_name" name="smtp_from_name" value="<?= h($smtpFromName) ?>" placeholder="<?= h(setting('system_name', 'E-EnrollSys')) ?>">
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save SMTP Settings</button>
        </div>
    </form>
</div>

<div class="settings-card">
    <h3>How It Works</h3>
    <p class="settings-card-desc">When SMTP is configured, the system sends email notifications for:</p>
    <ul style="font-size:13px;color:#475569;line-height:1.8;padding-left:20px;margin:0;">
        <li>Enrollment request submitted, approved, or rejected</li>
        <li>Grade postings and corrections</li>
        <li>Enrollment window open/close alerts</li>
        <li>System maintenance notices</li>
    </ul>
    <p style="font-size:12px;color:#94a3b8;margin-top:10px;">
        For Gmail: use an App Password (not your regular password). Enable 2FA on your Google account, then generate one at myaccount.google.com/apppasswords.
    </p>
</div>
