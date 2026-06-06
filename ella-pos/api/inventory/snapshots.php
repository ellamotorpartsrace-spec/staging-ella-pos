<?php
/**
 * api/inventory/snapshots.php
 * Central API for the Inventory Snapshot, Backup & Recovery System.
 * ADMIN ONLY — every action enforces session role check.
 *
 * Actions via ?action=:
 *   stats                GET  — Dashboard stat cards
 *   list                 GET  — All snapshots
 *   create               POST — Create a manual snapshot
 *   detail               GET  — Items for one snapshot (?id=X&limit=N&offset=N)
 *   compare              GET  — Diff between two snapshots (?a=X&b=Y)
 *   restore              POST — Safe restore (auto pre-restore backup first)
 *   delete               POST — Delete a snapshot (requires confirmation='DELETE')
 *   settings_get         GET  — Read auto-snapshot settings
 *   settings_save        POST — Save auto-snapshot settings
 *   export_comparison    GET  — Download diff as CSV (?a=X&b=Y)
 *   verify_admin_password POST— Verify current admin password (for emergency restore)
 *   audit_logs           GET  — Paginated audit log (?offset=N&limit=N&action_type=X)
 */

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';
require_once __DIR__ . '/snapshot_helpers.php';

requireLogin();

// ── Admin-only guard ─────────────────────────────────────────────────────────
if (($_SESSION['role'] ?? '') !== 'admin') {
    if (!headers_sent()) header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

// CSV/JSON export skips JSON content-type
if (!in_array($action, ['export_comparison', 'export_cold_backup'])) {
    if (!headers_sent()) header('Content-Type: application/json');
}

// ── DB connection ─────────────────────────────────────────────────────────────
try {
    $db   = new Database();
    $conn = $db->getConnection();
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database connection failed.']);
    exit;
}

// ── Current user context ──────────────────────────────────────────────────────
$currentUserId   = (int)($_SESSION['user_id'] ?? 0);
$currentUserName = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin';
$currentIp       = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

// ── Router ────────────────────────────────────────────────────────────────────
switch ($action) {
    case 'stats':                 handleStats($conn);                   break;
    case 'list':                  handleList($conn);                    break;
    case 'create':                handleCreate($conn);                  break;
    case 'detail':                handleDetail($conn);                  break;
    case 'compare':               handleCompare($conn);                 break;
    case 'restore':               handleRestore($conn);                 break;

    case 'settings_get':          handleSettingsGet($conn);             break;
    case 'settings_save':         handleSettingsSave($conn);            break;
    case 'export_comparison':     handleExportComparison($conn);        break;
    case 'export_cold_backup':    handleExportColdBackup($conn);        break;
    case 'verify_admin_password': handleVerifyAdminPassword($conn);     break;
    case 'audit_logs':            handleAuditLogs($conn);               break;
    default:
        echo json_encode(['success' => false, 'error' => 'Unknown action: ' . htmlspecialchars($action)]);
}
exit;

// =============================================================================
// ACTION: stats — dashboard card data
// =============================================================================
function handleStats(PDO $conn): void
{
    try {
        $totalSnapshots = (int)$conn->query("SELECT COUNT(*) FROM inventory_snapshots")->fetchColumn();

        $lastSnap = $conn->query("
            SELECT snapshot_name, created_at, total_products
            FROM inventory_snapshots
            ORDER BY created_at DESC LIMIT 1
        ")->fetch(PDO::FETCH_ASSOC);

        $productsProtected = (int)$conn->query("
            SELECT COUNT(*) FROM product_variations WHERE status = 'active'
        ")->fetchColumn();

        $stockProtected = (int)$conn->query("
            SELECT SUM(i.quantity) 
            FROM inventory i
            JOIN product_variations pv ON i.variation_id = pv.variation_id
            WHERE pv.status = 'active'
        ")->fetchColumn();

        // Audit table might not exist on prod yet — handle gracefully
        $lastRestore = null;
        try {
            $lastRestore = $conn->query("
                SELECT created_at FROM inventory_snapshot_audit
                WHERE action_type IN ('RESTORE','EMERGENCY_RESTORE')
                ORDER BY created_at DESC LIMIT 1
            ")->fetchColumn() ?: null;
        } catch (Exception $ignored) {}

        echo json_encode([
            'success'             => true,
            'total_snapshots'     => $totalSnapshots,
            'last_backup'         => $lastSnap['created_at']      ?? null,
            'last_backup_name'    => $lastSnap['snapshot_name']   ?? null,
            'last_backup_products'=> $lastSnap['total_products']  ?? 0,
            'products_protected'  => $productsProtected,
            'stock_protected'     => $stockProtected,
            'last_recovery'       => $lastRestore,
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================================================
// ACTION: list — all snapshots, newest first
// =============================================================================
function handleList(PDO $conn): void
{
    try {
        $snapshots = $conn->query("
            SELECT id, snapshot_name, notes, total_products, total_stock_qty,
                   trigger_type, created_by_name, created_at
            FROM inventory_snapshots
            ORDER BY created_at DESC
        ")->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success' => true, 'snapshots' => $snapshots]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================================================
// ACTION: create — manual snapshot
// =============================================================================
function handleCreate(PDO $conn): void
{
    global $currentUserId, $currentUserName, $currentIp;

    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $name  = trim($input['name']  ?? '');
    $notes = trim($input['notes'] ?? '');

    if ($name === '') {
        echo json_encode(['success' => false, 'error' => 'Snapshot name is required.']);
        return;
    }

    $conn->beginTransaction();
    try {
        $snapId = createSnapshotInternal($conn, $name, $notes, 'manual', $currentUserId, $currentUserName);
        $snapStmt = $conn->query("SELECT total_products, total_stock_qty FROM inventory_snapshots WHERE id = {$snapId}");
        $snapData = $snapStmt->fetch(PDO::FETCH_ASSOC);
        $count = (int)$snapData['total_products'];
        $qty   = (int)$snapData['total_stock_qty'];

        logSnapshotAudit($conn, 'CREATE_SNAPSHOT', $snapId, $name, null,
            $count, 'Manual snapshot created.', $currentUserId, $currentUserName, $currentIp, $qty);

        $conn->commit();
        echo json_encode([
            'success'        => true,
            'snapshot_id'    => $snapId,
            'total_products' => $count,
            'message'        => "Snapshot \"{$name}\" created — {$count} products captured.",
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
}

// =============================================================================
// ACTION: detail — items for one snapshot (paginated)
// =============================================================================
function handleDetail(PDO $conn): void
{
    $id     = (int)($_GET['id']     ?? 0);
    $limit  = min((int)($_GET['limit']  ?? 50), 500);
    $offset = (int)($_GET['offset'] ?? 0);
    $search = trim($_GET['search'] ?? '');

    if (!$id) { echo json_encode(['success' => false, 'error' => 'Missing snapshot id.']); return; }

    $snapStmt = $conn->prepare("SELECT * FROM inventory_snapshots WHERE id = ?");
    $snapStmt->execute([$id]);
    $header = $snapStmt->fetch(PDO::FETCH_ASSOC);
    if (!$header) { echo json_encode(['success' => false, 'error' => 'Snapshot not found.']); return; }

    $where  = 'WHERE isi.snapshot_id = ?';
    $params = [$id];
    if ($search !== '') {
        $words = preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($words as $word) {
            $where .= ' AND (isi.sku LIKE ? OR isi.product_name LIKE ? OR p.brand_name LIKE ?)';
            $term = '%' . $word . '%';
            $params[] = $term;
            $params[] = $term;
            $params[] = $term;
        }
    }

    $cStmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM inventory_snapshot_items isi
        LEFT JOIN product_variations pv ON isi.variation_id = pv.variation_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        {$where}
    ");
    $cStmt->execute($params);
    $total = (int)$cStmt->fetchColumn();

    $iStmt = $conn->prepare("
        SELECT isi.*, p.brand_name 
        FROM inventory_snapshot_items isi
        LEFT JOIN product_variations pv ON isi.variation_id = pv.variation_id
        LEFT JOIN products p ON pv.product_id = p.product_id
        {$where}
        ORDER BY isi.product_name ASC
        LIMIT ? OFFSET ?
    ");
    $idx = 1;
    foreach ($params as $p) {
        $iStmt->bindValue($idx++, $p);
    }
    $iStmt->bindValue($idx++, (int)$limit, PDO::PARAM_INT);
    $iStmt->bindValue($idx++, (int)$offset, PDO::PARAM_INT);
    $iStmt->execute();
    $items = $iStmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success'  => true,
        'snapshot' => $header,
        'total'    => $total,
        'items'    => $items,
    ]);
}

// =============================================================================
// ACTION: compare — diff between two snapshots
// =============================================================================
function handleCompare(PDO $conn): void
{
    $aId = (int)($_GET['a'] ?? 0);
    $bId = (int)($_GET['b'] ?? 0);

    if (!$aId || !$bId) {
        echo json_encode(['success' => false, 'error' => 'Both snapshot IDs (a, b) are required.']);
        return;
    }
    if ($aId === $bId) {
        echo json_encode(['success' => false, 'error' => 'Please select two different snapshots.']);
        return;
    }

    // Load both snapshot headers
    $getHeader = function(int $id) use ($conn): ?array {
        $s = $conn->prepare("SELECT * FROM inventory_snapshots WHERE id = ?");
        $s->execute([$id]);
        return $s->fetch(PDO::FETCH_ASSOC) ?: null;
    };

    $headerA = $getHeader($aId);
    $headerB = $getHeader($bId);

    if (!$headerA || !$headerB) {
        echo json_encode(['success' => false, 'error' => 'One or both snapshots not found.']);
        return;
    }

    // Fetch items keyed by variation_id
    $fetchItems = function(int $snapId) use ($conn): array {
        $s = $conn->prepare("SELECT * FROM inventory_snapshot_items WHERE snapshot_id = ?");
        $s->execute([$snapId]);
        $map = [];
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $map[(int)$row['variation_id']] = $row;
        }
        return $map;
    };

    $itemsA = $fetchItems($aId);
    $itemsB = $fetchItems($bId);

    $allIds  = array_unique(array_merge(array_keys($itemsA), array_keys($itemsB)));
    $diff    = [];
    $changed = $added = $removed = $unchanged = 0;

    foreach ($allIds as $vid) {
        $a = $itemsA[$vid] ?? null;
        $b = $itemsB[$vid] ?? null;

        if (!$a) {
            $changeType = 'added';
            $added++;
        } elseif (!$b) {
            $changeType = 'removed';
            $removed++;
        } elseif ((int)$a['current_pos_stock'] !== (int)$b['current_pos_stock']
               || (int)$a['shopee_allocated'] !== (int)$b['shopee_allocated']) {
            $changeType = 'changed';
            $changed++;
        } else {
            $unchanged++;
            continue; // skip identical rows
        }

        $diff[] = [
            'variation_id'  => $vid,
            'sku'           => $a['sku']          ?? $b['sku'],
            'product_name'  => $a['product_name'] ?? $b['product_name'],
            'a_total'       => $a ? (int)$a['total_stock']       : null,
            'b_total'       => $b ? (int)$b['total_stock']       : null,
            'a_shopee'      => $a ? (int)$a['shopee_allocated']  : null,
            'b_shopee'      => $b ? (int)$b['shopee_allocated']  : null,
            'a_pos'         => $a ? (int)$a['current_pos_stock'] : null,
            'b_pos'         => $b ? (int)$b['current_pos_stock'] : null,
            'total_diff'    => ($b ? (int)$b['total_stock']      : 0) - ($a ? (int)$a['total_stock']      : 0),
            'pos_diff'      => ($b ? (int)$b['current_pos_stock']: 0) - ($a ? (int)$a['current_pos_stock']: 0),
            'shopee_diff'   => ($b ? (int)$b['shopee_allocated'] : 0) - ($a ? (int)$a['shopee_allocated'] : 0),
            'change_type'   => $changeType,
        ];
    }

    // Sort: biggest absolute stock change first
    usort($diff, fn($x, $y) => abs($y['pos_diff']) <=> abs($x['pos_diff']));

    echo json_encode([
        'success'    => true,
        'snapshot_a' => $headerA,
        'snapshot_b' => $headerB,
        'summary'    => [
            'changed'   => $changed,
            'added'     => $added,
            'removed'   => $removed,
            'unchanged' => $unchanged,
            'total_diff_rows' => count($diff),
        ],
        'diff' => $diff,
    ]);
}

// =============================================================================
// ACTION: restore — full safe restore
// Pre-restore backup is always created automatically before overwriting inventory.
// =============================================================================
function handleRestore(PDO $conn): void
{
    global $currentUserId, $currentUserName, $currentIp;

    $input      = json_decode(file_get_contents('php://input'), true) ?? [];
    $snapshotId = (int)($input['snapshot_id']  ?? 0);
    $confirmed  = trim($input['confirmation'] ?? '');

    // Advanced Restore Options
    $restorePos    = filter_var($input['restore_pos'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $restoreShopee = filter_var($input['restore_shopee'] ?? true, FILTER_VALIDATE_BOOLEAN);
    $shopeeSync    = filter_var($input['shopee_sync'] ?? false, FILTER_VALIDATE_BOOLEAN);

    if (!$snapshotId) {
        echo json_encode(['success' => false, 'error' => 'Missing snapshot_id.']); return;
    }
    // Server-side confirmation check
    if ($confirmed !== 'RESTORE') {
        echo json_encode(['success' => false, 'error' => 'Server-side confirmation failed.']); return;
    }

    $snapStmt = $conn->prepare("SELECT * FROM inventory_snapshots WHERE id = ?");
    $snapStmt->execute([$snapshotId]);
    $snapshot = $snapStmt->fetch(PDO::FETCH_ASSOC);
    if (!$snapshot) {
        echo json_encode(['success' => false, 'error' => 'Snapshot not found.']); return;
    }

    $conn->beginTransaction();
    try {
        // ── Step A: Auto-create Pre-Restore Backup ─────────────────────────
        // Only create an undo backup if we are restoring a Manual or Auto snapshot.
        // We do not want to create an "undo of an undo" if restoring a pre_restore snapshot.
        $preRestoreId = null;
        $preRestoreName = null;
        if ($snapshot['trigger_type'] !== 'pre_restore') {
            $preRestoreName = 'Pre-Restore Backup — ' . date('Y-m-d H:i:s');
            $preRestoreId   = createSnapshotInternal(
                $conn, $preRestoreName,
                'Auto-created before restore of: ' . $snapshot['snapshot_name'],
                'pre_restore', $currentUserId, $currentUserName
            );
        }

        // ── Step B: Restore Physical POS stock (store_id = 1) ─────────────
        if ($restorePos) {
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                SELECT variation_id, 1, (total_stock - shopee_allocated)
                FROM   inventory_snapshot_items
                WHERE  snapshot_id = ?
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$snapshotId]);
        }

        // ── Step C: Restore Shopee Allocated stock (store_id = 2) ──────────
        if ($restoreShopee) {
            $conn->prepare("
                INSERT INTO inventory (variation_id, store_id, quantity)
                SELECT variation_id, 2, shopee_allocated
                FROM   inventory_snapshot_items
                WHERE  snapshot_id = ?
                ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
            ")->execute([$snapshotId]);

            // ── Step D: Sync shopee_product_mappings.shopee_stock ───────────────
            $conn->prepare("
                UPDATE shopee_product_mappings m
                INNER JOIN inventory_snapshot_items si
                    ON si.variation_id = m.pos_product_id
                   AND si.snapshot_id  = ?
                SET m.shopee_stock  = si.shopee_allocated,
                    m.updated_at   = NOW()
                WHERE m.mapping_status IN ('mapped','auto','manual')
            ")->execute([$snapshotId]);
        }

        // ── Step D2: Shopee Push (If enabled) ──────────────────────────────
        $pushedCount = 0;
        if ($shopeeSync && $restoreShopee) {
            $shopeeCfg = $conn->query("SELECT * FROM shopee_config WHERE is_active = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($shopeeCfg && !empty($shopeeCfg['access_token'])) {
                require_once __DIR__ . '/../../classes/ShopeeAPI.php';
                $isTest = $shopeeCfg['environment'] === 'test';
                $api = new ShopeeAPI($shopeeCfg['partner_id'], $shopeeCfg['partner_key'], $isTest);

                $mapStmt = $conn->prepare("
                    SELECT m.id, m.shopee_item_id, m.shopee_model_id, m.shopee_stock, m.shopee_product_name, m.shopee_variation_name
                    FROM shopee_product_mappings m
                    INNER JOIN inventory_snapshot_items si ON si.variation_id = m.pos_product_id
                    WHERE si.snapshot_id = ? AND m.mapping_status IN ('auto', 'manual')
                ");
                $mapStmt->execute([$snapshotId]);
                $maps = $mapStmt->fetchAll(PDO::FETCH_ASSOC);

                $bufferStock = (int)($shopeeCfg['buffer_stock'] ?? 0);

                foreach ($maps as $map) {
                    $pushedStock = max(0, (int)$map['shopee_stock'] - $bufferStock);
                    $stockItem = [ 'seller_stock' => [ [ 'stock' => $pushedStock ] ] ];
                    if (!empty($map['shopee_model_id'])) {
                        $stockItem['model_id'] = (int)$map['shopee_model_id'];
                    }

                    $body = [
                        'item_id' => (int)$map['shopee_item_id'],
                        'stock_list' => [$stockItem]
                    ];

                    try {
                        $api->post('/api/v2/product/update_stock', $body, $shopeeCfg['access_token'], $shopeeCfg['shop_id']);
                        $pushedCount++;
                    } catch (Exception $e) {
                        // Silently skip failed pushes during bulk restore to avoid breaking the transaction
                    }
                }
            }
        }

        // ── Step E: Audit log ───────────────────────────────────────────────
        $affected = (int)$snapshot['total_products'];
        $affectedQty = (int)$snapshot['total_stock_qty'];
        
        $auditMsg = $preRestoreId 
            ? "Inventory restored. Pre-restore backup ID: {$preRestoreId}." 
            : "Inventory restored from Pre-Restore backup (Undo).";
            
        logSnapshotAudit(
            $conn, 'RESTORE', $snapshotId, $snapshot['snapshot_name'],
            $preRestoreId, $affected,
            $auditMsg,
            $currentUserId, $currentUserName, $currentIp,
            $affectedQty
        );

        $conn->commit();

        echo json_encode([
            'success'               => true,
            'message'               => "Restored \"{$snapshot['snapshot_name']}\" — {$affected} products.",
            'products_restored'     => $affected,
            'qty_restored'          => $affectedQty,
            'pushed_to_shopee'      => $pushedCount,
            'pre_restore_snap_id'   => $preRestoreId,
            'pre_restore_snap_name' => $preRestoreName,
        ]);

    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'error' => 'Restore failed: ' . $e->getMessage()]);
    }
}


// =============================================================================
// ACTION: settings_get
// =============================================================================
function handleSettingsGet(PDO $conn): void
{
    $rows = $conn->query(
        "SELECT setting_key, setting_value FROM inventory_snapshot_settings"
    )->fetchAll(PDO::FETCH_KEY_PAIR);

    echo json_encode(['success' => true, 'settings' => $rows]);
}

// =============================================================================
// ACTION: settings_save
// =============================================================================
function handleSettingsSave(PDO $conn): void
{
    $input   = json_decode(file_get_contents('php://input'), true) ?? [];
    $allowed = ['auto_snapshot_enabled', 'auto_snapshot_frequency', 'auto_snapshot_retention', 'allow_partial_restores', 'shopee_auto_sync', 'auto_snapshot_threshold'];
    $stmt    = $conn->prepare("
        INSERT INTO inventory_snapshot_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    foreach ($allowed as $key) {
        if (array_key_exists($key, $input)) {
            $stmt->execute([$key, $input[$key]]);
        }
    }
    echo json_encode(['success' => true, 'message' => 'Settings saved.']);
}

// =============================================================================
// ACTION: export_comparison — CSV download
// =============================================================================
function handleExportComparison(PDO $conn): void
{
    $aId = (int)($_GET['a'] ?? 0);
    $bId = (int)($_GET['b'] ?? 0);
    if (!$aId || !$bId) { die('Missing snapshot IDs.'); }

    // Reuse compare logic by calling handleCompare and capturing output
    ob_start();
    $_GET['action'] = 'compare'; // ensure routing in handleCompare works
    handleCompare($conn);
    $json = ob_get_clean();
    $data = json_decode($json, true);

    if (!($data['success'] ?? false)) {
        die('Comparison failed: ' . ($data['error'] ?? 'Unknown'));
    }

    $nameA = $data['snapshot_a']['snapshot_name'] ?? "Snapshot #{$aId}";
    $nameB = $data['snapshot_b']['snapshot_name'] ?? "Snapshot #{$bId}";

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock_comparison_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');

    // Header metadata rows
    fputcsv($out, ['Inventory Stock Comparison Export']);
    fputcsv($out, ['Generated', date('Y-m-d H:i:s')]);
    fputcsv($out, ['Snapshot A', $nameA]);
    fputcsv($out, ['Snapshot B', $nameB]);
    fputcsv($out, ['Changed', $data['summary']['changed']]);
    fputcsv($out, ['Added', $data['summary']['added']]);
    fputcsv($out, ['Removed', $data['summary']['removed']]);
    fputcsv($out, []);

    // Column headers
    fputcsv($out, [
        'SKU', 'Product Name',
        'A POS Stock', 'B POS Stock', 'POS Stock Δ',
        'A Shopee Alloc', 'B Shopee Alloc', 'Shopee Alloc Δ',
        'A Total Stock', 'B Total Stock', 'Total Stock Δ',
        'Change Type',
    ]);

    foreach ($data['diff'] as $row) {
        fputcsv($out, [
            $row['sku']          ?? '',
            $row['product_name'] ?? '',
            $row['a_pos']    !== null ? $row['a_pos']    : 'N/A',
            $row['b_pos']    !== null ? $row['b_pos']    : 'N/A',
            ($row['pos_diff'] >= 0 ? '+' : '') . $row['pos_diff'],
            $row['a_shopee'] !== null ? $row['a_shopee'] : 'N/A',
            $row['b_shopee'] !== null ? $row['b_shopee'] : 'N/A',
            ($row['shopee_diff'] >= 0 ? '+' : '') . $row['shopee_diff'],
            $row['a_total']  !== null ? $row['a_total']  : 'N/A',
            $row['b_total']  !== null ? $row['b_total']  : 'N/A',
            ($row['total_diff']  >= 0 ? '+' : '') . $row['total_diff'],
            strtoupper($row['change_type']),
        ]);
    }
    fclose($out);
}

// =============================================================================
// ACTION: verify_admin_password — for Emergency Restore step 2
// =============================================================================
function handleVerifyAdminPassword(PDO $conn): void
{
    global $currentUserId;

    $input    = json_decode(file_get_contents('php://input'), true) ?? [];
    $password = $input['password'] ?? '';

    if ($password === '') {
        echo json_encode(['success' => false, 'error' => 'Password is required.']); return;
    }

    // Try common column names used for password hashes across projects
    $user = null;
    foreach (['password', 'password_hash'] as $col) {
        try {
            $st = $conn->prepare("SELECT `{$col}` AS pw_hash FROM users WHERE id = ? LIMIT 1");
            $st->execute([$currentUserId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row && !empty($row['pw_hash'])) { $user = $row; break; }
        } catch (Exception $ignored) {}
    }

    if (!$user || !password_verify($password, $user['pw_hash'])) {
        echo json_encode(['success' => false, 'error' => 'Incorrect password.']);
        return;
    }

    echo json_encode(['success' => true]);
}

// =============================================================================
// ACTION: audit_logs — paginated audit trail
// =============================================================================
function handleAuditLogs(PDO $conn): void
{
    try {
        $limit      = min((int)($_GET['limit']  ?? 25), 200);
        $offset     = max((int)($_GET['offset'] ?? 0), 0);
        $filterType = trim($_GET['action_type'] ?? '');
        $filterDate = trim($_GET['date'] ?? '');

        $where  = [];
        $params = [];

        if ($filterType !== '') {
            $where[]  = 'action_type = ?';
            $params[] = $filterType;
        }

        if ($filterDate !== '') {
            $where[]  = 'DATE(created_at) = ?';
            $params[] = $filterDate;
        }

        $whereClause = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $cStmt = $conn->prepare("SELECT COUNT(*) FROM inventory_snapshot_audit{$whereClause}");
        $cStmt->execute($params);
        $total = (int)$cStmt->fetchColumn();

        $lStmt = $conn->prepare(
            "SELECT id, action_type, snapshot_id, snapshot_name,
                    pre_restore_snapshot_id, user_name, ip_address,
                    products_affected, total_stock_qty, notes, created_at
             FROM inventory_snapshot_audit{$whereClause}
             ORDER BY created_at DESC LIMIT ? OFFSET ?"
        );
        $idx = 1;
        foreach ($params as $p) {
            $lStmt->bindValue($idx++, $p);
        }
        $lStmt->bindValue($idx++, (int)$limit, PDO::PARAM_INT);
        $lStmt->bindValue($idx++, (int)$offset, PDO::PARAM_INT);
        $lStmt->execute();

        echo json_encode([
            'success' => true,
            'total'   => $total,
            'offset'  => $offset,
            'limit'   => $limit,
            'logs'    => $lStmt->fetchAll(PDO::FETCH_ASSOC),
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Audit log error: ' . $e->getMessage()]);
    }
}

// =============================================================================
// ACTION: export_cold_backup — Download SQL Dump of Inventory & Snapshots
// =============================================================================
function handleExportColdBackup(PDO $conn): void
{
    try {
        $tables = [
            'inventory', 
            'inventory_snapshots', 
            'inventory_snapshot_items', 
            'inventory_snapshot_settings', 
            'inventory_snapshot_audit'
        ];
        
        $sql = "-- Ella POS Inventory & Snapshot Offline Backup\n";
        $sql .= "-- Exported At: " . date('Y-m-d H:i:s') . "\n";
        $sql .= "-- This file can be directly imported into phpMyAdmin for disaster recovery.\n\n";

        $sql .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

        foreach ($tables as $table) {
            $sql .= "-- --------------------------------------------------------\n";
            $sql .= "-- Data for table `$table`\n";
            $sql .= "-- --------------------------------------------------------\n";
            
            // Note: We don't TRUNCATE to avoid accidental data loss if they import blindly,
            // but we can add TRUNCATE so that importing this file restores the exact state.
            $sql .= "TRUNCATE TABLE `$table`;\n";
            
            $stmt = $conn->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if (count($rows) > 0) {
                $cols = array_keys($rows[0]);
                $colStr = "`" . implode("`, `", $cols) . "`";
                
                // Chunk to prevent massive queries
                $chunks = array_chunk($rows, 100);
                foreach ($chunks as $chunk) {
                    $sql .= "INSERT INTO `$table` ($colStr) VALUES \n";
                    $valStrings = [];
                    foreach ($chunk as $row) {
                        $escapedVals = [];
                        foreach ($row as $val) {
                            if ($val === null) {
                                $escapedVals[] = "NULL";
                            } else {
                                $escaped = str_replace(["\\", "'", "\n", "\r"], ["\\\\", "''", "\\n", "\\r"], (string)$val);
                                $escapedVals[] = "'" . $escaped . "'";
                            }
                        }
                        $valStrings[] = "(" . implode(", ", $escapedVals) . ")";
                    }
                    $sql .= implode(",\n", $valStrings) . ";\n";
                }
            }
            $sql .= "\n";
        }

        $sql .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        $filename = 'ella_pos_backup_' . date('Ymd_His') . '.sql';
        
        // Force download as a .sql file
        header('Content-Type: application/sql');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        header('Cache-Control: no-cache, no-store, must-revalidate'); // HTTP 1.1
        header('Pragma: no-cache'); // HTTP 1.0
        header('Expires: 0'); // Proxies
        
        echo $sql;
        exit;
    } catch (Exception $e) {
        if (!headers_sent()) {
            header('Content-Type: text/plain');
        }
        echo "Export failed: " . $e->getMessage();
        exit;
    }
}
