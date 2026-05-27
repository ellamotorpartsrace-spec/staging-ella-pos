<?php
try {
    $db = new PDO('mysql:host=localhost;dbname=ella_parts_db','root','elladbPogisiBen');
    $stmt = $db->prepare("SELECT id, source, event_type, old_value, new_value, product_name, created_at FROM shopee_sync_logs WHERE event_type = 'mapping' AND new_value = 'Unmapped' ORDER BY id DESC LIMIT 5");
    $stmt->execute();
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
} catch(Exception $e) {
    echo $e->getMessage();
}
