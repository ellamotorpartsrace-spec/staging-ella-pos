<?php
// scratch_check_6192.php - Diagnose variation 6192
if (!class_exists('Database')) { require 'config/database.php'; }
date_default_timezone_set('Asia/Manila');

$db = new Database();
$conn = $db->getConnection();

$vid = 6192;

// Current inventory
$inv = $conn->prepare("SELECT store_id, quantity FROM inventory WHERE variation_id = ?");
$inv->execute([$vid]);
$inventory = $inv->fetchAll(PDO::FETCH_ASSOC);

echo "=== INVENTORY TABLE (variation $vid) ===\n";
foreach ($inventory as $r) {
    echo "  store_id={$r['store_id']}: qty={$r['quantity']}\n";
}

// All movements ordered chronologically
$stmt = $conn->prepare("
    SELECT movement_id, type, quantity, previous_stock, new_stock,
           store_id, reference, created_at, COALESCE(status,'active') as status
    FROM stock_movements
    WHERE variation_id = ?
    ORDER BY movement_id ASC
");
$stmt->execute([$vid]);
$movements = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "\n=== ALL MOVEMENTS (chronological, variation $vid) ===\n";
echo str_pad("ID",8) . str_pad("Store",7) . str_pad("Type",22) . str_pad("Qty",8) . str_pad("Prev",8) . str_pad("New",8) . str_pad("Status",10) . "Reference\n";
echo str_repeat("-", 90) . "\n";

$STOCK_CHANGING = ['stock_in','stock_out','sales','adjustment','return','online_sale','online_adjustment'];

$running = [];
foreach ($movements as $m) {
    $sid = (int)$m['store_id'];
    $key = "store_$sid";
    if (!isset($running[$key])) $running[$key] = null;

    $prev = $running[$key];
    if ($prev === null) $prev = (float)$m['previous_stock'];

    $isStockChanging = in_array($m['type'], $STOCK_CHANGING);

    $delta = match($m['type']) {
        'sales','online_sale','stock_out' => -(float)$m['quantity'],
        default => (float)$m['quantity'],
    };
    $expNew = round($prev + $delta, 4);

    $ok = (abs((float)$m['previous_stock'] - $prev) <= 0.001 && abs((float)$m['new_stock'] - $expNew) <= 0.001) ? "OK" : "!!!BAD!!!";

    echo str_pad($m['movement_id'],8)
        . str_pad($sid,7)
        . str_pad($m['type'],22)
        . str_pad($m['quantity'],8)
        . str_pad($m['previous_stock'],8)
        . str_pad($m['new_stock'],8)
        . str_pad($m['status'],10)
        . substr($m['reference'] ?? '',0,30);

    if ($isStockChanging && $ok !== 'OK') echo " <-- $ok (expected prev=$prev new=$expNew)";
    echo "\n";

    if ($isStockChanging && $m['status'] !== 'voided') {
        $running[$key] = $expNew;
    }
}

echo "\n=== COMPUTED RUNNING BALANCE ===\n";
foreach ($running as $k => $v) echo "  $k: $v\n";

// Total movements count
$cnt = $conn->prepare("SELECT COUNT(*) FROM stock_movements WHERE variation_id = ?");
$cnt->execute([$vid]);
echo "\nTotal movement rows: " . $cnt->fetchColumn() . "\n";
echo "Shown above: " . count($movements) . "\n";
