<?php
require 'config/database.php';
$db = new Database();
$c = $db->getConnection();
print_r($c->query('DESCRIBE shopee_product_mappings')->fetchAll(PDO::FETCH_ASSOC));
