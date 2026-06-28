<?php
// api/inventory/add_stockin_to_reference.php - Add a new item to an existing stock-in reference
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

// Admin/Manager/Stockman only
if (!in_array($_SESSION['role'], ['admin', 'super_admin', 'manager', 'stockman'])) {
    echo json_encode(['success' => false, 'error' => 'Permission denied']);
    exit;
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    $input = $_POST;
}

$reference     = trim($input['reference'] ?? '');
$variation_id  = (int) ($input['variation_id'] ?? 0);
$quantity      = (float) ($input['quantity'] ?? 0);
$capital_cost  = (float) ($input['capital_cost'] ?? 0);
$remarks       = trim($input['remarks'] ?? '');
if (empty($remarks)) {
    $remarks = 'Manually added to reference: ' . $reference;
}
$user_id       = $_SESSION['user_id'] ?? 1;

// Validation
if (empty($reference)) {
    echo json_encode(['success' => false, 'error' => 'Reference number is required']);
    exit;
}
if (!$variation_id) {
    echo json_encode(['success' => false, 'error' => 'Product variation is required']);
    exit;
}
if ($quantity < 1) {
    echo json_encode(['success' => false, 'error' => 'Quantity must be at least 1']);
    exit;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    ensureStockinAdjustmentLogTable($conn);
    $conn->beginTransaction();
    $hasMovementStatus = payableReferenceColumnExists($conn, 'stock_movements', 'status');

    // 1. Check if reference exists in PO or stock_movements
    $stmtPO = $conn->prepare("SELECT po_id, supplier_id FROM purchase_orders WHERE po_ref = ? FOR UPDATE");
    $stmtPO->execute([$reference]);
    $po = $stmtPO->fetch(PDO::FETCH_ASSOC);

    if (!$po) {
        // If no PO, just check if reference exists in stock_movements
        $stmtCheck = $conn->prepare("SELECT 1 FROM stock_movements WHERE reference = ? LIMIT 1");
        $stmtCheck->execute([$reference]);
        if (!$stmtCheck->fetch()) {
            $conn->rollBack();
            echo json_encode(['success' => false, 'error' => 'Reference not found']);
            exit;
        }
    }

    // 2. Adjust Inventory and Get stock levels
    $stmtInv = $conn->prepare("SELECT COALESCE(quantity, 0) as qty FROM inventory WHERE variation_id = ? AND store_id = 1 FOR UPDATE");
    $stmtInv->execute([$variation_id]);
    $invRow = $stmtInv->fetch(PDO::FETCH_ASSOC);
    $previous_stock = $invRow ? (float) $invRow['qty'] : 0;
    $new_stock = $previous_stock + $quantity;

    $conn->prepare("
        INSERT INTO inventory (variation_id, quantity, store_id) 
        VALUES (?, ?, 1)
        ON DUPLICATE KEY UPDATE quantity = quantity + ?
    ")->execute([$variation_id, $quantity, $quantity]);

    // 3. Insert New Stock Movement
    if ($hasMovementStatus) {
        $stmtMove = $conn->prepare("
            INSERT INTO stock_movements
            (variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost, status)
            VALUES (?, 'stock_in', ?, ?, ?, ?, ?, ?, ?, 'active')
        ");
        $stmtMove->execute([$variation_id, $quantity, $previous_stock, $new_stock, $reference, $remarks, $user_id, $capital_cost]);
    } else {
        $stmtMove = $conn->prepare("
            INSERT INTO stock_movements
            (variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, capital_cost)
            VALUES (?, 'stock_in', ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmtMove->execute([$variation_id, $quantity, $previous_stock, $new_stock, $reference, $remarks, $user_id, $capital_cost]);
    }
    $new_movement_id = $conn->lastInsertId();

    // 4. Update Product Variation Capital Price if it changed
    $stmtOldCap = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
    $stmtOldCap->execute([$variation_id]);
    $oldCapRow = $stmtOldCap->fetch(PDO::FETCH_ASSOC);
    $old_capital = $oldCapRow ? (float) $oldCapRow['price_capital'] : 0.0;

    if (abs($old_capital - $capital_cost) > 0.001) {
        $conn->prepare("
            INSERT INTO product_price_history 
            (variation_id, user_id, old_capital, new_capital, old_retail, new_retail, old_wholesale, new_wholesale, old_dealer, new_dealer, changed_at)
            SELECT ?, ?, price_capital, ?, price_retail, price_retail, price_wholesale, price_wholesale, price_dealer, price_dealer, NOW()
            FROM product_variations WHERE variation_id = ?
        ")->execute([$variation_id, $user_id, $capital_cost, $variation_id]);

        $conn->prepare("UPDATE product_variations SET price_capital = ? WHERE variation_id = ?")
             ->execute([$capital_cost, $variation_id]);
    }

    // 5. Update Financial Payables (if PO exists)
    if ($po) {
        syncSupplierPayableForReference($conn, $reference);
    }
    if (false && $po) {
        $po_id = $po['po_id'];
        
        // A. Recalculate PO Total
        $stmtSum = $conn->prepare("SELECT SUM(quantity * capital_cost) FROM stock_movements WHERE reference = ? AND status != 'voided'");
        $stmtSum->execute([$reference]);
        $recalculated_total = (float)($stmtSum->fetchColumn() ?: 0);
        
        $conn->prepare("UPDATE purchase_orders SET total_amount = ? WHERE po_id = ?")
             ->execute([$recalculated_total, $po_id]);
        
        // B. Update Supplier Payment(s)
        $stmtPay = $conn->prepare("
            SELECT payment_id, amount, paid_amount 
            FROM supplier_payments 
            WHERE po_id = ? AND payment_status != 'paid' 
            ORDER BY due_date DESC LIMIT 1
        ");
        $stmtPay->execute([$po_id]);
        $payment = $stmtPay->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
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
            $item_total_cost = $quantity * $capital_cost;
            
            $stmtCheckMulti = $conn->prepare("SELECT COUNT(*) FROM supplier_payments WHERE po_id = ?");
            $stmtCheckMulti->execute([$po_id]);
            $is_single_payment = ($stmtCheckMulti->fetchColumn() == 1);

            if ($is_single_payment) {
                $new_payment_amount = $recalculated_total;
            } else {
                $new_payment_amount = (float)$payment['amount'] + $item_total_cost;
            }

            $paid_amount = (float)$payment['paid_amount'];
            $new_status = ($paid_amount > 0) ? 'partial' : 'pending';
            
            if ($paid_amount >= $new_payment_amount - 0.01 && $new_payment_amount > 0) {
                $new_status = 'paid';
            }

            $conn->prepare("
                UPDATE supplier_payments 
                SET amount = ?, payment_status = ?, updated_at = NOW() 
                WHERE payment_id = ?
            ")->execute([$new_payment_amount, $new_status, $payment['payment_id']]);

            // Sync PO status
            if ($new_status != 'paid' && $new_status != 'voided') {
                $conn->prepare("UPDATE purchase_orders SET payment_status = 'partial' WHERE po_id = ?")
                     ->execute([$po_id]);
            }
        }
    }

    // 5. Log the adjustment
    insertStockinAdjustmentLog($conn, [
        'movement_id' => $new_movement_id,
        'adjusted_by' => $user_id,
        'old_quantity' => 0,
        'new_quantity' => $quantity,
        'old_capital' => 0,
        'new_capital' => $capital_cost,
        'old_variation_id' => null,
        'new_variation_id' => $variation_id,
        'action_type' => 'add_to_ref',
        'reason' => 'Missed product added',
        'notes' => $remarks,
    ]);

    $conn->commit();

    echo json_encode([
        'success' => true, 
        'message' => 'Product added to reference and inventory updated.'
    ]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
