<?php
$db = new PDO('mysql:host=localhost;dbname=ella_parts_db', 'root', 'elladbPogisiBen');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $db->exec("ALTER TABLE lazada_product_mappings ADD COLUMN stock_allocation_ratio INT NOT NULL DEFAULT 100 AFTER mapping_status");
    echo "Added stock_allocation_ratio\n";
} catch (Exception $e) {
    echo "Error adding stock_allocation_ratio: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE lazada_product_mappings ADD COLUMN pos_bundle_set_id INT DEFAULT NULL AFTER pos_unit_id");
    echo "Added pos_bundle_set_id\n";
} catch (Exception $e) {
    echo "Error adding pos_bundle_set_id: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE lazada_product_mappings ADD COLUMN pos_qty INT DEFAULT 0");
    echo "Added pos_qty\n";
} catch (Exception $e) {
    echo "Error adding pos_qty: " . $e->getMessage() . "\n";
}

try {
    $db->exec("ALTER TABLE lazada_product_mappings ADD COLUMN multiplier INT DEFAULT 1");
    echo "Added multiplier\n";
} catch (Exception $e) {
    echo "Error adding multiplier: " . $e->getMessage() . "\n";
}
