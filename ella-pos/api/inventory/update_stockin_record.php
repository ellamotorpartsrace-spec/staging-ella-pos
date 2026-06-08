<?php
// api/inventory/update_stockin_record.php - Correct/Swap/Void a stock-in record and update financial payables
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/payable_reference_sync.php';
require_once '../../includes/stockin_adjustment_log.php';

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

// Admin only
if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$movement_id  = (int) ($input['movement_id'] ?? 0);
$action_type  = $input['action_type'] ?? 'edit'; // edit, swap, void, reference
$new_quantity  = (int) ($input['new_quantity'] ?? 0);
$new_capital   = (float) ($input['new_capital'] ?? 0);
$new_variation_id = (int) ($input['new_variation_id'] ?? 0);
$new_reference = trim((string) ($input['new_reference'] ?? ''));
$reason        = trim($input['reason'] ?? '');
$notes         = trim($input['notes'] ?? '');
$user_id       = $_SESSION['user_id'] ?? 1;

// Validation
if (!$movement_id) {
    echo json_encode(['success' => false, 'error' => 'Invalid movement ID']);
    exit;
}
if (!in_array($action_type, ['edit', 'swap', 'void', 'reference'], true)) {
    echo json_encode(['success' => false, 'error' => 'Invalid correction type']);
    exit;
}
if ($action_type === 'edit' || $action_type === 'swap') {
    if ($new_quantity < 1) {
        echo json_encode(['success' => false, 'error' => 'Quantity must be at least 1']);
        exit;
    }
}
if ($action_type === 'swap' && !$new_variation_id) {
    echo json_encode(['success' => false, 'error' => 'New product variation ID required for swap']);
    exit;
}
if ($action_type === 'reference') {
    if ($new_reference === '') {
        echo json_encode(['success' => false, 'error' => 'New reference number is required']);
        exit;
    }
    if (strlen($new_reference) > 100) {
        echo json_encode(['success' => false, 'error' => 'Reference number must be 100 characters or less']);
        exit;
    }
}
if (empty($reason)) {
    echo json_encode(['success' => false, 'error' => 'Reason is required']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureStockinAdjustmentLogTable($conn);
    $conn->beginTransaction();
    $hasMovementStatus = payableReferenceColumnExists($conn, 'stock_movements', 'status');

    // 1. Load original movement
    $stmt = $conn->prepare("
        SELECT sm.*, COALESCE(sm.capital_cost, pv.price_capital) as effective_capital
        FROM stock_movements sm
        JOIN product_variations pv ON sm.variation_id = pv.variation_id
        WHERE sm.movement_id = ? AND sm.type = 'stock_in'
        FOR UPDATE
    ");
    $stmt->execute([$movement_id]);
    $original = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$original) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'Original stock-in record not found']);
        exit;
    }

    if ($hasMovementStatus && ($original['status'] ?? '') === 'voided') {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'This record is already voided and cannot be edited']);
        exit;
    }

    $old_quantity = (int) $original['quantity'];
    $old_capital  = (float) $original['effective_capital'];
    $old_variation_id = $original['variation_id'];
    $reference = $original['reference'];

    if ($action_type === 'reference') {
        $old_reference = trim((string) $reference);

        if ($new_reference === $old_reference) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'New reference number is the same as the current reference']);
            exit;
        }

        $groupParams = [];
        if ($old_reference === '') {
            $groupWhere = 'sm.movement_id = ?';
            $groupParams[] = $movement_id;
        } else {
            $groupWhere = 'sm.reference = ? AND sm.type = \'stock_in\'';
            $groupParams[] = $old_reference;
        }

        $stmtGroup = $conn->prepare("
            SELECT
                sm.movement_id,
                sm.quantity,
                COALESCE(sm.capital_cost, pv.price_capital, 0) as effective_capital,
                sm.variation_id
            FROM stock_movements sm
            LEFT JOIN product_variations pv ON sm.variation_id = pv.variation_id
            WHERE $groupWhere
            FOR UPDATE
        ");
        $stmtGroup->execute($groupParams);
        $referenceRows = $stmtGroup->fetchAll(PDO::FETCH_ASSOC);

        if (!$referenceRows) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'No stock-in rows found for this reference']);
            exit;
        }

        $stmtExistingMovements = $conn->prepare("
            SELECT COUNT(*)
            FROM stock_movements
            WHERE type = 'stock_in'
              AND reference = ?
              " . ($old_reference === '' ? 'AND movement_id <> ?' : '') . "
        ");
        $existingMovementParams = [$new_reference];
        if ($old_reference === '') {
            $existingMovementParams[] = $movement_id;
        }
        $stmtExistingMovements->execute($existingMovementParams);
        if ((int) $stmtExistingMovements->fetchColumn() > 0) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'That reference number is already used by another stock-in record']);
            exit;
        }

        $oldPo = null;
        if ($old_reference !== '') {
            $stmtOldPo = $conn->prepare("SELECT po_id FROM purchase_orders WHERE po_ref = ? FOR UPDATE");
            $stmtOldPo->execute([$old_reference]);
            $oldPo = $stmtOldPo->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $stmtNewPo = $conn->prepare("SELECT po_id FROM purchase_orders WHERE po_ref = ? FOR UPDATE");
        $stmtNewPo->execute([$new_reference]);
        $newPo = $stmtNewPo->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($newPo && (!$oldPo || (int) $newPo['po_id'] !== (int) $oldPo['po_id'])) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'That reference number is already used by another purchase order']);
            exit;
        }
        if ($oldPo && strlen($new_reference) > 50) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'Purchase order references must be 50 characters or less']);
            exit;
        }

        $stmtExistingAttachments = $conn->prepare("SELECT COUNT(*) FROM reference_attachments WHERE reference_number = ?");
        $stmtExistingAttachments->execute([$new_reference]);
        if ((int) $stmtExistingAttachments->fetchColumn() > 0) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'That reference number already has receipt attachments']);
            exit;
        }

        if ($old_reference === '') {
            $conn->prepare("UPDATE stock_movements SET reference = ? WHERE movement_id = ?")
                ->execute([$new_reference, $movement_id]);
        } else {
            $conn->prepare("UPDATE stock_movements SET reference = ? WHERE type = 'stock_in' AND reference = ?")
                ->execute([$new_reference, $old_reference]);
        }

        if ($oldPo) {
            $conn->prepare("UPDATE purchase_orders SET po_ref = ? WHERE po_id = ?")
                ->execute([$new_reference, (int) $oldPo['po_id']]);
        }

        if ($old_reference !== '') {
            $conn->prepare("UPDATE reference_attachments SET reference_number = ? WHERE reference_number = ?")
                ->execute([$new_reference, $old_reference]);
        }

        $referenceNote = 'Reference changed from "' . ($old_reference !== '' ? $old_reference : 'No Reference') . '" to "' . $new_reference . '".';
        $logNotes = trim($referenceNote . ($notes !== '' ? ' ' . $notes : ''));

        foreach ($referenceRows as $row) {
            insertStockinAdjustmentLog($conn, [
                'movement_id' => (int) $row['movement_id'],
                'adjusted_by' => $user_id,
                'old_quantity' => (int) $row['quantity'],
                'new_quantity' => (int) $row['quantity'],
                'old_capital' => (float) $row['effective_capital'],
                'new_capital' => (float) $row['effective_capital'],
                'old_variation_id' => (int) $row['variation_id'],
                'new_variation_id' => (int) $row['variation_id'],
                'action_type' => 'reference',
                'reason' => $reason,
                'notes' => $logNotes,
            ]);
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Reference number updated for ' . count($referenceRows) . ' stock-in line(s).'
        ]);
        exit;
    }

    if ($action_type === 'void') {
        $new_quantity = 0;
        $new_variation_id = $old_variation_id;
    } elseif ($action_type === 'edit') {
        $new_variation_id = $old_variation_id;
    }

    // Calculate Financial Delta
    $old_total = $old_quantity * $old_capital;
    $new_total = $new_quantity * $new_capital;
    $total_delta_cost = $new_total - $old_total;

    // 2. Adjust Inventory
    if ($action_type === 'swap' && $old_variation_id !== $new_variation_id) {
        // A. Remove from old variation
        $stmtInvOld = $conn->prepare("SELECT COALESCE(quantity, 0) as qty FROM inventory WHERE variation_id = ? AND store_id = 1");
        $stmtInvOld->execute([$old_variation_id]);
        $invOld = $stmtInvOld->fetch(PDO::FETCH_ASSOC);
        $current_old = $invOld ? (int) $invOld['qty'] : 0;
        
        if ($current_old - $old_quantity < 0) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => "Cannot remove $old_quantity from old product. Current stock is $current_old."]);
            exit;
        }
        
        $conn->prepare("UPDATE inventory SET quantity = quantity - ? WHERE variation_id = ? AND store_id = 1")
             ->execute([$old_quantity, $old_variation_id]);
             
        // B. Add to new variation
        $conn->prepare("
            INSERT INTO inventory (variation_id, quantity, store_id) 
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ")->execute([$new_variation_id, $new_quantity, $new_quantity]);
        
    } else {
        // Just an edit or void (same variation)
        $qty_delta = $new_quantity - $old_quantity;
        
        if ($qty_delta !== 0) {
            $stmtInv = $conn->prepare("SELECT COALESCE(quantity, 0) as qty FROM inventory WHERE variation_id = ? AND store_id = 1");
            $stmtInv->execute([$old_variation_id]);
            $invRow = $stmtInv->fetch(PDO::FETCH_ASSOC);
            $current_inventory = $invRow ? (int) $invRow['qty'] : 0;

            if ($current_inventory + $qty_delta < 0) {
                $conn->rollBack();
                echo json_encode(['success' => false, 'error' => "Cannot reduce by " . abs($qty_delta) . ". Current inventory is $current_inventory."]);
                exit;
            }

            $conn->prepare("
                INSERT INTO inventory (variation_id, quantity, store_id) 
                VALUES (?, ?, 1)
                ON DUPLICATE KEY UPDATE quantity = quantity + ?
            ")->execute([$old_variation_id, $qty_delta, $qty_delta]);
        }
    }

    // 2.1 Get New Stock Levels for the record (for History/Movements)
    // We fetch the final inventory levels of the item NOW associated with this movement
    $stmtInvFinal = $conn->prepare("SELECT COALESCE(quantity, 0) as qty FROM inventory WHERE variation_id = ? AND store_id = 1");
    $stmtInvFinal->execute([$new_variation_id]);
    $final_qty = (int) ($stmtInvFinal->fetchColumn() ?: 0);
    $prev_qty_for_record = $final_qty - $new_quantity;
    if ($action_type === 'void') {
        $final_qty = $final_qty; // No change to inventory from THIS record after void
        // Since it's voided, it's safer to keep the original snapshot or set to current
        // But for clarity in movements, we'll set previous=new=current
        $prev_qty_for_record = $final_qty;
    }

    // 3. Update original movement
    if ($action_type === 'void') {
        if ($hasMovementStatus) {
            $conn->prepare("UPDATE stock_movements SET status = 'voided', quantity = 0, previous_stock = ?, new_stock = ? WHERE movement_id = ?")
                 ->execute([$prev_qty_for_record, $final_qty, $movement_id]);
        } else {
            $conn->prepare("UPDATE stock_movements SET quantity = 0, previous_stock = ?, new_stock = ? WHERE movement_id = ?")
                 ->execute([$prev_qty_for_record, $final_qty, $movement_id]);
        }
    } else {
        $conn->prepare("UPDATE stock_movements SET variation_id = ?, quantity = ?, capital_cost = ?, previous_stock = ?, new_stock = ? WHERE movement_id = ?")
             ->execute([$new_variation_id, $new_quantity, $new_capital, $prev_qty_for_record, $final_qty, $movement_id]);
             
        // If capital price changed, update product variation too
        if (abs($old_capital - $new_capital) > 0.001) {
            // Log price history first
            $conn->prepare("
                INSERT INTO product_price_history 
                (variation_id, user_id, old_capital, new_capital, old_retail, new_retail, old_wholesale, new_wholesale, old_dealer, new_dealer, changed_at)
                SELECT ?, ?, price_capital, ?, price_retail, price_retail, price_wholesale, price_wholesale, price_dealer, price_dealer, NOW()
                FROM product_variations WHERE variation_id = ?
            ")->execute([$new_variation_id, $user_id, $new_capital, $new_variation_id]);

            $conn->prepare("UPDATE product_variations SET price_capital = ? WHERE variation_id = ?")
                 ->execute([$new_capital, $new_variation_id]);
        }
    }

    // 4. Update Financial Payables (if applicable)
    // Trigger on any financial change OR when voiding (full removal of value)
    $has_financial_change = abs($total_delta_cost) > 0.001 || $action_type === 'void';
    if ($has_financial_change && !empty($reference)) {
        syncSupplierPayableForReference($conn, $reference);
    }
    if (false && $has_financial_change) {
        // Find Purchase Order — by reference first
        $po = null;
        if (!empty($reference)) {
            $stmtPO = $conn->prepare("SELECT po_id, payment_status FROM purchase_orders WHERE po_ref = ? FOR UPDATE");
            $stmtPO->execute([$reference]);
            $po = $stmtPO->fetch(PDO::FETCH_ASSOC);
        }

        if ($po) {
            $po_id = $po['po_id'];

            // A. Recalculate PO Total from all non-voided movements with this reference
            $stmtSum = $conn->prepare("SELECT SUM(quantity * capital_cost) FROM stock_movements WHERE reference = ? AND status != 'voided'");
            $stmtSum->execute([$reference]);
            $recalculated_total = (float)($stmtSum->fetchColumn() ?: 0);

            $conn->prepare("UPDATE purchase_orders SET total_amount = ? WHERE po_id = ?")
                 ->execute([$recalculated_total, $po_id]);

            // B. Update Supplier Payment(s)
            // We look for payments that are not fully paid first
            $stmtPay = $conn->prepare("
                SELECT payment_id, amount, paid_amount 
                FROM supplier_payments 
                WHERE po_id = ? AND payment_status != 'paid' 
                ORDER BY due_date DESC LIMIT 1
            ");
            $stmtPay->execute([$po_id]);
            $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);

            if (!$payment) {
                // If all are paid, we just update the last payment regardless of status
                $stmtPay = $conn->prepare("
                    SELECT payment_id, amount, paid_amount 
                    FROM supplier_payments 
                    WHERE po_id = ? 
                    ORDER BY due_date DESC LIMIT 1
                ");
                $stmtPay->execute([$po_id]);
                $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);
            }

            if ($payment) {
                // Determine new target amount for the payment(s)
                // If there's only one payment for this PO, it should match the PO total
                $stmtCheckMulti = $conn->prepare("SELECT COUNT(*) FROM supplier_payments WHERE po_id = ?");
                $stmtCheckMulti->execute([$po_id]);
                $is_single_payment = ($stmtCheckMulti->fetchColumn() == 1);

                if ($is_single_payment) {
                    $new_amount = $recalculated_total;
                } else {
                    // For multi-payment POs, we apply the delta to the chosen payment
                    $new_amount = max(0, (float)$payment['amount'] + $total_delta_cost);
                }

                $paid_amount = (float)$payment['paid_amount'];

                // Determine new status for this specific payment record
                $new_status = 'pending';
                if ($paid_amount > 0) {
                    $new_status = 'partial';
                }
                if ($paid_amount >= $new_amount - 0.01 && $new_amount > 0) {
                    $new_status = 'paid';
                } elseif ($new_amount <= 0.01) {
                    $new_status = 'voided'; // effectively voided payment
                }

                $conn->prepare("
                    UPDATE supplier_payments 
                    SET amount = ?, payment_status = ?, updated_at = NOW() 
                    WHERE payment_id = ?
                ")->execute([$new_amount, $new_status, $payment['payment_id']]);

                // Also update PO payment status if it was paid but now has a balance
                if ($new_status != 'paid' && $new_status != 'voided') {
                    $conn->prepare("UPDATE purchase_orders SET payment_status = 'partial' WHERE po_id = ?")
                         ->execute([$po_id]);
                } elseif ($new_status === 'voided') {
                    // Check if all payments for this PO are now voided or paid
                    $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM supplier_payments WHERE po_id = ? AND payment_status NOT IN ('paid', 'voided')");
                    $stmtCheck->execute([$po_id]);
                    if ($stmtCheck->fetchColumn() == 0) {
                        $conn->prepare("UPDATE purchase_orders SET payment_status = 'voided' WHERE po_id = ?")
                             ->execute([$po_id]);
                    }
                } else {
                    // Check if all payments for this PO are now paid
                    $stmtCheck = $conn->prepare("SELECT COUNT(*) FROM supplier_payments WHERE po_id = ? AND payment_status != 'paid'");
                    $stmtCheck->execute([$po_id]);
                    if ($stmtCheck->fetchColumn() == 0) {
                        $conn->prepare("UPDATE purchase_orders SET payment_status = 'paid' WHERE po_id = ?")
                             ->execute([$po_id]);
                    }
                }
            }
        }
    }

    // 5. Insert adjustment log
    insertStockinAdjustmentLog($conn, [
        'movement_id' => $movement_id,
        'adjusted_by' => $user_id,
        'old_quantity' => $old_quantity,
        'new_quantity' => $new_quantity,
        'old_capital' => $old_capital,
        'new_capital' => $new_capital,
        'old_variation_id' => $old_variation_id,
        'new_variation_id' => $new_variation_id,
        'action_type' => $action_type,
        'reason' => $reason,
        'notes' => $notes ?: null,
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Stock-in record ' . ($action_type === 'void' ? 'voided' : 'updated') . ' successfully and financial payables adjusted.'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
