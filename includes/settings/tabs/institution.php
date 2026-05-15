<?php
$systemName = setting('system_name', 'E-Enrollment System');
$campusName = setting('campus_name', 'Cavite State University Naic');
$campusAddress = setting('campus_address', 'Bucana, Naic, Cavite');
$institutionName = setting('institution_name', 'Your Institution');
$registrarName = setting('registrar_name', 'Campus Registrar');
$registrarTitle = setting('registrar_title', 'Campus Registrar');
$registrarSig = setting('registrar_signature', '');
$cogPurpose = setting('cog_purpose', 'For scholarship purposes only.');
?>

<div class="settings-card">
    <h3>System & Institution</h3>
    <p class="settings-card-desc">These values appear on documents, headers, and login pages.</p>

    <form id="institutionForm" onsubmit="return submitSettingsForm('institutionForm', 'update_institution')">
        <div class="settings-form-grid cols-2">
            <div class="settings-field">
                <label for="inst_system_name">System Name</label>
                <input type="text" id="inst_system_name" name="system_name" value="<?= h($systemName) ?>">
                <div class="settings-field-hint">Shown in sidebar header, browser title, and email footers.</div>
            </div>
            <div class="settings-field">
                <label for="inst_institution_name">Institution Name</label>
                <input type="text" id="inst_institution_name" name="institution_name" value="<?= h($institutionName) ?>">
                <div class="settings-field-hint">Fallback name used in some templates.</div>
            </div>
            <div class="settings-field">
                <label for="inst_campus_name">Campus Name</label>
                <input type="text" id="inst_campus_name" name="campus_name" value="<?= h($campusName) ?>">
                <div class="settings-field-hint">Used on registration forms, COG, and checklist documents.</div>
            </div>
            <div class="settings-field">
                <label for="inst_campus_address">Campus Address</label>
                <input type="text" id="inst_campus_address" name="campus_address" value="<?= h($campusAddress) ?>">
            </div>
        </div>

        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <h4 style="margin:0 0 4px;font-size:14px;font-weight:600;color:#1e293b;">Registrar Signature</h4>
            <p style="margin:0 0 12px;font-size:12px;color:#64748b;">Appears on official documents like COG and Registration Form.</p>
            <div class="settings-form-grid cols-2">
                <div class="settings-field">
                    <label for="inst_registrar_name">Registrar Name</label>
                    <input type="text" id="inst_registrar_name" name="registrar_name" value="<?= h($registrarName) ?>">
                </div>
                <div class="settings-field">
                    <label for="inst_registrar_title">Registrar Title</label>
                    <input type="text" id="inst_registrar_title" name="registrar_title" value="<?= h($registrarTitle) ?>">
                </div>
                <div class="settings-field">
                    <label for="inst_registrar_signature">Signature Image</label>
                    <input type="file" id="inst_registrar_signature" name="registrar_signature" accept="image/png,image/jpeg,image/webp">
                    <div class="settings-field-hint">PNG with transparent background works best. Max 2 MB.</div>
                    <?php if ($registrarSig !== ''): ?>
                    <img src="<?= h(app_url('uploads/' . $registrarSig)) ?>" alt="Current signature" class="settings-sig-preview">
                    <div>
                        <label class="settings-checkbox" style="margin-top:6px;">
                            <input type="checkbox" name="remove_signature" value="1"> Remove current signature
                        </label>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div style="margin-top:14px;padding-top:14px;border-top:1px solid #e2e8f0;">
            <h4 style="margin:0 0 12px;font-size:14px;font-weight:600;color:#1e293b;">Certificate of Grades</h4>
            <div class="settings-form-grid">
                <div class="settings-field">
                    <label for="inst_cog_purpose">Default COG Purpose</label>
                    <textarea id="inst_cog_purpose" name="cog_purpose"><?= h($cogPurpose) ?></textarea>
                    <div class="settings-field-hint">Used when no purpose is specified on a COG request.</div>
                </div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Institution Settings</button>
        </div>
    </form>
</div>
