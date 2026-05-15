<?php
declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_role(['admin', 'registrar']);

if (is_post()) {
    $termId = (int) ($_POST['term_id'] ?? 0);
    $enrollmentOpen = isset($_POST['enrollment_open']) ? 1 : 0;
    if ($termId > 0) {
        execute_sql('UPDATE academic_terms SET enrollment_open = :open WHERE id = :id', ['open' => $enrollmentOpen, 'id' => $termId]);
        flash('success', 'Term enrollment flag updated.');
    }
    redirect('includes/settings_term.php');
}

$terms = fetch_all(
    'SELECT t.*, ay.year_label FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Term Settings</h1>
        <p>Open or close online enrollment per academic term.</p>
    </div>
</div>
<div class="card table-wrap">
    <table>
        <thead>
            <tr><th>Academic Year</th><th>Semester</th><th>Active</th><th>Enrollment Open</th><th>Action</th></tr>
        </thead>
        <tbody>
        <?php foreach ($terms as $term): ?>
            <tr>
                <td><?= h($term['year_label']) ?></td>
                <td><?= h(semester_label((string) $term['semester'])) ?></td>
                <td><span class="badge <?= (int) $term['is_active'] === 1 ? 'success' : '' ?>"><?= (int) $term['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                <td><span class="badge <?= (int) $term['enrollment_open'] === 1 ? 'success' : 'danger' ?>"><?= (int) $term['enrollment_open'] === 1 ? 'Open' : 'Closed' ?></span></td>
                <td>
                    <form class="inline-form" method="post">
                        <input type="hidden" name="term_id" value="<?= h($term['id']) ?>">
                        <label style="margin:0; display:inline-flex; gap:8px; align-items:center;">
                            <input type="checkbox" name="enrollment_open" <?= (int) $term['enrollment_open'] === 1 ? 'checked' : '' ?>>
                            Open
                        </label>
                        <button class="btn small" type="submit">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
render_page('Term Settings', 'Term Settings', (string) ob_get_clean());
