<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';

function document_shell(string $title, string $content): void
{
    $portalName = setting('system_name', 'E-Enrollment System');
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= h($title) ?> - <?= h($portalName) ?></title>
        <link rel="stylesheet" href="<?= h(app_url('includes/style.css')) ?>">
        <style>
            @page { size: A4; margin: 15mm; }
            body.document-body { background: #f0fdf4; margin: 0; padding: 24px; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
            .document-wrapper { max-width: 210mm; margin: 0 auto; background: #fff; border: 1px solid #bbf7d0; border-radius: 12px; box-shadow: 0 8px 24px rgba(22,163,74,.1); padding: 18mm 14mm; }
            .document-actions { display: flex; gap: 10px; justify-content: flex-end; margin-bottom: 16px; }
            .doc-header { text-align: center; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 3px solid #22c55e; }
            .doc-header h1 { margin: 4px 0; font-size: 18px; color: #16a34a; }
            .doc-header p { margin: 2px 0; color: #475569; font-size: 13px; }
            .doc-header h2 { margin: 6px 0 4px; font-size: 22px; font-weight: 800; color: #1e293b; }
            .doc-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 8px 10px; margin: 14px 0; background: #f0fdf4; padding: 10px 12px; border-radius: 6px; border: 1px solid #bbf7d0; }
            .doc-grid .item { display: flex; flex-direction: column; gap: 1px; }
            .doc-grid .label { color: #15803d; font-size: 8.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.4px; }
            .doc-grid .value { font-size: 11px; font-weight: 600; color: #1e293b; }
            .doc-table { width: 100%; border-collapse: collapse; margin-top: 14px; font-size: 10px; }
            .doc-table th, .doc-table td { border: 1px solid #e2e8f0; padding: 5px 8px; text-align: left; }
            .doc-table th { background: linear-gradient(135deg, #16a34a, #22c55e); color: #fff; font-size: 9px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.3px; }
            .doc-table tbody tr:nth-child(even) { background: #f0fdf4; }
            .doc-summary { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-top: 14px; }
            .signature-block { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 24px; padding-top: 20px; border-top: 2px solid #bbf7d0; gap: 24px; }
            .signature-box { min-width: 200px; text-align: center; }
            .signature-line { border-top: 2px solid #1e293b; padding-top: 6px; margin-top: 36px; font-size: 12px; font-weight: 700; }
            .muted { color: #64748b; }
            .text-right { text-align: right; }
            .term-chip { display: inline-block; padding: 4px 10px; border-radius: 999px; background: #f0fdf4; color: #16a34a; font-weight: 600; font-size: 12px; border: 1px solid #bbf7d0; }
            @media print {
                .document-actions { display: none !important; }
                body.document-body { background: #fff; padding: 0; }
                .document-wrapper { box-shadow: none; border: 0; margin: 0; max-width: none; border-radius: 0; padding: 0; }
                .doc-table th { background: linear-gradient(135deg, #16a34a, #22c55e) !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .doc-table tbody tr:nth-child(even) { background: #f0fdf4 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
                .doc-grid { background: #f0fdf4 !important; border-color: #bbf7d0 !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            }
        </style>
    </head>
    <body class="document-body">
    <div class="document-wrapper">
        <div class="document-actions">
            <div class="doc-actions-bar">
                <button class="btn" onclick="window.print()"><span class="material-symbols-outlined" style="font-size:18px;">download</span> Print / Save PDF</button>
                <a class="btn secondary" href="javascript:history.back()">&larr; Back</a>
            </div>
        </div>
        <?= $content ?>
    </div>
    </body>
    </html>
    <?php
}

function render_registration_form_document(int $studentId, int $termId): void
{
    $data = registration_form_data($studentId, $termId);
    $student = $data['student'];
    $rows = $data['rows'];
    ob_start();
    ?>
    <div class="doc-header">
        <p>Republic of the Philippines</p>
        <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
        <p><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
        <h2>Registration Form</h2>
        <span class="term-chip"><?= h($student['year_label'] ?? '') ?> - <?= h(semester_label((string) ($student['semester'] ?? ''))) ?></span>
    </div>

    <div class="doc-grid">
        <div class="item"><div class="label">Student Number</div><div class="value"><?= h($student['student_number'] ?? '') ?></div></div>
        <div class="item"><div class="label">Student Name</div><div class="value"><?= h($student['full_name'] ?? '') ?></div></div>
        <div class="item"><div class="label">Course</div><div class="value"><?= h($student['program_code'] ?? '') ?></div></div>
        <div class="item"><div class="label">Section</div><div class="value"><?= h(($student['section_name'] ?? '') . ((string) ($student['year_level'] ?? '') !== '' ? ' (' . $student['year_level'] . ')' : '')) ?></div></div>
        <div class="item"><div class="label">Address</div><div class="value"><?= h($student['address'] ?? '') ?></div></div>
        <div class="item"><div class="label">Financial Status</div><div class="value"><?= h($data['financial']['label']) ?></div></div>
        <div class="item"><div class="label">Tuition / Unit</div><div class="value">&#8369;<?= h(format_money($data['financial']['tuition_per_unit'])) ?></div></div>
        <div class="item"><div class="label">Generated On</div><div class="value"><?= h(date('F j, Y g:i A')) ?></div></div>
    </div>

    <table class="doc-table">
        <thead>
            <tr>
                <th>#</th>
                <th>Subject Code</th>
                <th>Description</th>
                <th>Units</th>
                <th>Schedule</th>
                <th>Room</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $index => $row): ?>
            <tr>
                <td><?= $index + 1 ?></td>
                <td><?= h($row['subject_code']) ?></td>
                <td><?= h($row['subject_description']) ?></td>
                <td><?= h($row['units']) ?></td>
                <td><?= h(trim(($row['day_of_week'] ?? '') . ' ' . ($row['time_range'] ?? ''))) ?></td>
                <td><?= h($row['room'] ?? 'TBA') ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="doc-summary">
        <div>
            <table class="doc-table">
                <tr><th>Total Units</th><td class="text-right"><?= h(format_money($data['total_units'])) ?></td></tr>
                <tr><th>Tuition</th><td class="text-right">&#8369;<?= h(format_money($data['tuition'])) ?></td></tr>
                <tr><th>Other Fees</th><td class="text-right">&#8369;<?= h(format_money($data['other_fees'])) ?></td></tr>
                <tr><th>Total Amount</th><td class="text-right"><strong>&#8369;<?= h(format_money($data['total_amount'])) ?></strong></td></tr>
            </table>
        </div>
        <div>
            <table class="doc-table">
                <tr><th>Scholarship / Status</th><td><?= h($data['financial']['label']) ?></td></tr>
                <tr><th>Registrar Name</th><td><?= h(setting('registrar_name', 'Campus Registrar')) ?></td></tr>
                <tr><th>Portal</th><td><?= h(setting('system_name', 'E-Enrollment System')) ?></td></tr>
                <tr><th>Note</th><td>Your slot is final after registrar approval and payment processing.</td></tr>
            </table>
        </div>
    </div>

    $registrarSig = setting('registrar_signature', '');
    $registrarSigHtml = $registrarSig !== ''
        ? '<img src="' . h(app_url('uploads/' . $registrarSig)) . '" style="max-height:40px;margin-bottom:6px;"><br>'
        : '';

    <div class="signature-block">
        <div class="signature-box">
            <div class="signature-line">Student Signature</div>
        </div>
        <div class="signature-box">
            <div class="signature-line"><?= $registrarSigHtml ?><?= h(setting('registrar_name', 'Campus Registrar')) ?><br><span class="muted"><?= h(setting('registrar_title', 'Campus Registrar')) ?></span></div>
        </div>
    </div>
    <?php
    document_shell('Registration Form', (string) ob_get_clean());
}

function render_cog_document(int $studentId, ?int $termId = null, ?string $purpose = null): void
{
    $data = cog_data($studentId, $termId);
    $student = $data['student'];
    $purposeText = $purpose ?? setting('cog_purpose', 'For scholarship purposes only.');
    ob_start();
    ?>
    <div class="doc-header">
        <p>Republic of the Philippines</p>
        <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
        <p><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
        <h2>Certificate of Grades</h2>
        <p><?= h(date('F j, Y')) ?></p>
    </div>

    <div class="doc-grid">
        <div class="item"><div class="label">Student Number</div><div class="value"><?= h($student['student_number'] ?? '') ?></div></div>
        <div class="item"><div class="label">Student Name</div><div class="value"><?= h($student['full_name'] ?? '') ?></div></div>
        <div class="item"><div class="label">Program</div><div class="value"><?= h($student['program_code'] ?? '') ?></div></div>
        <div class="item"><div class="label">Purpose</div><div class="value"><?= h($purposeText) ?></div></div>
    </div>

    <p style="margin:12px 0 4px;font-size:13px;">To whom it may concern:</p>
    <p class="muted" style="margin:0 0 8px;font-size:12px;">This is to certify that the above-named student obtained the following grade(s) during the enrolled semesters.</p>

    <table class="doc-table">
        <thead>
            <tr>
                <th>Academic Year</th>
                <th>Semester</th>
                <th>Code</th>
                <th>Title</th>
                <th>Grade</th>
                <th>Units</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($data['rows'] as $row): ?>
            <tr>
                <td><?= h($row['year_label']) ?></td>
                <td><?= h(semester_label((string) $row['semester'])) ?></td>
                <td><?= h($row['subject_code']) ?></td>
                <td><?= h($row['subject_description']) ?></td>
                <td><?= h($row['final_grade']) ?></td>
                <td><?= h($row['units']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="doc-summary">
        <div>
            <table class="doc-table">
                <tr><th>Total Units</th><td class="text-right"><?= h(format_money($data['total_units'])) ?></td></tr>
                <tr><th>Credit Units</th><td class="text-right"><?= h(format_money($data['credit_units'])) ?></td></tr>
                <tr><th>Average</th><td class="text-right"><?= h(number_format((float) $data['average'], 2)) ?></td></tr>
            </table>
        </div>
        <div>
            <p style="font-size:12px;margin:0;"><strong><?= h($purposeText) ?></strong></p>
        </div>
    </div>

    <div class="signature-block">
        <div class="signature-box"></div>
        <div class="signature-box">
            <div class="signature-line"><?= $registrarSigHtml ?><?= h(setting('registrar_name', 'Campus Registrar')) ?><br><span class="muted"><?= h(setting('registrar_title', 'Campus Registrar')) ?></span></div>
        </div>
    </div>
    <?php
    document_shell('Certificate of Grades', (string) ob_get_clean());
}

function render_checklist_document(int $studentId): void
{
    $data = checklist_data($studentId);
    $student = $data['student'];
    ob_start();
    ?>
    <div class="doc-header">
        <p>Republic of the Philippines</p>
        <h1><?= h(setting('campus_name', 'Cavite State University Naic')) ?></h1>
        <p><?= h(setting('campus_address', 'Bucana, Naic, Cavite')) ?></p>
        <h2>Student Checklist</h2>
        <p class="muted"><?= h($student['full_name'] ?? '') ?> — <?= h($student['program_code'] ?? '') ?> — <?= h($student['student_number'] ?? '') ?></p>
    </div>

    <table class="doc-table">
        <thead>
            <tr>
                <th>Year</th>
                <th>Semester</th>
                <th>Code</th>
                <th>Description</th>
                <th>Units</th>
                <th>Grade</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($data['rows'] as $row): ?>
            <tr>
                <td><?= h($row['year_level']) ?></td>
                <td><?= h($row['semester']) ?></td>
                <td><?= h($row['subject_code']) ?></td>
                <td><?= h($row['subject_description']) ?></td>
                <td><?= h($row['units']) ?></td>
                <td><?= h($row['grade'] ?? '-') ?></td>
                <td><?= h($row['status']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    $registrarSig = setting('registrar_signature', '');
    $registrarSigHtml = $registrarSig !== ''
        ? '<img src="' . h(app_url('uploads/' . $registrarSig)) . '" style="max-height:40px;margin-bottom:6px;"><br>'
        : '';

    <div class="signature-block">
        <div class="signature-box"></div>
        <div class="signature-box">
            <div class="signature-line"><?= $registrarSigHtml ?><?= h(setting('registrar_name', 'Campus Registrar')) ?><br><span class="muted"><?= h(setting('registrar_title', 'Campus Registrar')) ?></span></div>
        </div>
    </div>
    <?php
    document_shell('Student Checklist', (string) ob_get_clean());
}
