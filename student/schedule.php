<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_once __DIR__ . '/../includes/components/modal.php';
require_role('student');

$student = current_student();
$currentTerm = current_term();
if ($student === null) {
    flash('error', 'Student profile not found.');
    redirect('auth/logout.php');
}

$termLabel = '';
$rows = [];
if ($currentTerm !== null) {
    $termLabel = (($currentTerm['year_label'] ?? '') . ' / ' . semester_label((string) ($currentTerm['semester'] ?? '')));
    $rows = fetch_all(
        'SELECT ss.offering_id, ss.section_id, ss.subject_id, ss.units,
                COALESCE(new_cs.schedule_code_id, sc.id, sc2.id) AS sched_code_id,
                sub.subject_code, sub.subject_description,
                sec.section_name, sec.year_level, p.program_code, p.program_name,
                COALESCE(new_st.full_name, offst.full_name) AS offering_instructor_name
         FROM student_subjects ss
         LEFT JOIN schedule_codes sc ON sc.id = ss.schedule_code_id
         LEFT JOIN schedule_codes sc2 ON sc2.offering_id = ss.offering_id
         LEFT JOIN class_schedules new_cs ON new_cs.id = ss.schedule_id
         INNER JOIN subjects sub ON sub.subject_id = ss.subject_id
         LEFT JOIN sections sec ON sec.id = ss.section_id
         LEFT JOIN programs p ON p.programs_id = sec.program_id
         LEFT JOIN section_subject_offerings o ON o.id = ss.offering_id
         LEFT JOIN staff offst ON offst.staff_id = o.instructor_id
         LEFT JOIN staff new_st ON new_st.staff_id = new_cs.instructor_id
         WHERE ss.student_id = :sid AND ss.term_id = :tid AND ss.enrollment_status = "enrolled"
         ORDER BY sub.subject_code, p.program_code, sec.year_level, sec.section_name',
        ['sid' => (int) $student['id'], 'tid' => (int) $currentTerm['id']]
    );
}

$groups = [];
foreach ($rows as $r) {
    $oid = (int) $r['offering_id'];
    if (!isset($groups[$oid])) {
        $groups[$oid] = [
            'subject_code' => (string) $r['subject_code'],
            'subject_description' => (string) $r['subject_description'],
            'units' => (string) $r['units'],
            'section_label' => trim(($r['program_code'] ?? '') . ' ' . ($r['year_level'] ?? '') . ($r['section_name'] ?? '')),
            'sched_code_id' => (int) ($r['sched_code_id'] ?? 0),
            'offering_instructor_name' => (string) ($r['offering_instructor_name'] ?? ''),
            'entries' => [],
        ];
    }
}

$schedCodeIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int) ($r['sched_code_id'] ?? 0), $rows), static fn($v) => $v > 0)));
$schedMap = [];
if ($schedCodeIds !== []) {
    $placeholders = implode(',', array_fill(0, count($schedCodeIds), '?'));
    $entries = fetch_all(
        "SELECT cs.schedule_code_id, cs.id AS schedule_id, cs.day, cs.start_time, cs.end_time, cs.room, cs.instructor_id,
                st.full_name AS instructor_name
         FROM class_schedules cs
         LEFT JOIN staff st ON st.staff_id = cs.instructor_id
         WHERE cs.schedule_code_id IN ($placeholders)
         ORDER BY FIELD(cs.day, 'Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'), cs.start_time",
        $schedCodeIds
    );
    foreach ($entries as $e) {
        $schedMap[(int) $e['schedule_code_id']][] = $e;
    }
}

$totalMeetings = 0;
$totalScheduledSubjects = 0;
$timetableEntries = [];
foreach ($groups as &$g) {
    $g['entries'] = $schedMap[$g['sched_code_id']] ?? [];
    if ($g['entries'] !== []) {
        $totalScheduledSubjects++;
        $totalMeetings += count($g['entries']);
    }
    foreach ($g['entries'] as $e) {
        $instructor = !empty($e['instructor_name']) ? $e['instructor_name'] : $g['offering_instructor_name'];
        $timetableEntries[] = [
            'day' => (string) $e['day'],
            'start_time' => (string) $e['start_time'],
            'end_time' => (string) $e['end_time'],
            'subject_code' => (string) $g['subject_code'],
            'subject_description' => (string) $g['subject_description'],
            'instructor_name' => (string) $instructor,
            'room' => (string) $e['room'],
            'section_label' => (string) $g['section_label'],
        ];
    }
}
unset($g);

$totalSubjects = count($groups);

$flashes = get_flashes();

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Class Schedule</h1>
        <p><?= h(trim($student['full_name'] . ' — ' . $student['program_code'])) ?> • <?= h($termLabel) ?></p>
    </div>
    <button class="btn secondary" type="button" data-open="modal-student-weekly">
        <span class="material-symbols-outlined" style="font-size:18px;vertical-align:middle;margin-right:4px;">calendar_view_week</span>
        Weekly Schedule
    </button>
</div>

<?php if ($flashes !== []): ?>
    <div class="flash-stack">
        <?php foreach ($flashes as $flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="grid cols-3" style="margin-bottom:16px;">
    <div class="card slim">
        <div class="metric"><?= h((string)$totalSubjects) ?></div>
        <div class="metric-label">Enrolled Subjects</div>
    </div>
    <div class="card slim">
        <div class="metric"><?= h((string)$totalScheduledSubjects) ?></div>
        <div class="metric-label">Scheduled Subjects</div>
    </div>
    <div class="card slim">
        <div class="metric"><?= h((string)$totalMeetings) ?></div>
        <div class="metric-label">Weekly Meetings</div>
    </div>
</div>

<div class="card">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
        <h3 style="margin:0;">My Subjects &amp; Schedules</h3>
        <span class="badge info"><?= h((string)$totalSubjects) ?> subject(s)</span>
    </div>

    <?php if ($groups === []): ?>
        <p class="helper" style="text-align:center;padding:24px;">You have no enrolled subjects for this term.</p>
    <?php else: ?>
    <div class="dt" data-dt-page-size="10">
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Course Code</th>
                        <th>Subject</th>
                        <th>Units</th>
                        <th>Section</th>
                        <th>Schedule</th>
                        <th>Instructor</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($groups as $g): ?>
                    <tr>
                        <td><span class="badge" style="font-family:monospace;"><?= h($g['subject_code']) ?></span></td>
                        <td><strong><?= h($g['subject_description']) ?></strong></td>
                        <td><?= h($g['units']) ?></td>
                        <td><?= h($g['section_label'] ?: '—') ?></td>
                        <td>
                            <?php if ($g['entries'] === []): ?>
                                <span class="badge warning">Not Scheduled</span>
                            <?php else: ?>
                                <?php foreach ($g['entries'] as $e): ?>
                                    <div class="schedule-entry">
                                        <span class="schedule-chip">
                                            <?= h(($e['day'] ? substr((string) $e['day'], 0, 3) : '—') . ' ' . format_time_range((string) $e['start_time'], (string) $e['end_time'])) ?>
                                            <?php if (!empty($e['room'])): ?><span class="helper"> · <?= h($e['room']) ?></span><?php endif; ?>
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $instr = '';
                            foreach ($g['entries'] as $e) {
                                if (!empty($e['instructor_name'])) { $instr = $e['instructor_name']; break; }
                            }
                            echo h($instr !== '' ? $instr : ($g['offering_instructor_name'] !== '' ? $g['offering_instructor_name'] : '—'));
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php
$weeklyModalInner = render_weekly_timetable($timetableEntries);
$weeklyModalInner .= '
<div style="text-align:center;margin-top:12px;display:flex;gap:8px;justify-content:center;">
    <button class="btn small" type="button" onclick="downloadTimetableImg()">
        <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">image</span> Download Image
    </button>
    <button class="btn small secondary" type="button" onclick="downloadTimetablePdf()">
        <span class="material-symbols-outlined" style="font-size:16px;vertical-align:middle;">picture_as_pdf</span> Download PDF
    </button>
</div>';
$weeklyModal = render_modal(
    'modal-student-weekly',
    h('Weekly Schedule — ' . trim($student['full_name'])),
    $weeklyModalInner,
    true
);

$studentName = h($student['full_name']);
$termLabelJs = h($termLabel);

$downloadJs = <<<SCRIPT
<script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
<script>
function getTimetableElement() {
    var modal = document.getElementById("modal-student-weekly");
    return modal ? modal.querySelector(".weekly-timetable") || modal.querySelector(".table-wrap") : null;
}

function downloadTimetablePdf() {
    var el = getTimetableElement();
    if (!el) { alert("No timetable to download."); return; }
    html2canvas(el, { scale: 2, useCORS: true }).then(function(canvas) {
        var imgData = canvas.toDataURL("image/png");
        var imgW = canvas.width;
        var imgH = canvas.height;
        var pdfW = 297;
        var pdfH = (imgH / imgW) * pdfW;
        var pdf = new jspdf.jsPDF({ orientation: "landscape", unit: "mm", format: [pdfW, pdfH + 30] });
        pdf.setFontSize(14);
        pdf.text("{$studentName} - Class Schedule", 10, 10);
        pdf.setFontSize(10);
        pdf.text("{$termLabelJs}", 10, 16);
        pdf.addImage(imgData, "PNG", 0, 22, pdfW, pdfH);
        pdf.save("class_schedule.pdf");
    });
}

function downloadTimetableImg() {
    var el = getTimetableElement();
    if (!el) { alert("No timetable to download."); return; }
    html2canvas(el, { scale: 2, useCORS: true }).then(function(canvas) {
        var link = document.createElement("a");
        link.download = "class_schedule.png";
        link.href = canvas.toDataURL("image/png");
        link.click();
    });
}
</script>
SCRIPT;

render_page(
    'Class Schedule',
    'Class Schedule',
    (string) ob_get_clean(),
    ['modals' => [$weeklyModal], 'extra_js' => $downloadJs]
);
