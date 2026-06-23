<?php
/**
 * api/inventory/sync_inventory.php
 *
 * Rebuilds local inventory from stock-changing movement history, then reapplies
 * Shopee allocation as the online stock split. GET previews only; POST applies.
 */
declare(strict_types=1);

require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../includes/auth.php';

requireLogin();

$role = $_SESSION['role'] ?? '';
if ($role !== 'admin' && !hasPermission('adjust_prices') && !in_array($role, ['manager', 'stockman'], true)) {
    denyAccess('You do not have permission to sync inventory.');
}

header('Content-Type: text/html; charset=UTF-8');

$stockChangingTypes = [
    'stock_in',
    'stock_out',
    'sales',
    'adjustment',
    'return',
    'online_sale',
    'online_adjustment',
];

function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $conn, string $table): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function columnExists(PDO $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function fetchProductLabels(PDO $conn): array
{
    $rows = $conn->query("
        SELECT
            v.variation_id,
            p.product_name,
            p.brand_name,
            v.variation_name,
            v.sku,
            v.barcode
        FROM product_variations v
        LEFT JOIN products p ON p.product_id = v.product_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    $labels = [];
    foreach ($rows as $row) {
        $variationId = (int)$row['variation_id'];
        $parts = array_filter([
            trim((string)($row['brand_name'] ?? '')),
            trim((string)($row['product_name'] ?? '')),
            trim((string)($row['variation_name'] ?? '')),
        ]);

        $labels[$variationId] = [
            'name' => $parts ? implode(' - ', $parts) : 'Variation #' . $variationId,
            'sku' => trim((string)($row['sku'] ?? '')),
            'barcode' => trim((string)($row['barcode'] ?? '')),
        ];
    }

    return $labels;
}

function fetchCurrentInventory(PDO $conn): array
{
    $rows = $conn->query("
        SELECT variation_id, store_id, quantity
        FROM inventory
        WHERE store_id IN (1, 2)
    ")->fetchAll(PDO::FETCH_ASSOC);

    $inventory = [];
    foreach ($rows as $row) {
        $variationId = (int)$row['variation_id'];
        $storeId = (int)$row['store_id'];
        $inventory[$variationId][$storeId] = (float) $row['quantity'];
    }

    return $inventory;
}

function fetchBaseStock(PDO $conn, bool $hasStatusColumn): array
{
    $statusSql = $hasStatusColumn ? "AND COALESCE(sm.status, '') <> 'voided'" : '';

    $stmt = $conn->prepare("
        SELECT
            sm.variation_id,
            COALESCE(SUM(sm.new_stock - sm.previous_stock), 0) AS base_stock
        FROM stock_movements sm
        INNER JOIN product_variations v ON v.variation_id = sm.variation_id
        WHERE sm.store_id = 1
          $statusSql
        GROUP BY sm.variation_id
    ");
    $stmt->execute();

    $baseStock = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $baseStock[(int)$row['variation_id']] = (int)$row['base_stock'];
    }

    return $baseStock;
}

function fetchShopeeAllocation(PDO $conn, bool $hasBundleColumn, array &$warnings): array
{
    $allocation = [];
    $activeMapped = [];

    if (!tableExists($conn, 'shopee_product_mappings')) {
        $warnings[] = 'Shopee mapping table is not installed; Shopee allocation was skipped.';
        return ['allocation' => $allocation, 'activeMapped' => $activeMapped, 'bundleRows' => 0];
    }

    $bundleFilter = $hasBundleColumn ? 'AND (m.pos_bundle_set_id IS NULL OR m.pos_bundle_set_id = 0)' : '';
    $stmt = $conn->query("
        SELECT
            m.pos_product_id AS variation_id,
            COALESCE(SUM(GREATEST(m.shopee_stock, 0) * COALESCE(NULLIF(u.multiplier, 0), 1)), 0) AS allocated_stock,
            COUNT(*) AS mapping_count
        FROM shopee_product_mappings m
        LEFT JOIN product_units u ON u.id = m.pos_unit_id
        WHERE m.mapping_status IN ('auto', 'manual')
          AND m.pos_product_id IS NOT NULL
          $bundleFilter
        GROUP BY m.pos_product_id
    ");

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $variationId = (int)$row['variation_id'];
        $allocation[$variationId] = ($allocation[$variationId] ?? 0) + (int)$row['allocated_stock'];
        $activeMapped[$variationId] = ($activeMapped[$variationId] ?? 0) + (int)$row['mapping_count'];
    }

    $bundleRows = 0;
    if ($hasBundleColumn) {
        $hasBundleTables = tableExists($conn, 'product_unit_sets') && tableExists($conn, 'product_unit_set_items');
        if ($hasBundleTables) {
            $bundleStmt = $conn->query("
                SELECT
                    si.component_variation_id AS variation_id,
                    COALESCE(SUM(
                        GREATEST(m.shopee_stock, 0)
                        * si.component_qty
                        * COALESCE(NULLIF(cu.multiplier, 0), 1)
                    ), 0) AS allocated_stock,
                    COUNT(DISTINCT m.id) AS mapping_count
                FROM shopee_product_mappings m
                INNER JOIN product_unit_set_items si ON si.product_set_id = m.pos_bundle_set_id
                LEFT JOIN product_units cu ON cu.id = si.component_unit_id
                WHERE m.mapping_status IN ('auto', 'manual')
                  AND m.pos_bundle_set_id IS NOT NULL
                  AND m.pos_bundle_set_id > 0
                GROUP BY si.component_variation_id
            ");

            foreach ($bundleStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $variationId = (int)$row['variation_id'];
                $allocation[$variationId] = ($allocation[$variationId] ?? 0) + (int)$row['allocated_stock'];
                $activeMapped[$variationId] = ($activeMapped[$variationId] ?? 0) + (int)$row['mapping_count'];
                $bundleRows++;
            }
        } else {
            $bundleCount = (int)$conn->query("
                SELECT COUNT(*)
                FROM shopee_product_mappings
                WHERE mapping_status IN ('auto', 'manual')
                  AND pos_bundle_set_id IS NOT NULL
                  AND pos_bundle_set_id > 0
            ")->fetchColumn();

            if ($bundleCount > 0) {
                $warnings[] = "Bundle mappings exist ({$bundleCount}), but bundle tables are not installed. Bundle allocation was skipped.";
            }
        }
    } else {
        $warnings[] = 'Shopee bundle allocation column is not installed. Bundle allocation was skipped.';
    }

    return ['allocation' => $allocation, 'activeMapped' => $activeMapped, 'bundleRows' => $bundleRows];
}

function buildSyncReport(PDO $conn): array
{
    $warnings = [];
    $hasStatusColumn = columnExists($conn, 'stock_movements', 'status');
    $hasBundleColumn = columnExists($conn, 'shopee_product_mappings', 'pos_bundle_set_id');

    if (!$hasStatusColumn) {
        $warnings[] = 'stock_movements.status is not installed. Voided movement exclusion was skipped.';
    }

    $labels = fetchProductLabels($conn);
    $inventory = fetchCurrentInventory($conn);
    $baseStock = fetchBaseStock($conn, $hasStatusColumn);
    $shopee = fetchShopeeAllocation($conn, $hasBundleColumn, $warnings);
    $allocation = $shopee['allocation'];
    $activeMapped = $shopee['activeMapped'];

    $variationIds = array_unique(array_merge(
        array_keys($labels),
        array_keys($inventory),
        array_keys($baseStock),
        array_keys($allocation)
    ));
    sort($variationIds, SORT_NUMERIC);

    $rows = [];
    $stats = [
        'reviewed' => 0,
        'insert_rows' => 0,
        'update_rows' => 0,
        'unchanged_variations' => 0,
        'changed_variations' => 0,
        'orphan_online_rows' => 0,
        'capped_allocations' => 0,
        'negative_base_floors' => 0,
        'active_mapped_variations' => count($activeMapped),
        'bundle_component_rows' => (int)$shopee['bundleRows'],
    ];

    foreach ($variationIds as $variationId) {
        $variationId = (int)$variationId;
        $rawBaseStock = (int)($baseStock[$variationId] ?? 0);
        $targetPhysicalStock = max(0, $rawBaseStock);
        $rawShopeeAllocation = max(0, (int)($allocation[$variationId] ?? 0));
        $targetOnlineStock = $rawShopeeAllocation;
        $targetTotalStock = $targetPhysicalStock + $targetOnlineStock;

        $currentPhysicalStock = $inventory[$variationId][1] ?? null;
        $currentOnlineStock = $inventory[$variationId][2] ?? null;

        $physicalAction = $currentPhysicalStock === null
            ? ($targetPhysicalStock !== 0 ? 'insert' : 'none')
            : ((int)$currentPhysicalStock !== $targetPhysicalStock ? 'update' : 'unchanged');

        $onlineAction = $currentOnlineStock === null
            ? ($targetOnlineStock !== 0 ? 'insert' : 'none')
            : ((int)$currentOnlineStock !== $targetOnlineStock ? 'update' : 'unchanged');

        $isChanged = in_array($physicalAction, ['insert', 'update'], true)
            || in_array($onlineAction, ['insert', 'update'], true);
        $isOrphanOnline = !isset($activeMapped[$variationId])
            && $currentOnlineStock !== null
            && (int)$currentOnlineStock !== 0
            && $targetOnlineStock === 0;
        $isCapped = $rawShopeeAllocation > $targetTotalStock;

        $stats['reviewed']++;
        if ($physicalAction === 'insert') {
            $stats['insert_rows']++;
        } elseif ($physicalAction === 'update') {
            $stats['update_rows']++;
        }

        if ($onlineAction === 'insert') {
            $stats['insert_rows']++;
        } elseif ($onlineAction === 'update') {
            $stats['update_rows']++;
        }

        if ($isChanged) {
            $stats['changed_variations']++;
        } else {
            $stats['unchanged_variations']++;
        }

        if ($isOrphanOnline) {
            $stats['orphan_online_rows']++;
        }
        if ($isCapped) {
            $stats['capped_allocations']++;
        }
        if ($baseWasNegative) {
            $stats['negative_base_floors']++;
        }

        $notes = [];
        if ($isOrphanOnline) {
            $notes[] = 'orphan online stock will be cleared';
        }
        if ($isCapped) {
            $notes[] = 'Shopee allocation capped to base stock';
        }
        if ($baseWasNegative) {
            $notes[] = 'negative ledger stock floored to zero';
        }
        if (isset($activeMapped[$variationId])) {
            $notes[] = $activeMapped[$variationId] . ' active Shopee mapping(s)';
        }

        $rows[] = [
            'variation_id' => $variationId,
            'name' => $labels[$variationId]['name'] ?? 'Variation #' . $variationId,
            'sku' => $labels[$variationId]['sku'] ?? '',
            'barcode' => $labels[$variationId]['barcode'] ?? '',
            'raw_base_stock' => $rawBaseStock,
            'target_total_stock' => $targetTotalStock,
            'raw_shopee_allocation' => $rawShopeeAllocation,
            'target_physical_stock' => $targetPhysicalStock,
            'target_online_stock' => $targetOnlineStock,
            'current_physical_stock' => $currentPhysicalStock,
            'current_online_stock' => $currentOnlineStock,
            'physical_action' => $physicalAction,
            'online_action' => $onlineAction,
            'changed' => $isChanged,
            'orphan_online' => $isOrphanOnline,
            'capped' => $isCapped,
            'notes' => implode('; ', $notes),
        ];
    }

    usort($rows, static function (array $a, array $b): int {
        if ($a['changed'] !== $b['changed']) {
            return $a['changed'] ? -1 : 1;
        }
        if ($a['orphan_online'] !== $b['orphan_online']) {
            return $a['orphan_online'] ? -1 : 1;
        }
        if ($a['capped'] !== $b['capped']) {
            return $a['capped'] ? -1 : 1;
        }
        return $a['variation_id'] <=> $b['variation_id'];
    });

    $blankShopeeMovementCount = (int)$conn->query("
        SELECT COUNT(*)
        FROM stock_movements
        WHERE (type IS NULL OR type = '')
          AND reference LIKE 'SHP-%'
    ")->fetchColumn();

    if ($blankShopeeMovementCount > 0) {
        $warnings[] = "{$blankShopeeMovementCount} historical SHP movement row(s) have blank type values. They are excluded from stock rebuilding.";
    }

    return [
        'rows' => $rows,
        'stats' => $stats,
        'warnings' => $warnings,
        'blankShopeeMovementCount' => $blankShopeeMovementCount,
    ];
}

function applyInventoryTargets(PDO $conn, array $rows): array
{
    $selectStmt = $conn->prepare("
        SELECT quantity
        FROM inventory
        WHERE variation_id = ?
          AND store_id = ?
        FOR UPDATE
    ");
    $insertStmt = $conn->prepare("
        INSERT INTO inventory (variation_id, store_id, quantity)
        VALUES (?, ?, ?)
    ");
    $updateStmt = $conn->prepare("
        UPDATE inventory
        SET quantity = ?
        WHERE variation_id = ?
          AND store_id = ?
    ");

    $applied = [
        'insert_rows' => 0,
        'update_rows' => 0,
        'skipped_rows' => 0,
    ];

    foreach ($rows as $row) {
        $variationId = (int)$row['variation_id'];
        $targets = [
            1 => (int)$row['target_physical_stock'],
            2 => (int)$row['target_online_stock'],
        ];

        foreach ($targets as $storeId => $quantity) {
            $selectStmt->execute([$variationId, $storeId]);
            $current = $selectStmt->fetchColumn();

            if ($current === false) {
                if ($quantity === 0) {
                    $applied['skipped_rows']++;
                    continue;
                }

                $insertStmt->execute([$variationId, $storeId, $quantity]);
                $applied['insert_rows']++;
                continue;
            }

            if ((int)$current !== $quantity) {
                $updateStmt->execute([$quantity, $variationId, $storeId]);
                $applied['update_rows']++;
            } else {
                $applied['skipped_rows']++;
            }
        }
    }

    return $applied;
}

$mode = 'preview';
$applied = null;
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();

    $report = buildSyncReport($conn, $stockChangingTypes);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'apply') {
        $conn->beginTransaction();
        $applied = applyInventoryTargets($conn, $report['rows']);
        $conn->commit();
        $mode = 'applied';
        $report = buildSyncReport($conn, $stockChangingTypes);
    }
} catch (Exception $e) {
    if (isset($conn) && $conn instanceof PDO && $conn->inTransaction()) {
        $conn->rollBack();
    }
    $report = $report ?? ['rows' => [], 'stats' => [], 'warnings' => []];
    $error = $e->getMessage();
}

$stats = $report['stats'] ?? [];
$rows = $report['rows'] ?? [];
$warnings = $report['warnings'] ?? [];
$changedRows = array_values(array_filter($rows, static fn(array $row): bool => (bool)$row['changed']));
$unchangedRows = array_values(array_filter($rows, static fn(array $row): bool => !(bool)$row['changed']));
$displayRows = array_slice(array_merge($changedRows, array_slice($unchangedRows, 0, 50)), 0, 1000);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory Sync</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 24px; color: #1f2937; background: #f8fafc; }
        .shell { max-width: 1280px; margin: 0 auto; }
        .panel { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 18px; margin-bottom: 16px; }
        h1 { font-size: 24px; margin: 0 0 6px; }
        h2 { font-size: 18px; margin: 0 0 12px; }
        p { margin: 6px 0; }
        .muted { color: #64748b; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 10px; }
        .stat { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; background: #f9fafb; }
        .stat strong { display: block; font-size: 22px; margin-bottom: 4px; }
        .warnings { border-color: #f59e0b; background: #fffbeb; }
        .success { border-color: #22c55e; background: #f0fdf4; }
        .error { border-color: #ef4444; background: #fef2f2; color: #991b1b; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        button, .btn { border: 0; border-radius: 6px; padding: 10px 14px; font-weight: 700; cursor: pointer; text-decoration: none; display: inline-block; }
        button { background: #dc2626; color: #fff; }
        .btn { background: #e5e7eb; color: #111827; }
        table { width: 100%; border-collapse: collapse; background: #fff; font-size: 13px; }
        th, td { border-bottom: 1px solid #e5e7eb; padding: 8px; vertical-align: top; text-align: left; }
        th { background: #f1f5f9; font-size: 12px; text-transform: uppercase; letter-spacing: .04em; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .badge { display: inline-block; border-radius: 999px; padding: 2px 8px; font-size: 12px; font-weight: 700; background: #e5e7eb; color: #374151; }
        .badge.update, .badge.insert { background: #dbeafe; color: #1d4ed8; }
        .badge.none, .badge.unchanged { background: #f3f4f6; color: #6b7280; }
        .note { color: #92400e; }
        .product { min-width: 260px; }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>
<div class="shell">
    <div class="panel">
        <h1>Inventory Synchronization Tool</h1>
        <p class="muted">
            Rebuilds total stock from stock-changing movements and reapplies active Shopee allocation as online stock.
            GET is preview only. POST apply writes local inventory rows. No Shopee API calls are made.
        </p>
    </div>

    <?php if ($error): ?>
        <div class="panel error">
            <h2>Sync Error</h2>
            <p><?= e($error) ?></p>
        </div>
    <?php endif; ?>

    <?php if ($applied): ?>
        <div class="panel success">
            <h2>Changes Applied</h2>
            <p>
                Inserted <?= number_format($applied['insert_rows']) ?> row(s),
                updated <?= number_format($applied['update_rows']) ?> row(s),
                skipped <?= number_format($applied['skipped_rows']) ?> already-correct or zero-missing row(s).
            </p>
        </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="panel warnings">
            <h2>Warnings</h2>
            <?php foreach ($warnings as $warning): ?>
                <p><?= e($warning) ?></p>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="panel">
        <h2><?= $mode === 'applied' ? 'Post-Apply Summary' : 'Preview Summary' ?></h2>
        <div class="grid">
            <div class="stat"><strong><?= number_format($stats['reviewed'] ?? 0) ?></strong><span>Variations reviewed</span></div>
            <div class="stat"><strong><?= number_format($stats['changed_variations'] ?? 0) ?></strong><span>Variations needing changes</span></div>
            <div class="stat"><strong><?= number_format($stats['insert_rows'] ?? 0) ?></strong><span>Inventory rows to insert</span></div>
            <div class="stat"><strong><?= number_format($stats['update_rows'] ?? 0) ?></strong><span>Inventory rows to update</span></div>
            <div class="stat"><strong><?= number_format($stats['orphan_online_rows'] ?? 0) ?></strong><span>Orphan online rows to clear</span></div>
            <div class="stat"><strong><?= number_format($stats['capped_allocations'] ?? 0) ?></strong><span>Capped Shopee allocations</span></div>
            <div class="stat"><strong><?= number_format($stats['active_mapped_variations'] ?? 0) ?></strong><span>Active mapped variations</span></div>
            <div class="stat"><strong><?= number_format($stats['bundle_component_rows'] ?? 0) ?></strong><span>Bundle component allocation rows</span></div>
        </div>
    </div>

    <div class="panel">
        <div class="actions">
            <form method="POST" onsubmit="return confirm('Apply this local inventory reconciliation now? This will update inventory store 1 and store 2 quantities.');">
                <button type="submit" name="action" value="apply">Apply Local Inventory Sync</button>
            </form>
            <a class="btn" href="<?= e($_SERVER['PHP_SELF'] ?? 'sync_inventory.php') ?>">Refresh Preview</a>
            <a class="btn" href="../../views/inventory/index.php">Back to Inventory</a>
        </div>
        <p class="muted">Only changed rows plus the first 50 unchanged rows are shown below.</p>
    </div>

    <div class="panel">
        <h2>Reconciliation Rows</h2>
        <table>
            <thead>
                <tr>
                    <th>Variation</th>
                    <th>Product</th>
                    <th class="num">Ledger Total</th>
                    <th class="num">Shopee Allocation</th>
                    <th class="num">Current Physical</th>
                    <th class="num">Target Physical</th>
                    <th class="num">Current Online</th>
                    <th class="num">Target Online</th>
                    <th>Action</th>
                    <th>Notes</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($displayRows)): ?>
                <tr><td colspan="10" class="muted">No inventory rows found.</td></tr>
            <?php endif; ?>
            <?php foreach ($displayRows as $row): ?>
                <tr>
                    <td class="nowrap">
                        <strong>#<?= number_format((int)$row['variation_id']) ?></strong><br>
                        <span class="muted"><?= e($row['sku'] ?: $row['barcode'] ?: '-') ?></span>
                    </td>
                    <td class="product"><?= e($row['name']) ?></td>
                    <td class="num"><?= number_format((int)$row['target_total_stock']) ?></td>
                    <td class="num">
                        <?= number_format((int)$row['raw_shopee_allocation']) ?>
                        <?php if ($row['capped']): ?><br><span class="note">capped</span><?php endif; ?>
                    </td>
                    <td class="num"><?= $row['current_physical_stock'] === null ? '-' : number_format((int)$row['current_physical_stock']) ?></td>
                    <td class="num"><strong><?= number_format((int)$row['target_physical_stock']) ?></strong></td>
                    <td class="num"><?= $row['current_online_stock'] === null ? '-' : number_format((int)$row['current_online_stock']) ?></td>
                    <td class="num"><strong><?= number_format((int)$row['target_online_stock']) ?></strong></td>
                    <td class="nowrap">
                        <span class="badge <?= e($row['physical_action']) ?>">P: <?= e($row['physical_action']) ?></span><br>
                        <span class="badge <?= e($row['online_action']) ?>">O: <?= e($row['online_action']) ?></span>
                    </td>
                    <td><?= $row['notes'] ? e($row['notes']) : '<span class="muted">-</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
