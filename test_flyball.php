<?php
require 'testing_site/config/config.php';
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->query("SELECT m.lazada_variation_name, m.mapping_status, m.pos_product_id FROM lazada_product_mappings m WHERE m.lazada_product_name LIKE '%FLYBALL%'");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
