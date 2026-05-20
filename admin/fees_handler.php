<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/app.php';
require_role(['admin', 'registrar']);

$action = trim((string) ($_POST['action'] ?? $_GET['action'] ?? ''));

$redirectParams = [];
$programId = isset($_POST['program_id']) ? (int) $_POST['program_id'] : (isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0);
$semester   = trim((string) ($_POST['semester'] ?? $_GET['semester'] ?? ''));

if ($programId > 0) $redirectParams['program_id'] = $programId;
if ($semester !== '') $redirectParams['semester'] = $semester;

function redirectBack(): void
{
    global $redirectParams;
    $qs = http_build_query($redirectParams);
    redirect('admin/fees.php' . ($qs ? '?' . $qs : ''));
}

function jsonResponse(array $data): void
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

switch ($action) {
    case 'add':
        $category     = trim((string) ($_POST['category'] ?? ''));
        $feeName      = trim((string) ($_POST['fee_name'] ?? ''));
        $amount       = (float) ($_POST['amount'] ?? 0);
        $programIdFk  = !empty($_POST['program_id_fk']) ? (int) $_POST['program_id_fk'] : null;
        $yearLevel    = !empty($_POST['year_level']) ? (int) $_POST['year_level'] : null;
        $semesterFilt = trim((string) ($_POST['semester_filter'] ?? ''));
        $isMandatory  = !empty($_POST['is_mandatory']) ? 1 : 0;

        if (!in_array($category, ['laboratory', 'other', 'assessment'], true)) {
            flash('error', 'Invalid fee category.');
            redirectBack();
        }

        if ($feeName === '' || $amount < 0) {
            flash('error', 'Please provide a valid fee name and amount.');
            redirectBack();
        }

        execute_sql(
            'INSERT INTO fee_items (category, fee_name, amount, program_id, year_level, semester, is_mandatory)
             VALUES (:category, :fee_name, :amount, :program_id, :year_level, :semester, :is_mandatory)',
            [
                'category'     => $category,
                'fee_name'     => $feeName,
                'amount'       => $amount,
                'program_id'   => $programIdFk,
                'year_level'   => $yearLevel,
                'semester'     => $semesterFilt ?: null,
                'is_mandatory' => $isMandatory,
            ]
        );

        flash('success', 'Fee item "' . h($feeName) . '" added successfully.');
        redirectBack();
        break;

    case 'edit':
        $id           = (int) ($_POST['id'] ?? 0);
        $category     = trim((string) ($_POST['category'] ?? ''));
        $feeName      = trim((string) ($_POST['fee_name'] ?? ''));
        $amount       = (float) ($_POST['amount'] ?? 0);
        $programIdFk  = !empty($_POST['program_id_fk']) ? (int) $_POST['program_id_fk'] : null;
        $yearLevel    = !empty($_POST['year_level']) ? (int) $_POST['year_level'] : null;
        $semesterFilt = trim((string) ($_POST['semester_filter'] ?? ''));
        $isMandatory  = !empty($_POST['is_mandatory']) ? 1 : 0;

        if ($id <= 0) {
            flash('error', 'Invalid fee item.');
            redirectBack();
        }

        if (!in_array($category, ['laboratory', 'other', 'assessment'], true)) {
            flash('error', 'Invalid fee category.');
            redirectBack();
        }

        if ($feeName === '' || $amount < 0) {
            flash('error', 'Please provide a valid fee name and amount.');
            redirectBack();
        }

        execute_sql(
            'UPDATE fee_items SET category = :category, fee_name = :fee_name, amount = :amount,
             program_id = :program_id, year_level = :year_level, semester = :semester,
             is_mandatory = :is_mandatory WHERE id = :id',
            [
                'id'           => $id,
                'category'     => $category,
                'fee_name'     => $feeName,
                'amount'       => $amount,
                'program_id'   => $programIdFk,
                'year_level'   => $yearLevel,
                'semester'     => $semesterFilt ?: null,
                'is_mandatory' => $isMandatory,
            ]
        );

        flash('success', 'Fee item "' . h($feeName) . '" updated successfully.');
        redirectBack();
        break;

    case 'delete':
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            flash('error', 'Invalid fee item.');
            redirectBack();
        }

        execute_sql('DELETE FROM fee_items WHERE id = :id', ['id' => $id]);
        flash('success', 'Fee item deleted successfully.');
        redirectBack();
        break;

    case 'verify':
        $progId  = (int) ($_GET['program_id'] ?? 0);
        $sem     = trim((string) ($_GET['semester'] ?? ''));

        if ($progId <= 0) {
            jsonResponse(['success' => false, 'message' => 'No program selected.']);
        }

        $params = ['program_id' => $progId];
        $sql = "SELECT category, SUM(amount) AS total, COUNT(*) AS count
                FROM fee_items
                WHERE is_active = 1
                  AND (program_id = :program_id OR program_id IS NULL)";

        if ($sem !== '') {
            $sql .= " AND (semester = :semester OR semester IS NULL)";
            $params['semester'] = $sem;
        }

        $sql .= " GROUP BY category ORDER BY FIELD(category, 'laboratory','other','assessment')";

        $rows = fetch_all($sql, $params);

        if ($rows === []) {
            jsonResponse(['success' => false, 'message' => 'No fees found for the selected criteria.']);
        }

        $totals = ['laboratory' => 0, 'other' => 0, 'assessment' => 0];
        $counts = ['laboratory' => 0, 'other' => 0, 'assessment' => 0];
        foreach ($rows as $row) {
            $totals[$row['category']] = (float) $row['total'];
            $counts[$row['category']] = (int) $row['count'];
        }

        $grandTotal = $totals['laboratory'] + $totals['other'] + $totals['assessment'];

        jsonResponse([
            'success'    => true,
            'grand_total' => format_money($grandTotal),
            'laboratory' => format_money($totals['laboratory']),
            'other'      => format_money($totals['other']),
            'assessment' => format_money($totals['assessment']),
            'counts'     => $counts,
        ]);
        break;

    default:
        flash('error', 'Unknown action.');
        redirectBack();
        break;
}
