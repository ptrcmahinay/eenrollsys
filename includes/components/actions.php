<?php
declare(strict_types=1);

/**
 * Reusable row-action button cluster for data tables.
 *
 * Renders View / Edit / Delete buttons. Any of them can be skipped by
 * passing null. Edit can either be a URL or a modal id (data-open).
 *
 * @param array{
 *   view_url?: ?string,
 *   edit_url?: ?string,
 *   edit_modal?: ?string,
 *   edit_data?: ?array<string,string>,
 *   delete_action?: ?string,        // POST `action` value
 *   delete_id_field?: ?string,      // POST field name carrying the id
 *   delete_id?: ?(string|int),      // id value
 *   delete_url?: ?string,           // optional override for form action
 *   delete_confirm?: ?string,       // confirm text
 * } $opts
 */
function render_row_actions(array $opts): string
{
    $html = '<div class="row-actions">';

    if (!empty($opts['view_url'])) {
        $html .= '<a class="icon-btn" title="View" aria-label="View" href="'
              . h($opts['view_url']) . '">'
              . '<span class="material-symbols-outlined">visibility</span></a>';
    }

    if (!empty($opts['edit_url'])) {
        $html .= '<a class="icon-btn" title="Edit" aria-label="Edit" href="'
              . h($opts['edit_url']) . '">'
              . '<span class="material-symbols-outlined">edit</span></a>';
    } elseif (!empty($opts['edit_modal'])) {
        $extras = '';
        if (!empty($opts['edit_data']) && is_array($opts['edit_data'])) {
            foreach ($opts['edit_data'] as $k => $v) {
                $extras .= ' data-' . h($k) . '="' . h((string) $v) . '"';
            }
        }
        $html .= '<button type="button" class="icon-btn" title="Edit" aria-label="Edit"'
              . ' data-open="' . h($opts['edit_modal']) . '"' . $extras . '>'
              . '<span class="material-symbols-outlined">edit</span></button>';
    }

    if (!empty($opts['delete_action']) && !empty($opts['delete_id_field']) && isset($opts['delete_id'])) {
        $confirm = $opts['delete_confirm'] ?? 'Mark this record as inactive?';
        $action = $opts['delete_url'] ?? '';
        $html .= '<form method="post" class="inline-form" action="' . h($action) . '"'
              . ' onsubmit="return confirm(' . json_encode($confirm) . ');" style="display:inline;">'
              . '<input type="hidden" name="action" value="' . h($opts['delete_action']) . '">'
              . '<input type="hidden" name="' . h($opts['delete_id_field']) . '" value="' . h((string) $opts['delete_id']) . '">'
              . '<button type="submit" class="icon-btn danger" title="Delete" aria-label="Delete">'
              . '<span class="material-symbols-outlined">delete</span></button>'
              . '</form>';
    }

    $html .= '</div>';
    return $html;
}

/**
 * Soft-delete helper: sets status='inactive' on the given table for the given id.
 * Falls back to a regular UPDATE; the SQL patch ensures the column exists.
 */
function soft_delete(string $table, string $idColumn, int $id): bool
{
    if ($id <= 0) return false;
    // Whitelist tables to avoid SQL injection via $table parameter.
    $allowed = [
        'students', 'staff', 'users', 'departments', 'programs',
        'sections', 'subjects', 'program_curriculum',
        'academic_terms', 'academic_years', 'section_subject_offerings',
    ];
    if (!in_array($table, $allowed, true)) return false;
    $allowedIds = [
        'id', 'staff_id', 'users_id', 'dept_id', 'programs_id',
        'subject_id', 'curriculum_id',
    ];
    if (!in_array($idColumn, $allowedIds, true)) return false;
    $sql = "UPDATE `{$table}` SET `status` = 'inactive' WHERE `{$idColumn}` = :id";
    return execute_sql($sql, ['id' => $id]);
}

/**
 * Bulk-soft-delete: sets status='inactive' on multiple rows.
 * Returns number of affected rows or 0 on failure.
 */
function bulk_soft_delete(string $table, string $idColumn, array $ids): int
{
    $allowed = [
        'students', 'staff', 'users', 'departments', 'programs',
        'sections', 'subjects', 'program_curriculum',
        'academic_terms', 'academic_years', 'section_subject_offerings',
    ];
    if (!in_array($table, $allowed, true)) return 0;
    $allowedIds = [
        'id', 'staff_id', 'users_id', 'dept_id', 'programs_id',
        'subject_id', 'curriculum_id',
    ];
    if (!in_array($idColumn, $allowedIds, true)) return 0;

    $ints = array_filter(array_map('intval', $ids), fn($v) => $v > 0);
    if (empty($ints)) return 0;

    $placeholders = implode(',', array_fill(0, count($ints), ':id' . array_search($ints[0], $ints, true) !== false ? '' : ''));
    $params = [];
    foreach ($ints as $i => $v) {
        $params['id' . $i] = $v;
    }
    $ph = implode(',', array_keys($params));
    $ph = implode(',', array_map(fn($k) => ":$k", array_keys($params)));
    $sql = "UPDATE `{$table}` SET `status` = 'inactive' WHERE `{$idColumn}` IN ({$ph})";
    return execute_sql($sql, $params) ? count($ints) : 0;
}

/**
 * Process bulk delete POST request. Checks action, ids, then delegates to bulk_soft_delete.
 * Call this at the top of any page that supports bulk deletion.
 */
function handle_bulk_delete_post(string $table, string $idColumn, string $redirectUrl = null): bool
{
    if (
        ($_SERVER['REQUEST_METHOD'] === 'POST') &&
        !empty($_POST['action']) &&
        $_POST['action'] === 'bulk_delete' &&
        !empty($_POST['ids']) &&
        is_array($_POST['ids'])
    ) {
        $count = bulk_soft_delete($table, $idColumn, $_POST['ids']);
        if ($redirectUrl) {
            $key = $count > 0 ? 'success' : 'error';
            $msg = $count > 0
                ? "{$count} record(s) deleted successfully."
                : "No records were deleted.";
            flash($key, $msg);
            redirect($redirectUrl);
        }
        return $count > 0;
    }
    return false;
}
