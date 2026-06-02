<?php
declare(strict_types=1);

function payableReferenceColumnExists(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function payableReferenceStockInSummary(PDO $conn, string $reference): array
{
    $statusFilter = payableReferenceColumnExists($conn, 'stock_movements', 'status')
        ? "AND COALESCE(sm.status, '') <> 'voided'"
        : '';

    $stmt = $conn->prepare("
        SELECT
            COUNT(*) AS line_count,
            COALESCE(SUM(
                ABS(sm.quantity) * COALESCE(NULLIF(sm.capital_cost, 0), pv.price_capital, 0)
            ), 0) AS reference_total
        FROM stock_movements sm
        LEFT JOIN product_variations pv ON pv.variation_id = sm.variation_id
        WHERE sm.reference = ?
          AND sm.type = 'stock_in'
          $statusFilter
    ");
    $stmt->execute([$reference]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    return [
        'line_count' => (int)($row['line_count'] ?? 0),
        'reference_total' => round((float)($row['reference_total'] ?? 0), 2),
    ];
}

function payableReferenceStockInTotal(PDO $conn, string $reference): float
{
    $summary = payableReferenceStockInSummary($conn, $reference);
    return (float)$summary['reference_total'];
}

function payableReferencePaymentStatus(float $amount, float $paidAmount): string
{
    if ($amount <= 0.01) {
        return 'voided';
    }
    if ($paidAmount >= $amount - 0.01) {
        return 'paid';
    }
    if ($paidAmount > 0.01) {
        return 'partial';
    }

    return 'pending';
}

function syncSupplierPayableForReference(PDO $conn, string $reference): array
{
    $reference = trim($reference);
    if ($reference === '') {
        return ['synced' => false, 'reason' => 'empty_reference'];
    }

    $stmtPO = $conn->prepare("
        SELECT po_id, total_amount
        FROM purchase_orders
        WHERE po_ref = ?
        FOR UPDATE
    ");
    $stmtPO->execute([$reference]);
    $po = $stmtPO->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        return ['synced' => false, 'reason' => 'po_not_found'];
    }

    $poId = (int)$po['po_id'];
    $stockInSummary = payableReferenceStockInSummary($conn, $reference);
    if ((int)$stockInSummary['line_count'] === 0) {
        return ['synced' => false, 'reason' => 'no_stock_movements', 'po_id' => $poId];
    }

    $referenceTotal = (float)$stockInSummary['reference_total'];

    $stmtPayments = $conn->prepare("
        SELECT payment_id, amount, paid_amount, payment_status, due_date
        FROM supplier_payments
        WHERE po_id = ?
        ORDER BY payment_status IN ('paid', 'voided') ASC, due_date DESC, payment_id DESC
        FOR UPDATE
    ");
    $stmtPayments->execute([$poId]);
    $payments = $stmtPayments->fetchAll(PDO::FETCH_ASSOC);

    $oldPaymentTotal = 0.0;
    foreach ($payments as $payment) {
        $oldPaymentTotal += (float)$payment['amount'];
    }

    $delta = round($referenceTotal - $oldPaymentTotal, 2);

    $conn->prepare("UPDATE purchase_orders SET total_amount = ? WHERE po_id = ?")
        ->execute([$referenceTotal, $poId]);

    if (!empty($payments) && abs($delta) > 0.01) {
        $target = $payments[0];
        $newAmount = max(0, round((float)$target['amount'] + $delta, 2));
        $paidAmount = (float)$target['paid_amount'];
        $newStatus = payableReferencePaymentStatus($newAmount, $paidAmount);

        $conn->prepare("
            UPDATE supplier_payments
            SET amount = ?,
                payment_status = ?,
                updated_at = NOW()
            WHERE payment_id = ?
        ")->execute([$newAmount, $newStatus, (int)$target['payment_id']]);
    }

    $stmtTotals = $conn->prepare("
        SELECT
            COALESCE(SUM(amount), 0) AS payment_total,
            COALESCE(SUM(paid_amount), 0) AS paid_total,
            SUM(CASE WHEN payment_status NOT IN ('paid', 'voided') THEN 1 ELSE 0 END) AS open_count
        FROM supplier_payments
        WHERE po_id = ?
    ");
    $stmtTotals->execute([$poId]);
    $totals = $stmtTotals->fetch(PDO::FETCH_ASSOC) ?: [];

    $paymentTotal = (float)($totals['payment_total'] ?? 0);
    $paidTotal = (float)($totals['paid_total'] ?? 0);
    $openCount = (int)($totals['open_count'] ?? 0);

    $poStatus = 'unpaid';
    if ($paymentTotal <= 0.01) {
        $poStatus = 'voided';
    } elseif ($openCount === 0 || $paidTotal >= $paymentTotal - 0.01) {
        $poStatus = 'paid';
    } elseif ($paidTotal > 0.01) {
        $poStatus = 'partial';
    }

    $conn->prepare("UPDATE purchase_orders SET payment_status = ? WHERE po_id = ?")
        ->execute([$poStatus, $poId]);

    return [
        'synced' => true,
        'po_id' => $poId,
        'reference_total' => $referenceTotal,
        'old_payment_total' => round($oldPaymentTotal, 2),
        'delta' => $delta,
        'payment_status' => $poStatus,
    ];
}
