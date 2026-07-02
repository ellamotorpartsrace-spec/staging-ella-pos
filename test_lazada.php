<?php
require 'testing_site/config/config.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT * FROM lazada_product_mappings WHERE matched_pos_sku = 'BW-036' OR pos_product_id = 6415");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
