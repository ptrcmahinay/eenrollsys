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
    'SELECT program_code, program_name, program_major FROM programs WHERE programs_id = :id',
    ['id' => (int) $student['program_id']]
);

$section = fetch_one(
    'SELECT section_name, year_level FROM sections WHERE id = :id',
    ['id' => (int) $request['requested_section_id']]
);

$financial = financial_profile($student, fetch_one('SELECT * FROM academic_terms WHERE id = :tid', ['tid' => (int) $request['term_id']]));
$otherFees = (float) setting('other_school_fees', '2500');
$feeItems = fee_items_for_enrollment((int) $student['program_id'], (int) $student['year_level'], (string) $request['semester']);

$tuitionPerUnit = 0;
$labFeeRate = 0;
$labFeeName = 'Laboratory Fee';
if (isset($feeItems['assessment'])) {
    foreach ($feeItems['assessment'] as $fi) {
        if (strcasecmp($fi['fee_name'], 'tuition') === 0) {
            $tuitionPerUnit = (float) $fi['amount'];
            break;
        }
    }
}
if (isset($feeItems['laboratory'])) {
    foreach ($feeItems['laboratory'] as $fi) {
        $labFeeRate = (float) $fi['amount'];
        $labFeeName = ucfirst($fi['fee_name']) . ' Fee';
        break;
    }
}

$enrolledAt = $request['registrar_processed_at'] ?? $request['created_at'];

ob_start();
?>
<div class="form-wrapper" id="formContent">
    <div class="header">
        <div class="header-logo">
            <span class="material-symbols-outlined">school</span>
        </div>
        <div class="header-text">
            <p class="rep-text">Republic of the Philippines</p>
            <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
            <p class="campus-sub"><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
            <p class="form-title"><strong>REGISTRATION FORM</strong></p>
        </div>
    </div>

    <div class="stamp">
        <span>ENROLLED</span>
    </div>

    <div class="top-section">
        <div class="left-column">
            <div class="form-row">
                <span class="label">Student Number:</span>
                <div class="value"><?= h($student['student_number']) ?></div>
            </div>
            <div class="form-row">
                <span class="label">Student Name:</span>
                <div class="value"><?= h($student['full_name']) ?></div>
            </div>
            <div class="form-row">
                <span class="label">Course:</span>
                <div class="value"><?= h($program['program_code']) ?></div>
            </div>
            <div class="form-row">
                <span class="label">Address:</span>
                <div class="value"><?= h($student['address'] ?? '') ?></div>
            </div>
        </div>

        <div class="right-column">
            <div class="form-row">
                <span class="label">Semester:</span>
                <div class="value"><?= h(str_replace(' Semester', '', semester_label((string) $request['semester']))) ?></div>
                <span class="label" style="min-width: 80px; margin-left: 20px;">Schoolyear:</span>
                <div class="value" style="flex: 0.8;"><?= h($request['year_label']) ?></div>
            </div>
            <div class="form-row">
                <span class="label">Date:</span>
                <div class="value"><?= h(date('F j, Y g:i A', strtotime($enrolledAt))) ?></div>
                <span class="label" style="min-width: 40px; margin-left: 20px;"></span>
            </div>
            <div class="form-row">
                <span class="label">Encoder:</span>
                <div class="value"></div>
                <span class="label" style="min-width: 40px; margin-left: 20px;">Major:</span>
                <div class="value" style="flex: 0.8;"><?= h(!empty($program['program_major']) ? $program['program_major'] : 'N/A') ?></div>
            </div>
            <div class="form-row">
                <span class="label">Section:</span>
                <div class="value"><?= h($section['year_level'] . '-' . $section['section_name']) ?></div>
            </div>
        </div>
    </div>

    <div class="course-section">
        <table>
            <thead>
                <tr>
                    <th style="width: 10%">Sched Code</th>
                    <th style="width: 14%">Course Code</th>
                    <th style="width: 35%">Course Description</th>
                    <th style="width: 8%">Units</th>
                    <th style="width: 12%">Time</th>
                    <th style="width: 8%">Day</th>
                    <th style="width: 12%">Room</th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 1; $totalUnits = 0; $totalLabCredits = 0; ?>
                <?php foreach ($items as $item): ?>
                <tr>
                    <td></td>
                    <td><?= h($item['subject_code']) ?></td>
                    <td><?= h($item['subject_description']) ?></td>
                    <td style="text-align:center"><?= h($item['units']) ?></td>
                    <td></td>
                    <td></td>
                    <td></td>
                </tr>
                <?php $totalUnits += (float) $item['units']; ?>
                <?php $totalLabCredits += (float) ($item['lab_credit'] ?? 0); ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php
$labFee = $labFeeRate * $totalLabCredits;
$tuitionOnly = $totalUnits * $tuitionPerUnit;
$feeItemsTotal = 0;
foreach ($feeItems as $cat => $items) {
    if ($cat === 'laboratory') continue;
    foreach ($items as $fi) {
        if ($cat === 'assessment' && strcasecmp($fi['fee_name'], 'tuition') === 0) continue;
        $feeItemsTotal += (float) $fi['amount'];
    }
}
$totalDue = $tuitionOnly + $labFee + $otherFees + $feeItemsTotal;
?>

    <div class="fee-section">
        <div class="fee-column">
            <div class="fee-column-header">Laboratory Fees</div>
            <div class="fee-item">
                <span class="fee-label"><?= h($labFeeName) ?> (<?= h((string) $totalLabCredits) ?> crd)</span>
                <span class="fee-amount">&#8369;<?= h(format_money($labFee)) ?></span>
            </div>
        </div>

        <div class="fee-column">
            <div class="fee-column-header">Other Fees</div>
            <div class="fee-item">
                <span class="fee-label">Other School Fees</span>
                <span class="fee-amount">&#8369;<?= h(format_money($otherFees)) ?></span>
            </div>
            <?php if (isset($feeItems['other'])): ?>
                <?php foreach ($feeItems['other'] as $fi): ?>
                <div class="fee-item">
                    <span class="fee-label"><?= h($fi['fee_name']) ?></span>
                    <span class="fee-amount">&#8369;<?= h(format_money((float) $fi['amount'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="fee-column">
            <div class="fee-column-header">Assessment</div>
            <div class="fee-item">
                <span>Tuition (<?= h((string) $totalUnits) ?> units × &#8369;<?= h(format_money($tuitionPerUnit)) ?>)</span>
                <span class="fee-amount">&#8369;<?= h(format_money($tuitionOnly)) ?></span>
            </div>
            <?php if (isset($feeItems['assessment'])): ?>
                <?php foreach ($feeItems['assessment'] as $fi): ?>
                    <?php if (strcasecmp($fi['fee_name'], 'tuition') === 0) continue; ?>
                <div class="fee-item">
                    <span><?= h($fi['fee_name']) ?></span>
                    <span class="fee-amount">&#8369;<?= h(format_money((float) $fi['amount'])) ?></span>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="fee-column">
            <div class="fee-column-header">Summary</div>
            <div class="fee-item">
                <span>Total UNITS:</span>
                <span class="fee-amount"><?= h((string) $totalUnits) ?></span>
            </div>
            <div class="fee-item">
                <span>Total HOURS:</span>
                <span class="fee-amount"><?= h((string) $totalLabCredits) ?></span>
            </div>
            <div class="fee-item" style="border-top: 1px solid #16a34a; padding-top: 5px;">
                <span style="font-weight: bold;">Total AMOUNT:</span>
                <span class="fee-amount" style="color: #16a34a;">&#8369;<?= h(format_money($totalDue)) ?></span>
            </div>
            <div class="fee-item">
                <span>Scholarship</span>
            </div>
            <div class="fee-item">
                <span><?= h($financial['status'] === 'free' ? 'RA 10931 (Free Education)' : 'N/A') ?></span>
                <span class="fee-amount">&#8369;0.00</span>
            </div>
            <div style="border-top: 1px solid #16a34a; padding-top: 8px; margin-top: 5px;">
                <div class="fee-item">
                    <span style="font-size: 10px;">Tuition</span>
                    <span style="font-size: 10px; text-align: right;">&#8369;<?= h(format_money($financial['status'] === 'free' ? 0 : $tuitionOnly)) ?></span>
                </div>
                <div class="fee-item">
                    <span style="font-size: 10px;">SFDF</span>
                    <span style="font-size: 10px; text-align: right;">&#8369;<?= h(format_money($financial['status'] === 'free' ? 0 : ($feeItems['assessment'][2]['amount'] ?? 0))) ?></span>
                </div>
                <div class="fee-item">
                    <span style="font-size: 10px;">SRF</span>
                    <span style="font-size: 10px; text-align: right;">&#8369;<?= h(format_money($financial['status'] === 'free' ? 0 : ($feeItems['assessment'][3]['amount'] ?? 0))) ?></span>
                </div>
            </div>
            <div style="border-top: 1px solid #16a34a; padding-top: 6px; margin-top: 4px;">
                <div class="summary-title" style="font-size: 9px; margin-bottom: 3px;">Terms of Payment</div>
                <div style="font-size: 9px; line-height: 1.5;">
                    <div>First: &#8369;<?= h(format_money($totalDue / 3)) ?></div>
                    <div>Second: &#8369;<?= h(format_money($totalDue / 3)) ?></div>
                    <div>Third: &#8369;<?= h(format_money($totalDue - (2 * ($totalDue / 3)))) ?></div>
                </div>
            </div>
        </div>
        <div class="fee-column" style="grid-column: 1 / -1; border-top: none; font-style: italic; color: #475569; background: #f0fdf4;">
            <p style="margin: 0; font-size: 10px;">I hereby agree to abide the existing rules and regulation of this institution.</p>
        </div>
    </div>

    <div class="notes">
        <strong>NOTE:</strong> Course slots on the above subjects will be confirmed only upon payment.
    </div>
</div>

<div class="form-actions no-print">
    <a class="btn secondary" href="<?= h(app_url('student/enrollment_status.php')) ?>">&larr; Back to Enrollment Status</a>
    <button class="btn" onclick="window.print()"><span class="material-symbols-outlined" style="font-size:18px;">download</span> Print / Download PDF</button>
</div>

<style>
@page {
    size: A4 portrait;
    margin: 8mm;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

body {
    font-family: 'Times New Roman', Times, serif;
    background: #f0fdf4;
    color: #1e293b;
}

.form-wrapper {
    background: white;
    max-width: 1000px;
    margin: 20px auto;
    padding: 40px;
    box-shadow: 0 0 15px rgba(22, 163, 74, 0.15);
    border: 1px solid #bbf7d0;
    border-radius: 4px;
    position: relative;
}

/* Header */
.header {
    text-align: center;
    margin-bottom: 20px;
    border-bottom: 3px solid #16a34a;
    padding-bottom: 15px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
}

.header-logo {
    width: 56px;
    height: 56px;
    background: linear-gradient(135deg, #15803d, #22c55e);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.header-logo .material-symbols-outlined {
    font-size: 30px;
    color: #fff;
}

.header-text h1 {
    font-size: 18px;
    font-weight: 700;
    color: #15803d;
    letter-spacing: 2px;
    margin: 2px 0;
}

.rep-text {
    font-size: 11px;
    color: #64748b;
    letter-spacing: 0.5px;
    margin: 0;
}

.campus-sub {
    font-size: 11px;
    color: #64748b;
    margin: 2px 0;
}

.form-title {
    font-size: 16px;
    font-weight: 800;
    color: #1e293b;
    letter-spacing: 3px;
    margin: 5px 0 0;
}

/* Stamp */
.stamp {
    position: absolute;
    top: 28px;
    right: 35px;
    width: 80px;
    height: 80px;
    border: 3px solid #22c55e;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    transform: rotate(-15deg);
    opacity: 0.5;
}

.stamp span {
    font-size: 13px;
    font-weight: 800;
    color: #22c55e;
    letter-spacing: 1.5px;
}

/* Top Section - Two Columns */
.top-section {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 30px;
    margin-bottom: 20px;
    font-size: 13px;
    background: #ffffff;
    padding: 14px 8px;
}

.left-column, .right-column {
    display: flex;
    flex-direction: column;
    gap: 8px;
}

.form-row {
    display: flex;
    gap: 10px;
    align-items: center;
}

.label {
    font-weight: 800;
    min-width: 130px;
    font-size: 12px;
    color: #15803d;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.value {
    flex: 1;
    padding: 2px 5px;
    min-height: 18px;
    font-size: 13px;
    font-weight: 600;
    color: #1e293b;
}

/* Course Table */
.course-section {
    margin: 20px 0;
}

.section-header {
    background: none;
    border: 1px solid #16a34a;
    padding: 8px 10px;
    font-weight: 700;
    font-size: 13px;
    color: #15803d;
    letter-spacing: 0.5px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 15px;
}

table thead {
    background: none;
}

table th {
    background: none;
    border: 1px solid #16a34a;
    padding: 7px 8px;
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    color: #16a34a;
}

table td {
    border: 1px solid #16a34a;
    padding: 5px 8px;
    height: 25px;
    font-size: 12px;
}

table tbody tr:nth-child(even) {
    background: none;
}

/* Fee Section */
.fee-section {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr 1fr;
    gap: 1px;
    border: 1px solid #16a34a;
    margin-bottom: 18px;
    overflow: hidden;
    background: #16a34a;
}

.fee-column {
    background: white;
    padding: 10px;
    font-size: 12px;
}

.fee-column-header {
    font-weight: 700;
    border-bottom: 1px solid #16a34a;
    padding-bottom: 5px;
    margin-bottom: 6px;
    color: #15803d;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.fee-item {
    display: flex;
    justify-content: space-between;
    margin-bottom: 4px;
    padding-bottom: 3px;
    border-bottom: 1px solid #dcfce7;
}

.fee-item:last-child {
    border-bottom: none;
    margin-bottom: 0;
    padding-bottom: 0;
}

.fee-label {
    font-size: 11px;
    color: #475569;
}

.fee-amount {
    font-weight: 700;
    text-align: right;
    min-width: 60px;
    color: #1e293b;
}

/* Summary Section */
.summary-title {
    font-weight: 700;
    margin-bottom: 8px;
    font-size: 12px;
    color: #15803d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.notes {
    margin-top: 15px;
    font-size: 12px;
    text-align: left;
    padding: 8px 10px;
    background: #f0fdf4;
    border-left: 3px solid #16a34a;
    color: #475569;
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: center;
    margin-top: 16px;
    padding: 14px;
}

.no-print { }

@media print {
    .no-print, .form-actions { display: none !important; }
    body { margin: 0; padding: 0; background: #fff; font-size: 10px; }
    .layout { display: block !important; }
    .main { padding: 0 !important; margin: 0 !important; }
    header, .sidebar, .sidebar-overlay { display: none !important; }
    .form-wrapper {
        width: 100%;
        padding: 4mm 5mm;
        margin: 0;
        box-shadow: none;
        border: none;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }
    .header { border-bottom-width: 2px; margin-bottom: 2mm; padding-bottom: 1.5mm; gap: 5px; }
    .header-logo { width: 28px; height: 28px; }
    .header-logo .material-symbols-outlined { font-size: 15px; }
    .header-text h1 { font-size: 14px; letter-spacing: 1px; margin: 0; }
    .rep-text { font-size: 9px; }
    .campus-sub { font-size: 9px; }
    .form-title { font-size: 12px; letter-spacing: 2px; margin: 1px 0 0; }
    .stamp { width: 40px; height: 40px; top: 4mm; right: 6mm; }
    .stamp span { font-size: 8px; }
    .top-section {
        border-color: #bbf7d0 !important;
        padding: 2mm 4mm;
        gap: 6px;
        margin-bottom: 2mm;
        font-size: 10px;
    }
    .left-column, .right-column { gap: 1px; }
    .form-row { gap: 3px; }
    .label { font-size: 9px; min-width: 65px; }
    .value { font-size: 10px; padding: 1px 2px; min-height: auto; }
    .course-section { margin: 2mm 0; }
    .section-header { font-size: 10px; padding: 1.5mm 4px; }
    table { margin-bottom: 2mm; }
    table th { font-size: 9px; padding: 1.5mm 2px; }
    table td { font-size: 10px; padding: 1.5mm 2px; height: auto; }
    table thead { background: none !important; }
    table th { color: #16a34a !important; }
    table tbody tr:nth-child(even) { background: none !important; }
    .section-header { background: none !important; color: #15803d !important; }
    .fee-section { margin-bottom: 2mm; border-width: 1px; }
    .fee-column { padding: 1.5mm 3px; font-size: 9px; }
    .fee-column-header { font-size: 9px; padding-bottom: 1px; margin-bottom: 1px; border-bottom-width: 1px; }
    .fee-item { margin-bottom: 0; padding-bottom: 0; }
    .fee-label { font-size: 9px; }
    .fee-amount { font-size: 9px; min-width: 30px; }
    .summary-title { font-size: 9px; margin-bottom: 1px; }
    .notes { font-size: 9px; padding: 1.5mm 4px; margin-top: 1mm; background: #f0fdf4 !important; border-left-color: #16a34a !important; }
    .stamp { opacity: 0.25; }
    .header-logo { background: linear-gradient(135deg, #15803d, #22c55e) !important; }
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
