<?php 
require_once 'config/config.php'; 
require_once 'config/database.php'; 
require_once 'includes/auth.php'; 
requireLogin(); 
require_once 'api/shopee/sync_helpers.php'; 

$db = new Database(); 
$conn = $db->getConnection(); 

$stmt = $conn->query("SELECT DISTINCT pos_product_id FROM shopee_product_mappings WHERE pos_product_id IS NOT NULL AND mapping_status IN ('auto','manual')"); 
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN); 

foreach($ids as $id) { 
    propagateStockToPos($conn, $id, 0, '', '', 1, null); 
} 

echo 'Successfully fixed all ' . count($ids) . ' products!'; 
?>
