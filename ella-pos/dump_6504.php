<?php
require 'config/database.php';
$db = new Database();
$c = $db->getConnection();
$res = $c->query("SELECT movement_id, type, quantity, previous_stock, new_stock, reference, created_at FROM stock_movements WHERE variation_id = 6504 AND store_id = 1 AND status = 'active' ORDER BY created_at ASC, movement_id ASC")->fetchAll(PDO::FETCH_ASSOC);
file_put_contents('scratch_6504.txt', print_r($res, true));
