<?php
/**
 * api/lazada/get_mapped_ids.php
 * Fetches all mapped IDs for the batch Live Stock Sync
 */
header('Content-Type: application/json');
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT id, lazada_product_name, lazada_variation_name FROM lazada_product_mappings WHERE mapping_status IN ('mapped', 'auto', 'manual') ORDER BY id ASC");
    $stmt->execute();
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'mapped_items' => $results,
        'total' => count($results)
    ]);
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
