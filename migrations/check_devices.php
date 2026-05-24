<?php
require_once 'c:/xampp/htdocs/ella-pos/config/database.php';
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // Add voided to enum
    $conn->exec("ALTER TABLE service_fees MODIFY COLUMN payment_status ENUM('pending','partial','paid','voided') NOT NULL DEFAULT 'pending'");
    echo "Altered table successfully.\n";
    
    // Update view to exclude voided
    $conn->exec("CREATE OR REPLACE VIEW `v_pending_service_fees` AS
    SELECT 
        sf.fee_id, sf.fee_ref, sf.buyer_id, sf.buyer_name,
        COALESCE(b.buyer_name, sf.buyer_name) AS display_name,
        b.shop_name, b.contact_number,
        sf.fee_type, sf.description, sf.amount,
        sf.paid_amount, (sf.amount - sf.paid_amount) AS balance,
        sf.payment_status, sf.due_date, sf.sale_ref, sf.notes,
        sf.created_at,
        CASE 
            WHEN sf.due_date < CURDATE() AND sf.payment_status IN ('pending', 'partial') THEN 'overdue'
            WHEN sf.due_date = CURDATE() AND sf.payment_status IN ('pending', 'partial') THEN 'due_today'
            ELSE sf.payment_status
        END AS status_label,
        DATEDIFF(sf.due_date, CURDATE()) AS days_until_due,
        u.full_name AS created_by_name
    FROM service_fees sf
    LEFT JOIN buyers b ON sf.buyer_id = b.buyer_id
    LEFT JOIN users u ON sf.created_by = u.id
    WHERE sf.payment_status IN ('pending', 'partial')
    ORDER BY sf.due_date ASC");
    echo "Updated view successfully.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
