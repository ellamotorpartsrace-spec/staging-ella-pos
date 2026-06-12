<?php
// api/inventory/approve_restock.php
header("Content-Type: application/json");
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once '../../includes/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

requireLogin();

// STRICT CHECK
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Only admins can approve restocks.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$request_id = $input['request_id'] ?? null;
$batch_id = $input['batch_id'] ?? null;
$password = $input['password'] ?? '';

if (empty($password)) {
    echo json_encode(['success' => false, 'error' => 'Password is required.']);
    exit;
}

$db = new Database();
$conn = $db->getConnection();

// Verify Password
$stmtAuth = $conn->prepare("SELECT password FROM users WHERE id = ?");
$stmtAuth->execute([$_SESSION['user_id']]);
$user = $stmtAuth->fetch(PDO::FETCH_ASSOC);

if (!$user || !password_verify($password, $user['password'])) {
    echo json_encode(['success' => false, 'error' => 'Incorrect password.']);
    exit;
}

if (!$request_id && !$batch_id) {
    echo json_encode(['success' => false, 'error' => 'Missing ID']);
    exit;
}

try {
    $conn->beginTransaction();

    $user_id = $_SESSION['user_id'] ?? 1;
    $processed = 0;
    
    // Fetch requests
    if ($batch_id) {
        $stmt = $conn->prepare("SELECT * FROM restock_requests WHERE batch_id = ? AND status = 'pending'");
        $stmt->execute([$batch_id]);
    } else {
        $stmt = $conn->prepare("SELECT * FROM restock_requests WHERE request_id = ? AND status = 'pending'");
        $stmt->execute([$request_id]);
    }
    
    $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($requests)) {
        throw new Exception("No pending requests found for this ID.");
    }

    $totalCostBySupplier = [];
    $supplierDueDates = [];
    $supplierCreditTerms = [];

    foreach ($requests as $req) {
        $variation_id = $req['variation_id'];
        $quantity = (int) $req['quantity'];
        $cost = (float) $req['cost'];
        $supplier_id = $req['supplier_id'];
        $reference = $req['reference'];
        
        // 1. Update Inventory
        $stmtStock = $conn->prepare("SELECT COALESCE(quantity, 0) as qty FROM inventory WHERE variation_id = ? AND store_id = 1");
        $stmtStock->execute([$variation_id]);
        $inv = $stmtStock->fetch(PDO::FETCH_ASSOC);
        $actual_current = $inv ? (int) $inv['qty'] : 0;
        
        $conn->prepare("
            INSERT INTO inventory (variation_id, quantity, store_id) 
            VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE quantity = quantity + ?
        ")->execute([$variation_id, $quantity, $quantity]);
        
        $new_stock = $actual_current + $quantity;

        // 2. Update Price
        if ($cost > 0) {
            $stmtOld = $conn->prepare("SELECT price_capital FROM product_variations WHERE variation_id = ?");
            $stmtOld->execute([$variation_id]);
            $oldData = $stmtOld->fetch(PDO::FETCH_ASSOC);
            $old_capital = $oldData ? (float) $oldData['price_capital'] : 0;
            
            if ($old_capital != $cost) {
                $conn->prepare("
                    INSERT INTO product_price_history 
                    (variation_id, user_id, old_capital, new_capital, old_retail, new_retail, old_wholesale, new_wholesale, old_dealer, new_dealer, changed_at)
                    SELECT ?, ?, price_capital, ?, price_retail, price_retail, price_wholesale, price_wholesale, price_dealer, price_dealer, NOW()
                    FROM product_variations WHERE variation_id = ?
                ")->execute([$variation_id, $user_id, $cost, $variation_id]);
                
                $conn->prepare("UPDATE product_variations SET price_capital = ? WHERE variation_id = ?")->execute([$cost, $variation_id]);
            }
        }

        // 3. Log Movement
        $remarks = "Approved Restock: " . ($req['supplier_name'] ?: 'Unknown') . " (Requested by user #" . $req['requested_by'] . ")";
        $conn->prepare("
            INSERT INTO stock_movements 
            (variation_id, type, quantity, previous_stock, new_stock, reference, remarks, created_by, store_id, capital_cost)
            VALUES (?, 'stock_in', ?, ?, ?, ?, ?, ?, 1, ?)
        ")->execute([
            $variation_id, $quantity, $actual_current, $new_stock, $reference, $remarks, $user_id, $cost
        ]);

        // Accumulate POs
        if ($req['payment_status'] === 'unpaid' && $supplier_id) {
            if (!isset($totalCostBySupplier[$supplier_id])) {
                $totalCostBySupplier[$supplier_id] = 0;
                $supplierDueDates[$supplier_id] = $req['due_date'];
                $supplierCreditTerms[$supplier_id] = $req['credit_terms'];
            }
            $totalCostBySupplier[$supplier_id] += ($quantity * $cost);
        }

        // Mark as approved
        $conn->prepare("UPDATE restock_requests SET status = 'approved', approved_by = ?, updated_at = NOW() WHERE request_id = ?")
             ->execute([$user_id, $req['request_id']]);
             
        $processed++;
    }

    // Handle POs
    foreach ($totalCostBySupplier as $sid => $totalCost) {
        if ($totalCost > 0) {
            $po_ref = $requests[0]['reference'] ?: 'PO-APRV-' . date('YmdHis');
            $due_date = $supplierDueDates[$sid];
            $credit_terms = $supplierCreditTerms[$sid] ? json_decode($supplierCreditTerms[$sid], true) : [];
            $primary_due_date = (!empty($credit_terms) && isset($credit_terms[0]['due_date'])) ? $credit_terms[0]['due_date'] : ($due_date ?: date('Y-m-d', strtotime('+30 days')));

            $checkPO = $conn->prepare("SELECT po_id FROM purchase_orders WHERE po_ref = ?");
            $checkPO->execute([$po_ref]);
            $existingPO = $checkPO->fetch();

            if (!$existingPO) {
                $stmtPO = $conn->prepare("
                    INSERT INTO purchase_orders 
                    (supplier_id, po_ref, total_amount, status, payment_status, user_id, due_date) 
                    VALUES (?, ?, ?, 'received', 'unpaid', ?, ?)
                ");
                $stmtPO->execute([$sid, $po_ref, $totalCost, $user_id, $primary_due_date]);
                $po_id = $conn->lastInsertId();
            } else {
                $po_id = $existingPO['po_id'];
                $conn->prepare("UPDATE purchase_orders SET total_amount = total_amount + ? WHERE po_id = ?")->execute([$totalCost, $po_id]);
            }

            if (!empty($credit_terms)) {
                $stmtPay = $conn->prepare("INSERT INTO supplier_payments (po_id, supplier_id, amount, due_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)");
                foreach ($credit_terms as $idx => $term) {
                    $notes = "Approved Restock" . (count($credit_terms) > 1 ? " (Term " . ($idx+1) . ")" : "");
                    $stmtPay->execute([$po_id, $sid, $term['amount'], $term['due_date'], $notes, $user_id]);
                }
            } else {
                $conn->prepare("INSERT INTO supplier_payments (po_id, supplier_id, amount, due_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?)")
                     ->execute([$po_id, $sid, $totalCost, $primary_due_date, "Approved Restock", $user_id]);
            }
        }
    }

    $conn->commit();

    logActivity($conn, $user_id, 'RESTOCK_APPROVE', 'Inventory', "Approved $processed restock items.");

    echo json_encode(['success' => true, 'message' => "Successfully approved $processed items"]);

} catch (Exception $e) {
    if (isset($conn) && $conn->inTransaction()) {
        $conn->rollBack();
    }
    error_log("Approve Restock Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
