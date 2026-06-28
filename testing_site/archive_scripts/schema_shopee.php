<?php
require 'config/database.php';
$db = new Database();
$c = $db->getConnection();
print_r($c->query('DESCRIBE shopee_orders')->fetchAll(PDO::FETCH_ASSOC));
print_r($c->query('DESCRIBE shopee_order_items')->fetchAll(PDO::FETCH_ASSOC));
