<?php
require 'testing_site/config/database.php';
$db = new Database();
$conn = $db->getConnection();
$desc = $conn->query('DESCRIBE lazada_product_mappings');
print_r($desc->fetchAll(PDO::FETCH_ASSOC));
