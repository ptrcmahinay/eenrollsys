<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/document_renderer.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$termId = ($_GET['term_id'] ?? '') !== '' ? (int) $_GET['term_id'] : null;

if (is_post()) {
    verify_csrf();
    $purpose = trim($_POST['purpose'] ?? '');
    if ($purpose === 'Other') {
        $purpose = trim($_POST['custom_purpose'] ?? '');
    }
    if ($purpose === '') {
        flash('error', 'Please select or specify a purpose for your COG request.');
        redirect('student/cog.php' . ($termId !== null ? '?term_id=' . $termId : ''));
    }

    create_notification('student', (int) $student['id'], 'info', 'COG Requested', 'You requested a Certificate of Grades for: ' . $purpose . ' on ' . date('F j, Y g:i A'));

    $selectedTermId = ($_POST['term_id'] ?? '') !== '' ? (int) $_POST['term_id'] : null;
    render_cog_document((int) $student['id'], $selectedTermId, $purpose);
    exit;
}

$cogPurposes = setting('cog_purposes', 'Scholarship purposes|Graduation|Employment|Transfer|Visa Application|Board Exam|Personal Reference|Other');
$purposeOptions = explode('|', $cogPurposes);

$terms = fetch_all(
    'SELECT DISTINCT t.id, ay.year_label, t.semester
     FROM student_subjects ss
     INNER JOIN academic_terms t ON t.id = ss.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE ss.student_id = :sid
     ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid") DESC',
    ['sid' => (int) $student['id']]
);

ob_start();
?>
<div class="cog-page">
    <div class="cog-card">
        <div class="cog-header">
            <div class="cog-logo">
                <span class="material-symbols-outlined">school</span>
            </div>
            <div class="cog-title">
                <p class="cog-rep">Republic of the Philippines</p>
                <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
                <h2>Certificate of Grades</h2>
                <p class="cog-subtitle"><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
            </div>
        </div>

        <div class="cog-student-info">
            <div class="cog-info-row">
                <span class="cog-label">Student Number</span>
                <span class="cog-value"><?= h($student['student_number']) ?></span>
            </div>
            <div class="cog-info-row">
                <span class="cog-label">Full Name</span>
                <span class="cog-value"><?= h($student['full_name']) ?></span>
            </div>
            <div class="cog-info-row">
                <span class="cog-label">Program</span>
                <span class="cog-value"><?= h($student['program_code']) ?></span>
            </div>
        </div>

        <form method="post" class="cog-form">
            <?= csrf_field() ?>

            <div class="cog-form-group">
                <label class="cog-form-label" for="purposeSelect">Purpose of Request</label>
                <select name="purpose" id="purposeSelect" required class="cog-form-select">
                    <option value="">-- Select purpose --</option>
                    <?php foreach ($purposeOptions as $opt): ?>
                        <option value="<?= h(trim($opt)) ?>"><?= h(trim($opt)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cog-form-group" id="customPurposeGroup" style="display:none;">
                <label class="cog-form-label" for="customPurpose">Specify Purpose</label>
                <input type="text" name="custom_purpose" id="customPurpose" class="cog-form-input" placeholder="Enter your purpose">
            </div>

            <div class="cog-form-group">
                <label class="cog-form-label">Academic Term (optional)</label>
                <p class="cog-form-hint">Leave blank to include all terms.</p>
                <select name="term_id" class="cog-form-select">
                    <option value="">All Terms</option>
                    <?php foreach ($terms as $t): ?>
                        <option value="<?= h((string)$t['id']) ?>" <?= $termId === (int)$t['id'] ? 'selected' : '' ?>>
                            <?= h($t['year_label'] . ' - ' . semester_label((string)$t['semester'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cog-form-actions">
                <a class="btn secondary" href="<?= h(app_url('student/grades.php')) ?>">&larr; Back to Grades</a>
                <button type="submit" class="btn">
                    <span class="material-symbols-outlined" style="font-size:18px;">download</span> Generate COG
                </button>
            </div>
        </form>
    </div>
</div>

<style>
.cog-page { min-height: 100vh; background: #f0fdf4; display: flex; align-items: center; justify-content: center; padding: 24px; }
.cog-card { max-width: 520px; width: 100%; background: #fff; border-radius: 16px; box-shadow: 0 8px 32px rgba(22,163,74,.1); border: 1px solid #bbf7d0; padding: 28px; }
.cog-header { display: flex; align-items: center; gap: 16px; padding-bottom: 14px; border-bottom: 3px solid #22c55e; margin-bottom: 18px; }
.cog-logo { width: 48px; height: 48px; background: linear-gradient(135deg, #16a34a, #22c55e); border-radius: 50%; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.cog-logo .material-symbols-outlined { font-size: 26px; color: #fff; }
.cog-title h1 { margin: 0; font-size: 15px; font-weight: 700; color: #16a34a; }
.cog-rep { margin: 0; font-size: 10px; color: #64748b; }
.cog-title h2 { margin: 2px 0 0; font-size: 18px; font-weight: 800; color: #1e293b; }
.cog-subtitle { margin: 1px 0 0; font-size: 9px; color: #64748b; }
.cog-student-info { display: flex; flex-direction: column; gap: 6px; margin-bottom: 20px; background: #f0fdf4; padding: 10px 12px; border-radius: 8px; border: 1px solid #bbf7d0; }
.cog-info-row { display: flex; justify-content: space-between; align-items: center; }
.cog-label { font-size: 10px; font-weight: 600; color: #15803d; text-transform: uppercase; }
.cog-value { font-size: 12px; font-weight: 600; color: #1e293b; }
.cog-form { display: flex; flex-direction: column; gap: 16px; }
.cog-form-group { display: flex; flex-direction: column; gap: 4px; }
.cog-form-label { font-size: 13px; font-weight: 600; color: #1e293b; }
.cog-form-hint { font-size: 11px; color: #64748b; margin: 0; }
.cog-form-select, .cog-form-input { padding: 10px 12px; border: 1px solid #bbf7d0; border-radius: 8px; font-size: 13px; background: #fff; color: #1e293b; outline: none; transition: border-color .2s; }
.cog-form-select:focus, .cog-form-input:focus { border-color: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.15); }
.cog-form-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 8px; }
.cog-btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; padding: 10px 18px; border-radius: 10px; font-size: 13px; font-weight: 600; cursor: pointer; border: none; text-decoration: none; transition: all .2s; }
.cog-btn-primary { background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; box-shadow: 0 2px 8px rgba(22,163,74,.25); }
.cog-btn-primary:hover { background: linear-gradient(135deg, #15803d, #16a34a); box-shadow: 0 4px 12px rgba(22,163,74,.35); transform: translateY(-1px); }
.cog-btn-secondary { background: #fff; color: #16a34a; border: 1px solid #bbf7d0; }
.cog-btn-secondary:hover { background: #f0fdf4; border-color: #86efac; }
</style>

<script>
document.getElementById('purposeSelect').addEventListener('change', function() {
    document.getElementById('customPurposeGroup').style.display = this.value === 'Other' ? 'flex' : 'none';
});
</script>
<?php
render_page('Request COG', 'COG', (string) ob_get_clean());
