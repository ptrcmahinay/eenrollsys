<?php
$cogPurposes = setting('cog_purposes', 'Scholarship purposes|Graduation|Employment|Transfer|Visa Application|Board Exam|Personal Reference|Other');
$maxSlots = (int) setting('max_section_slots', '40');
?>

<div class="settings-card">
    <h3>Certificate of Grades</h3>
    <p class="settings-card-desc">Configure purpose options available when students request a COG.</p>

    <form id="academicForm" onsubmit="return submitSettingsForm('academicForm', 'update_academic')">
        <div class="settings-form-grid">
            <div class="settings-field">
                <label for="cog_purposes">Purpose Options</label>
                <input type="text" id="cog_purposes" name="cog_purposes" value="<?= h($cogPurposes) ?>">
                <div class="settings-field-hint">Separate each option with a pipe character (|). Example: Scholarship|Graduation|Employment</div>
            </div>
        </div>

        <div class="settings-form-grid" style="margin-top:14px;">
            <div class="settings-field">
                <label for="max_slots">Default Max Section Slots</label>
                <input type="number" id="max_slots" name="max_section_slots" value="<?= h((string) $maxSlots) ?>">
                <div class="settings-field-hint">Default capacity for new sections. Overrideable per section.</div>
            </div>
        </div>

        <div class="settings-actions">
            <button class="btn" type="submit">Save Academic Settings</button>
        </div>
    </form>
</div>
