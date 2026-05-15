<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

if (is_post()) {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create_year') {
        $startYear = (int) ($_POST['start_year'] ?? date('Y'));
        $endYear = $startYear + 1;
        $label = $startYear . '-' . $endYear;
        execute_sql(
            'INSERT INTO academic_years (year_label, start_year, end_year, is_active, created_at) VALUES (:label, :start_year, :end_year, 0, NOW())',
            ['label' => $label, 'start_year' => $startYear, 'end_year' => $endYear]
        );
        flash('success', 'Academic year created.');
    }

    if ($action === 'create_term') {
        execute_sql(
            'INSERT INTO academic_terms (academic_year_id, semester, is_active, enrollment_open, start_date, end_date, created_at, updated_at)
             VALUES (:academic_year_id, :semester, 0, :enrollment_open, :start_date, :end_date, NOW(), NOW())',
            [
                'academic_year_id' => (int) ($_POST['academic_year_id'] ?? 0),
                'semester' => trim($_POST['semester'] ?? '1'),
                'enrollment_open' => isset($_POST['enrollment_open']) ? 1 : 0,
                'start_date' => $_POST['start_date'] ?? null,
                'end_date' => $_POST['end_date'] ?? null,
            ]
        );
        flash('success', 'Academic term created.');
    }

    if ($action === 'activate_term') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        db()->beginTransaction();
        try {
            execute_sql('UPDATE academic_terms SET is_active = 0');
            execute_sql('UPDATE academic_years SET is_active = 0');
            execute_sql('UPDATE academic_terms SET is_active = 1 WHERE id = :id', ['id' => $termId]);
            $yearId = fetch_one('SELECT academic_year_id FROM academic_terms WHERE id = :id', ['id' => $termId]);
            if ($yearId !== null) {
                execute_sql('UPDATE academic_years SET is_active = 1 WHERE id = :id', ['id' => (int) $yearId['academic_year_id']]);
            }
            db()->commit();
            flash('success', 'Active term updated.');
        } catch (Throwable $throwable) {
            db()->rollBack();
            throw $throwable;
        }
    }

    if ($action === 'toggle_open') {
        $termId = (int) ($_POST['term_id'] ?? 0);
        $open = isset($_POST['enrollment_open']) ? 1 : 0;
        execute_sql('UPDATE academic_terms SET enrollment_open = :open WHERE id = :id', ['open' => $open, 'id' => $termId]);
        flash('success', 'Enrollment availability updated.');
    }

    redirect('registrar/academic_term.php');
}

$years = fetch_all('SELECT * FROM academic_years ORDER BY start_year DESC');
$terms = fetch_all(
    'SELECT t.*, ay.year_label FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id ORDER BY ay.start_year DESC, FIELD(t.semester, "1", "2", "mid")'
);

ob_start();
?>
<div class="page-header">
    <div>
        <h1>Academic Year & Term Management</h1>
        <p>Admin and registrar can create years, create terms, activate the current term, and open or close online enrollment.</p>
    </div>
</div>

<div class="grid cols-2">
    <div class="card">
        <h3>Create academic year</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_year">
            <div class="form-grid">
                <div>
                    <label>Start Year</label>
                    <input type="number" name="start_year" value="<?= h((string) date('Y')) ?>" required>
                </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Create Academic Year</button></div>
        </form>
    </div>
    <div class="card">
        <h3>Create academic term</h3>
        <form method="post">
            <input type="hidden" name="action" value="create_term">
            <div class="form-grid cols-3">
                <div>
                    <label>Academic Year</label>
                    <select name="academic_year_id" required>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= h($year['id']) ?>"><?= h($year['year_label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label>Semester</label>
                    <select name="semester">
                        <option value="1">First Semester</option>
                        <option value="2">Second Semester</option>
                        <option value="mid">Midyear</option>
                    </select>
                </div>
                <div>
                    <label>Enrollment Open</label>
                    <label style="margin-top: 12px; display:inline-flex; align-items:center; gap:8px;"><input type="checkbox" name="enrollment_open" checked> Open online enrollment</label>
                </div>
                <div>
                    <label>Start Date</label>
                    <input type="date" name="start_date">
                </div>
                <div>
                    <label>End Date</label>
                    <input type="date" name="end_date">
                </div>
            </div>
            <div class="form-actions"><button class="btn" type="submit">Create Term</button></div>
        </form>
    </div>
</div>

<div class="card" style="margin-top: 16px;">
    <h3>Existing terms</h3>
    <div class="dt" data-dt-page-size="10">
<div class="table-wrap">
        <table>
            <thead><tr><th>Academic Year</th><th>Semester</th><th>Active</th><th>Enrollment</th><th>Dates</th><th data-dt-no-sort>Actions</th></tr></thead>
            <tbody>
            <?php foreach ($terms as $term): ?>
                <tr>
                    <td><?= h($term['year_label']) ?></td>
                    <td><?= h(semester_label((string) $term['semester'])) ?></td>
                    <td><span class="badge <?= (int) $term['is_active'] === 1 ? 'success' : '' ?>"><?= (int) $term['is_active'] === 1 ? 'Active' : 'Inactive' ?></span></td>
                    <td><span class="badge <?= (int) $term['enrollment_open'] === 1 ? 'success' : 'danger' ?>"><?= (int) $term['enrollment_open'] === 1 ? 'Open' : 'Closed' ?></span></td>
                    <td><?= h(($term['start_date'] ?: '-') . ' to ' . ($term['end_date'] ?: '-')) ?></td>
                    <td>
                        <form class="inline-form" method="post">
                            <input type="hidden" name="term_id" value="<?= h($term['id']) ?>">
                            <button class="btn small secondary" type="submit" name="action" value="activate_term">Set Active</button>
                            <label style="display:inline-flex; align-items:center; gap:6px; margin:0 6px;">
                                <input type="checkbox" name="enrollment_open" <?= (int) $term['enrollment_open'] === 1 ? 'checked' : '' ?>> Open
                            </label>
                            <button class="btn small" type="submit" name="action" value="toggle_open">Save Open/Close</button>
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
render_page('Academic Term', 'Academic Term', (string) ob_get_clean());
