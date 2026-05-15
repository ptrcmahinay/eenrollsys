<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role('chair');

$staff = current_staff();
if ($staff === null) {
    flash('error', 'Staff profile not found.');
    redirect('auth/logout.php');
}

if (is_post()) {
    $sectionId = (int) ($_POST['section_id'] ?? 0);
    $adviserId = (int) ($_POST['adviser_id'] ?? 0);
    if ($sectionId > 0 && $adviserId > 0) {
        execute_sql('UPDATE sections SET adviser_id = :adviser_id WHERE id = :section_id', [
            'adviser_id' => $adviserId,
            'section_id' => $sectionId,
        ]);
        flash('success', 'Adviser assigned to section.');
    }
    redirect('chair/assign_adviser.php');
}

$sections = fetch_all(
    'SELECT sec.id, sec.year_level, sec.section_name, p.program_code, st.full_name AS adviser_name
     FROM sections sec
     INNER JOIN programs p ON p.programs_id = sec.program_id
     LEFT JOIN staff st ON st.staff_id = sec.adviser_id
     WHERE p.department_id = :department_id
     ORDER BY sec.year_level, sec.section_name',
    ['department_id' => (int) $staff['dept_id']]
);

$advisers = fetch_all(
    'SELECT st.staff_id, st.full_name
     FROM staff st
     INNER JOIN user_roles ur ON ur.user_id = st.users_id
     INNER JOIN roles r ON r.roles_id = ur.role_id AND r.role_name = "adviser"
     WHERE st.dept_id = :department_id
     ORDER BY st.full_name',
    ['department_id' => (int) $staff['dept_id']]
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Assign Adviser per Section</h1>
        <p>Only adviser accounts under the same department are available for selection.</p>
    </div>
</div>
<div class="card">
    <div class="dt" data-dt-page-size="10">
<div class="table-wrap">
        <table>
            <thead><tr><th>Section</th><th>Current Adviser</th><th data-dt-no-sort>Assign Adviser</th></tr></thead>
            <tbody>
            <?php foreach ($sections as $section): ?>
                <tr>
                    <td><?= h($section['program_code'] . ' ' . $section['year_level'] . $section['section_name']) ?></td>
                    <td><?= h($section['adviser_name'] ?: 'Unassigned') ?></td>
                    <td>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="section_id" value="<?= h($section['id']) ?>">
                            <select name="adviser_id" required>
                                <?php foreach ($advisers as $adviser): ?>
                                    <option value="<?= h($adviser['staff_id']) ?>"><?= h($adviser['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn small" type="submit">Assign</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</div>
<?php
render_page('Assign Adviser', 'Assign Adviser', (string) ob_get_clean());
