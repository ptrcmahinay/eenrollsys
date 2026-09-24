<?php
declare(strict_types=1);

/**
 * Fee Calculation Engine
 *
 * Single source of truth for computing enrollment fee assessments.
 * Replaces scattered fee logic in calculate_request_totals(), registration_form_data(), etc.
 */

/**
 * Compute the complete fee assessment for a student's enrollment.
 *
 * Returns an array of fee line items, each with:
 *   fee_item_id, fee_name, category, calculation_type,
 *   quantity, rate, gross_amount, discount_amount, net_amount, notes
 */
function compute_enrollment_fees(
    int $studentId,
    array $offeringIds,
    int $termId
): array {
    $student = fetch_one('SELECT * FROM students WHERE id = :id', ['id' => $studentId])
        ?? ['entry_year' => date('Y'), 'ra10931_override' => 'auto', 'program_id' => 0, 'year_level' => 1];

    $term = fetch_one(
        'SELECT t.*, ay.year_label FROM academic_terms t INNER JOIN academic_years ay ON ay.id = t.academic_year_id WHERE t.id = :tid',
        ['tid' => $termId]
    );
    $semester = $term ? (string) $term['semester'] : '1';
    $programId = (int) $student['program_id'];
    $yearLevel = (int) $student['year_level'];

    // Compute totals from selected offerings
    $totalUnits = 0.0;
    $labCredits = 0.0;
    if ($offeringIds !== []) {
        $ph = implode(',', array_fill(0, count($offeringIds), '?'));
        $stmt = db()->prepare(
            "SELECT SUM(sub.lec_credit + sub.lab_credit) AS total_units,
                    SUM(sub.lab_credit) AS lab_credits
             FROM section_subject_offerings o
             INNER JOIN subjects sub ON sub.subject_id = o.subject_id
             WHERE o.id IN ($ph)"
        );
        $stmt->execute($offeringIds);
        $row = $stmt->fetch();
        $totalUnits = (float) ($row['total_units'] ?? 0);
        $labCredits = (float) ($row['lab_credits'] ?? 0);
    }

    // Load all matching fee items
    $feeItems = fetch_all(
        'SELECT id, category, fee_name, amount, calculation_type, is_mandatory
         FROM fee_items
         WHERE is_active = 1
           AND (program_id IS NULL OR program_id = :pid)
           AND (year_level IS NULL OR year_level = :yl)
           AND (semester IS NULL OR semester = :sem)
         ORDER BY FIELD(category, "assessment","laboratory","other"), is_mandatory DESC, fee_name',
        ['pid' => $programId, 'yl' => $yearLevel, 'sem' => $semester]
    );

    $assessments = [];

    foreach ($feeItems as $fi) {
        $calcType = $fi['calculation_type'] ?? 'fixed';
        $rate = (float) $fi['amount'];
        $gross = 0.0;
        $quantity = 1.0;
        $notes = null;

        switch ($calcType) {
            case 'per_unit':
                $quantity = $totalUnits;
                $gross = $totalUnits * $rate;
                $notes = $totalUnits . ' units × ' . format_money($rate) . '/unit';
                break;
            case 'per_lab_unit':
                $quantity = $labCredits;
                $gross = $labCredits * $rate;
                $notes = $labCredits . ' lab units × ' . format_money($rate) . '/lab unit';
                break;
            case 'per_subject':
                $quantity = count($offeringIds);
                $gross = count($offeringIds) * $rate;
                $notes = count($offeringIds) . ' subjects × ' . format_money($rate);
                break;
            case 'fixed':
            case 'per_student':
            default:
                $quantity = 1.0;
                $gross = $rate;
                $notes = 'Fixed fee';
                break;
        }

        $assessments[] = [
            'fee_item_id'      => (int) $fi['id'],
            'fee_name'         => $fi['fee_name'],
            'category'         => $fi['category'],
            'calculation_type' => $calcType,
            'quantity'         => $quantity,
            'rate'             => $rate,
            'gross_amount'     => round($gross, 2),
            'discount_amount'  => 0.0,
            'net_amount'       => round($gross, 2),
            'notes'            => $notes,
        ];
    }

    // Apply scholarship adjustments from scholarship engine
    $adjustments = calculate_scholarship_adjustments($studentId, $assessments, $term);
    $fheEligibility = check_fhe_eligibility($studentId, $student);

    // Legacy fallback: if no scholarship engine adjustments, check ra10931_override directly
    if (empty($adjustments)) {
        $override = (string) ($student['ra10931_override'] ?? 'auto');
        if ($override === 'free') {
            foreach ($assessments as &$a) {
                if (strcasecmp($a['fee_name'], 'tuition') === 0 && $a['category'] === 'assessment') {
                    $a['discount_amount'] = $a['gross_amount'];
                    $a['net_amount'] = 0.0;
                    $a['notes'] = ($a['quantity'] ?? 0) . ' units × ' . format_money($a['rate']) . '/unit — RA 10931 (Free Education)';
                    break;
                }
            }
            unset($a);
        }
    }

    $assessments['_fhe_eligibility'] = $fheEligibility;
    return $assessments;
}

/**
 * Freeze the fee assessment into enrollment_request_fees and update the enrollment request total.
 *
 * @param array $assessments Output of compute_enrollment_fees()
 */
function freeze_fee_assessment(int $requestId, array $assessments): void
{
    $totalNet = 0.0;

    $ins = db()->prepare(
        'INSERT INTO enrollment_request_fees
            (enrollment_request_id, fee_item_id, fee_name, category, calculation_type, quantity, rate, gross_amount, discount_amount, net_amount, notes)
         VALUES
            (:rid, :fid, :fname, :cat, :ctype, :qty, :rate, :gross, :discount, :net, :notes)'
    );

    foreach ($assessments as $a) {
        $ins->execute([
            'rid'      => $requestId,
            'fid'      => $a['fee_item_id'],
            'fname'    => $a['fee_name'],
            'cat'      => $a['category'],
            'ctype'    => $a['calculation_type'],
            'qty'      => $a['quantity'],
            'rate'     => $a['rate'],
            'gross'    => $a['gross_amount'],
            'discount' => $a['discount_amount'],
            'net'      => $a['net_amount'],
            'notes'    => $a['notes'],
        ]);
        $totalNet += $a['net_amount'];
    }

    execute_sql(
        'UPDATE enrollment_requests SET total_amount = :total WHERE id = :id',
        ['total' => round($totalNet, 2), 'id' => $requestId]
    );
}

/**
 * Retrieve the frozen fee assessment for an enrollment request.
 */
function get_frozen_fees(int $requestId): array
{
    return fetch_all(
        'SELECT * FROM enrollment_request_fees WHERE enrollment_request_id = :rid ORDER BY id',
        ['rid' => $requestId]
    );
}

/**
 * Get a summary of a frozen assessment.
 */
function get_frozen_fees_summary(int $requestId): array
{
    $fees = get_frozen_fees($requestId);

    $breakdown = [
        'laboratory' => ['items' => [], 'subtotal' => 0.0],
        'assessment' => ['items' => [], 'subtotal' => 0.0],
        'other'      => ['items' => [], 'subtotal' => 0.0],
    ];

    $totalGross = 0.0;
    $totalDiscount = 0.0;
    $totalNet = 0.0;
    $hasDiscount = false;

    foreach ($fees as $f) {
        $cat = $f['category'];
        if (isset($breakdown[$cat])) {
            $breakdown[$cat]['items'][] = $f;
            $breakdown[$cat]['subtotal'] += (float) $f['net_amount'];
        }
        $totalGross += (float) $f['gross_amount'];
        $totalDiscount += (float) $f['discount_amount'];
        $totalNet += (float) $f['net_amount'];
        if ((float) $f['discount_amount'] > 0) {
            $hasDiscount = true;
        }
    }

    return [
        'fees'          => $fees,
        'breakdown'     => $breakdown,
        'total_gross'   => round($totalGross, 2),
        'total_discount'=> round($totalDiscount, 2),
        'total_net'     => round($totalNet, 2),
        'has_discount'  => $hasDiscount,
    ];
}

/**
 * Calculate the payment balance for an enrollment request.
 */
function calculate_balance(int $requestId): array
{
    $req = fetch_one('SELECT total_amount FROM enrollment_requests WHERE id = :id', ['id' => $requestId]);
    if (!$req) {
        return ['total_assessed' => 0, 'total_paid' => 0, 'balance' => 0, 'payment_status' => 'unpaid'];
    }

    $totalAssessed = (float) $req['total_amount'];
    $totalPaid = (float) (fetch_one(
        'SELECT COALESCE(SUM(amount), 0) AS total FROM payments WHERE request_id = :rid',
        ['rid' => $requestId]
    )['total'] ?? 0);

    $balance = max(0, round($totalAssessed - $totalPaid, 2));

    if ($totalPaid <= 0) {
        $status = 'unpaid';
    } elseif ($balance <= 0) {
        $status = 'paid';
    } else {
        $status = 'partial';
    }

    return [
        'total_assessed' => $totalAssessed,
        'total_paid'     => $totalPaid,
        'balance'        => $balance,
        'payment_status' => $status,
    ];
}

/**
 * Derive and update the payment_status on enrollment_requests from payment records.
 */
function sync_payment_status(int $requestId): string
{
    $balanceInfo = calculate_balance($requestId);
    $newStatus = $balanceInfo['payment_status'];

    execute_sql(
        'UPDATE enrollment_requests SET payment_status = :status WHERE id = :id',
        ['status' => $newStatus, 'id' => $requestId]
    );

    return $newStatus;
}
