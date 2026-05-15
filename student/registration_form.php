<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('student');

$student = current_student();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$termId = (int) ($_GET['term_id'] ?? 0);
$requestId = (int) ($_GET['request_id'] ?? 0);
$lookupId = $requestId > 0 ? $requestId : $termId;

$request = fetch_one(
    'SELECT er.*, t.semester, ay.year_label
     FROM enrollment_requests er
     INNER JOIN academic_terms t ON t.id = er.term_id
     INNER JOIN academic_years ay ON ay.id = t.academic_year_id
     WHERE er.id = :id AND er.student_id = :sid AND er.workflow_status = "registrar_approved"
     LIMIT 1',
    ['id' => $lookupId, 'sid' => (int) $student['id']]
);

if ($request === null) {
    flash('error', 'Registration form not available. Enrollment must be approved by the registrar.');
    redirect('student/enrollment_status.php');
}

$items = enrollment_request_items((int) $request['id']);

$program = fetch_one(
    'SELECT program_code, program_name FROM programs WHERE programs_id = :id',
    ['id' => (int) $student['program_id']]
);

$section = fetch_one(
    'SELECT section_name, year_level FROM sections WHERE id = :id',
    ['id' => (int) $request['requested_section_id']]
);

$financial = financial_profile($student, fetch_one('SELECT * FROM academic_terms WHERE id = :tid', ['tid' => (int) $request['term_id']]));
$otherFees = (float) setting('other_school_fees', '2500');

$enrolledAt = $request['registrar_processed_at'] ?? $request['created_at'];

ob_start();
?>
<div class="reg-form-container">
    <div class="reg-header">
        <div class="reg-logo">
            <span class="material-symbols-outlined">school</span>
        </div>
        <div class="reg-title">
            <p class="reg-rep">Republic of the Philippines</p>
            <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
            <h2>Registration Form</h2>
            <p class="reg-subtitle"><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?> · Official Student Enrollment Record</p>
        </div>
    </div>

    <div class="reg-stamp">
        <span>ENROLLED</span>
    </div>

    <div class="reg-info-grid">
        <div class="reg-info-item">
            <span class="reg-label">Student Number</span>
            <span class="reg-value"><?= h($student['student_number']) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Full Name</span>
            <span class="reg-value"><?= h($student['full_name']) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Program</span>
            <span class="reg-value"><?= h($program['program_code'] . ' - ' . $program['program_name']) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Section</span>
            <span class="reg-value"><?= h($section['year_level'] . '-' . $section['section_name']) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Semester</span>
            <span class="reg-value"><?= h(semester_label((string) $request['semester'])) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">School Year</span>
            <span class="reg-value"><?= h('A.Y. ' . $request['year_label']) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Date &amp; Time Enrolled</span>
            <span class="reg-value"><?= h(date('F j, Y g:i A', strtotime($enrolledAt))) ?></span>
        </div>
        <div class="reg-info-item">
            <span class="reg-label">Status</span>
            <span class="reg-value">Regular</span>
        </div>
    </div>

    <h3 class="reg-section-title">Subjects Enrolled</h3>

    <table class="reg-table">
        <thead>
            <tr>
                <th class="reg-col-num">No.</th>
                <th class="reg-col-code">Course Code</th>
                <th class="reg-col-desc">Course Description</th>
                <th class="reg-col-units">Units</th>
                <th class="reg-col-time">Time</th>
                <th class="reg-col-day">Day</th>
                <th class="reg-col-room">Room</th>
            </tr>
        </thead>
        <tbody>
            <?php $i = 1; $totalUnits = 0; ?>
            <?php foreach ($items as $item): ?>
                <tr>
                    <td class="reg-col-num"><?= $i++ ?></td>
                    <td class="reg-col-code"><?= h($item['subject_code']) ?></td>
                    <td class="reg-col-desc"><?= h($item['subject_description']) ?></td>
                    <td class="reg-col-units"><?= h($item['units']) ?></td>
                    <td class="reg-col-time"><?= h($item['time_range'] ?: 'TBA') ?></td>
                    <td class="reg-col-day"><?= h($item['day_of_week'] ?: 'TBA') ?></td>
                    <td class="reg-col-room"><?= h($item['room'] ?: 'TBA') ?></td>
                </tr>
                <?php $totalUnits += (float) $item['units']; ?>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <td colspan="3" class="reg-total-label">Total Units</td>
                <td class="reg-total-value"><?= h((string) $totalUnits) ?></td>
                <td colspan="3"></td>
            </tr>
        </tfoot>
    </table>

    <div class="reg-fees">
        <h3 class="reg-section-title">Tuition Fee Breakdown</h3>
        <div class="reg-fees-grid">
            <div class="reg-fee-item">
                <span class="reg-fee-label">Tuition per Unit</span>
                <span class="reg-fee-value">&#8369;<?= h(format_money($financial['tuition_per_unit'])) ?></span>
            </div>
            <div class="reg-fee-item">
                <span class="reg-fee-label">Total Units</span>
                <span class="reg-fee-value"><?= h((string) $totalUnits) ?></span>
            </div>
            <div class="reg-fee-item">
                <span class="reg-fee-label">Tuition Fee</span>
                <span class="reg-fee-value">&#8369;<?= h(format_money($request['total_amount'])) ?></span>
            </div>
            <div class="reg-fee-item">
                <span class="reg-fee-label">Other School Fees</span>
                <span class="reg-fee-value">&#8369;<?= h(format_money($otherFees)) ?></span>
            </div>
            <div class="reg-fee-item reg-fee-total">
                <span class="reg-fee-label">Total Amount Due</span>
                <span class="reg-fee-value">&#8369;<?= h(format_money((float) $request['total_amount'] + $otherFees)) ?></span>
            </div>
        </div>
    </div>

    <div class="reg-footer">
        <div class="reg-signature">
            <div class="reg-sig-line"></div>
            <div class="reg-sig-name">Registrar</div>
            <div class="reg-sig-title">Office of the Registrar</div>
        </div>
        <div class="reg-date-printed">
            Printed: <?= h(date('F j, Y g:i A')) ?>
        </div>
    </div>
</div>

<div class="reg-actions no-print">
    <a class="btn secondary" href="<?= h(app_url('student/enrollment_status.php')) ?>">&larr; Back to Enrollment Status</a>
    <button class="btn" onclick="window.print()"><span class="material-symbols-outlined" style="font-size:18px;">download</span> Print / Download PDF</button>
</div>

<style>
@page {
    size: A4;
    margin: 15mm;
}

.reg-form-container {
    width: 210mm;
    min-height: 297mm;
    margin: 0 auto;
    padding: 18mm 15mm;
    background: #fff;
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    color: #1e293b;
    position: relative;
    box-sizing: border-box;
}

.reg-header {
    display: flex;
    align-items: center;
    gap: 16px;
    padding-bottom: 10px;
    border-bottom: 3px solid #22c55e;
    margin-bottom: 14px;
}

.reg-logo {
    width: 52px;
    height: 52px;
    background: linear-gradient(135deg, #16a34a, #22c55e);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.reg-logo .material-symbols-outlined {
    font-size: 28px;
    color: #fff;
}

.reg-title h1 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #16a34a;
    letter-spacing: 0.5px;
}

.reg-rep {
    margin: 0;
    font-size: 10px;
    color: #64748b;
    letter-spacing: 0.5px;
}

.reg-title h2 {
    margin: 2px 0 0;
    font-size: 18px;
    font-weight: 800;
    color: #1e293b;
    letter-spacing: -0.3px;
}

.reg-subtitle {
    margin: 1px 0 0;
    font-size: 9px;
    color: #64748b;
    letter-spacing: 0.3px;
}

.reg-stamp {
    position: absolute;
    top: 14mm;
    right: 14mm;
    width: 78px;
    height: 78px;
    border: 3px solid #22c55e;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transform: rotate(-15deg);
    opacity: 0.6;
}

.reg-stamp span {
    font-size: 14px;
    font-weight: 800;
    color: #22c55e;
    letter-spacing: 1.5px;
}

.reg-info-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 6px 10px;
    margin-bottom: 14px;
    background: #f0fdf4;
    padding: 10px 12px;
    border-radius: 6px;
    border: 1px solid #bbf7d0;
}

.reg-info-item {
    display: flex;
    flex-direction: column;
    gap: 1px;
}

.reg-label {
    font-size: 8.5px;
    font-weight: 600;
    color: #15803d;
    text-transform: uppercase;
    letter-spacing: 0.4px;
}

.reg-value {
    font-size: 11px;
    font-weight: 600;
    color: #1e293b;
}

.reg-section-title {
    font-size: 13px;
    font-weight: 700;
    color: #16a34a;
    margin: 0 0 8px;
    padding-bottom: 4px;
    border-bottom: 2px solid #bbf7d0;
}

.reg-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
    font-size: 10px;
}

.reg-table th {
    background: linear-gradient(135deg, #16a34a, #22c55e);
    color: #fff;
    padding: 6px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.reg-table td {
    padding: 5px 8px;
    border-bottom: 1px solid #e2e8f0;
}

.reg-table tbody tr:nth-child(even) {
    background: #f0fdf4;
}

.reg-col-num { width: 28px; text-align: center; }
.reg-col-code { width: 72px; font-weight: 600; }
.reg-col-desc { }
.reg-col-units { width: 36px; text-align: center; }
.reg-col-time { width: 82px; }
.reg-col-day { width: 88px; }
.reg-col-room { width: 58px; }

.reg-table tfoot {
    background: #f0fdf4;
    font-weight: 700;
}

.reg-total-label {
    padding: 6px 8px;
    text-align: right;
    font-size: 10px;
}

.reg-total-value {
    padding: 6px 8px;
    text-align: center;
    font-size: 10px;
}

.reg-fees {
    background: #f0fdf4;
    padding: 10px 12px;
    border-radius: 6px;
    border: 1px solid #bbf7d0;
    margin-bottom: 18px;
}

.reg-fees .reg-section-title {
    margin-bottom: 6px;
}

.reg-fees-grid {
    display: grid;
    grid-template-columns: repeat(5, 1fr);
    gap: 6px;
}

.reg-fee-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: 6px 8px;
    background: #fff;
    border-radius: 4px;
    border: 1px solid #bbf7d0;
}

.reg-fee-label {
    font-size: 8.5px;
    font-weight: 600;
    color: #15803d;
    text-transform: uppercase;
}

.reg-fee-value {
    font-size: 11px;
    font-weight: 700;
    color: #1e293b;
}

.reg-fee-total {
    background: linear-gradient(135deg, #16a34a, #22c55e) !important;
    border-color: #16a34a !important;
}

.reg-fee-total .reg-fee-label,
.reg-fee-total .reg-fee-value {
    color: #fff;
}

.reg-footer {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    padding-top: 24px;
    border-top: 2px solid #bbf7d0;
}

.reg-signature {
    text-align: center;
    width: 220px;
}

.reg-sig-line {
    border-bottom: 2px solid #1e293b;
    margin-bottom: 6px;
    height: 32px;
}

.reg-sig-name {
    font-weight: 700;
    font-size: 12px;
}

.reg-sig-title {
    font-size: 10px;
    color: #64748b;
}

.reg-date-printed {
    font-size: 10px;
    color: #64748b;
}

.reg-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 16px;
    padding: 14px;
}

.no-print { }

@media print {
    body { margin: 0; padding: 0; background: #fff; }
    .no-print { display: none !important; }
    .layout { display: block !important; }
    .main { padding: 0 !important; margin: 0 !important; }
    header, .sidebar, .sidebar-overlay { display: none !important; }
    .reg-form-container {
        width: 100%;
        padding: 0;
        margin: 0;
        box-shadow: none;
    }
    .reg-table th {
        background: linear-gradient(135deg, #16a34a, #22c55e) !important;
        color: #fff !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-table tbody tr:nth-child(even) {
        background: #f0fdf4 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-table tfoot {
        background: #f0fdf4 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-info-grid {
        background: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-fees {
        background: #f0fdf4 !important;
        border-color: #bbf7d0 !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-fee-item {
        border-color: #bbf7d0 !important;
    }
    .reg-fee-total {
        background: linear-gradient(135deg, #16a34a, #22c55e) !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-logo {
        background: linear-gradient(135deg, #16a34a, #22c55e) !important;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .reg-stamp { opacity: 0.35; }
    .reg-header { border-bottom-color: #22c55e; }
    .reg-footer { border-top-color: #bbf7d0; }
}
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    setTimeout(function() { window.print(); }, 500);
});
</script>
<?php

$show_sidebar = false;
render_page('Registration Form', 'Registration Form', (string) ob_get_clean(), ['show_sidebar' => false]);
