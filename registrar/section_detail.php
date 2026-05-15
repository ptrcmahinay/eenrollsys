<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$sectionId = (int)($_GET['id'] ?? 0);

$section = fetch_one(
    'SELECT sec.*, p.program_code, p.program_name, d.department_code,
            s.full_name AS adviser_name
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     INNER JOIN departments d ON d.dept_id = p.department_id
     LEFT JOIN staff s ON s.staff_id = sec.adviser_id
     WHERE sec.id = :id',
    ['id' => $sectionId]
);

if (!$section) {
    flash('error', 'Section not found.');
    redirect('registrar/departments.php');
}

/* =========================
   STUDENTS IN THIS SECTION
========================= */

$students = fetch_all(
    'SELECT 
        st.id,
        st.student_number,
        st.full_name,
        st.year_level,
        st.address,
        p.program_code,
        sec.section_name
     FROM students st
     INNER JOIN sections sec ON sec.id = st.section_id
     INNER JOIN programs p ON p.programs_id = sec.program_id
     WHERE st.section_id = :section_id
     ORDER BY st.full_name',
    ['section_id' => $sectionId]
);

$totalStudents = count($students);
$maxSlots = (int)($section['max_slots'] ?? 0);

ob_start();
?>

<div class="page-header">
    <div>
        <h1><?= h($section['program_code'] . ' ' . $section['year_level'] . '-' . $section['section_name']) ?></h1>
        <p><?= h($section['program_name']) ?> • <?= h($section['department_code']) ?></p>
    </div>
</div>

<div class="grid">

    <!-- SECTION INFO -->
    <div class="card">
        <h3>Section Info</h3>

        <p><strong>Adviser:</strong> <?= $section['adviser_name'] ? h($section['adviser_name']) : 'None' ?></p>
        <p><strong>Slots:</strong> <?= $totalStudents ?> / <?= $maxSlots ?: '∞' ?></p>
        <p><strong>Status:</strong> <?= h($section['status'] ?? 'active') ?></p>
    </div>

    <!-- STUDENTS LIST -->
    <div class="card">
        <h3>Enrolled Students</h3>

        <?php if (empty($students)): ?>
            <p class="helper">No students enrolled in this section.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Student No</th>
                            <th>Name</th>
                            <th>Program</th>
                            <th>Year / Section</th>
                            <th>Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students as $st): ?>
                            <tr>
                              <td><?= h($st['student_number']) ?></td>
                              <td><?= h($st['full_name']) ?></td>
                              <td><?= h($st['program_code']) ?></td>
                              <td><?= h($st['year_level'] . '-' . $st['section_name']) ?></td>
                              <td><?= h($st['address']) ?></td>
                          </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php
render_page('Section Detail', 'Section Detail', ob_get_clean());