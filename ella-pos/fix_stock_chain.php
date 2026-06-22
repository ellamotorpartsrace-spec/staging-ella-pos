<?php
/**
 * fix_stock_chain.php
 * Diagnoses and repairs stock_movements chain integrity.
 *
 * WHAT IT DOES:
 *   1. For each variation, replay stock_movements in chronological order (by movement_id).
 *   2. Compute what previous_stock and new_stock SHOULD be based on the deltas (quantity changes).
 *   3. Report discrepancies.
 *   4. On POST ?action=fix, rewrite previous_stock / new_stock so the chain is consistent.
 *   5. After repairing the chain, also correct the inventory table (store_id=1) to match the
 *      final computed balance.
 *
 * SAFE TO RUN: GET is read-only. POST ?action=fix writes to the DB inside a transaction.
 */
declare(strict_types=1);

require_once 'config/config.php';
require_once 'config/database.php';
require_once 'includes/auth.php';

requireLogin();
if (!in_array($_SESSION['role'] ?? '', ['admin', 'super_admin'])) {
    die('Access denied. Admins only.');
}

$db   = new Database();
$conn = $db->getConnection();
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// ─── helpers ────────────────────────────────────────────────────────────────
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$action  = $_GET['action'] ?? 'report';
$limitTo = (int)($_GET['variation_id'] ?? 0); // 0 = all

// ─── Stock-changing movement types ──────────────────────────────────────────
// 'sales' and 'online_sale' carry NEGATIVE deltas in quantity column.
// Everything else is positive (stock_in, adjustment, return, online_adjustment).
// We rely on (new_stock - previous_stock) = net delta for a movement.
// For sales/online_sale the stored quantity is POSITIVE but represents a subtraction.
// We use the signed formula: delta = new_stock - previous_stock.

$STOCK_CHANGING = ['stock_in','stock_out','sales','adjustment','return','online_sale','online_adjustment'];
$in             = implode(',', array_fill(0, count($STOCK_CHANGING), '?'));

// ─── Fetch all relevant movements ordered by variation + movement_id ─────────
$varFilter = $limitTo > 0 ? 'AND sm.variation_id = ?' : '';
$params    = $STOCK_CHANGING;
if ($limitTo > 0) $params[] = $limitTo;

$stmt = $conn->prepare("
    SELECT
        sm.movement_id,
        sm.variation_id,
        sm.store_id,
        sm.type,
        sm.quantity,
        sm.previous_stock,
        sm.new_stock,
        sm.created_at,
        COALESCE(sm.status,'') AS status,
        p.product_name,
        pv.variation_name,
        pv.sku
    FROM stock_movements sm
    LEFT JOIN product_variations pv ON pv.variation_id = sm.variation_id
    LEFT JOIN products p ON p.product_id = pv.product_id
    WHERE sm.type IN ($in)
      AND sm.store_id = 1
      $varFilter
    ORDER BY sm.variation_id ASC, sm.movement_id ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── Group by variation ──────────────────────────────────────────────────────
$byVar = [];
foreach ($rows as $row) {
    $vid = (int)$row['variation_id'];
    $byVar[$vid][] = $row;
}

// ─── Replay chain for each variation ────────────────────────────────────────
$brokenVariations = [];
$allFixes         = []; // [ movement_id => [prev, new] ]

foreach ($byVar as $vid => $movements) {
    $runningBalance = null; // start unknown — seed from first movement's previous_stock
    $broken         = [];

    foreach ($movements as $idx => $m) {
        if (($m['status'] ?? '') === 'voided') {
            // Voided movements should be no-ops: previous_stock == new_stock
            // They don't change the running balance.
            $expectedPrev = $runningBalance ?? (float)$m['previous_stock'];
            $expectedNew  = $expectedPrev; // no change
            if (abs((float)$m['previous_stock'] - $expectedPrev) > 0.001 ||
                abs((float)$m['new_stock']       - $expectedNew)  > 0.001) {
                $broken[] = array_merge($m, [
                    'expected_prev' => $expectedPrev,
                    'expected_new'  => $expectedNew,
                ]);
                $allFixes[$m['movement_id']] = [$expectedPrev, $expectedNew];
            }
            // runningBalance stays the same
            if ($runningBalance === null) $runningBalance = $expectedPrev;
            continue;
        }

        // For the very first movement, seed the running balance from its own previous_stock
        if ($runningBalance === null) {
            $runningBalance = (float)$m['previous_stock'];
        }

        // Delta = signed change recorded in this movement
        $recordedDelta = (float)$m['new_stock'] - (float)$m['previous_stock'];

        // What the delta SHOULD be (using the quantity column, sign aware)
        // For sales/online_sale: quantity is a positive number representing a subtraction
        $computedDelta = match($m['type']) {
            'sales', 'online_sale' => -(float)$m['quantity'],
            'stock_out'            => -(float)$m['quantity'],
            default                =>  (float)$m['quantity'],
        };

        $expectedPrev = $runningBalance;
        $expectedNew  = round($runningBalance + $computedDelta, 4);

        // Check if recorded values match expected
        $prevOk = abs((float)$m['previous_stock'] - $expectedPrev) <= 0.001;
        $newOk  = abs((float)$m['new_stock']       - $expectedNew)  <= 0.001;

        if (!$prevOk || !$newOk) {
            $broken[] = array_merge($m, [
                'expected_prev'  => $expectedPrev,
                'expected_new'   => $expectedNew,
                'computed_delta' => $computedDelta,
            ]);
            $allFixes[$m['movement_id']] = [$expectedPrev, $expectedNew];
        }

        $runningBalance = $expectedNew;
    }

    if (!empty($broken)) {
        $label = trim(($movements[0]['product_name'] ?? '') . ' ' . ($movements[0]['variation_name'] ?? ''));
        $brokenVariations[$vid] = [
            'label'      => $label ?: "Variation #$vid",
            'sku'        => $movements[0]['sku'] ?? '',
            'movements'  => $movements,
            'broken'     => $broken,
            'final_balance' => $runningBalance,
        ];
    }
}

// ─── Fetch current inventory for comparison ──────────────────────────────────
$currentInventory = [];
$invStmt = $conn->query("SELECT variation_id, quantity FROM inventory WHERE store_id = 1");
foreach ($invStmt->fetchAll(PDO::FETCH_ASSOC) as $inv) {
    $currentInventory[(int)$inv['variation_id']] = (float)$inv['quantity'];
}

// Also compute expected final balances for ALL variations (even unbroken) to catch inventory drift
$expectedFinalBalance = [];
foreach ($byVar as $vid => $movements) {
    $runningBalance = null;
    foreach ($movements as $m) {
        if (($m['status'] ?? '') === 'voided') {
            if ($runningBalance === null) $runningBalance = (float)$m['previous_stock'];
            continue;
        }
        if ($runningBalance === null) $runningBalance = (float)$m['previous_stock'];
        $delta = match($m['type']) {
            'sales', 'online_sale' => -(float)$m['quantity'],
            'stock_out'            => -(float)$m['quantity'],
            default                =>  (float)$m['quantity'],
        };
        $runningBalance = round($runningBalance + $delta, 4);
    }
    $expectedFinalBalance[$vid] = $runningBalance ?? 0;
}

// ─── APPLY FIX ───────────────────────────────────────────────────────────────
$fixResults = null;
if ($action === 'fix' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        $fixStmt = $conn->prepare("
            UPDATE stock_movements
            SET previous_stock = ?, new_stock = ?
            WHERE movement_id = ?
        ");

        $fixCount = 0;
        foreach ($allFixes as $movId => [$prev, $new]) {
            $fixStmt->execute([$prev, $new, $movId]);
            $fixCount++;
        }

        // Also correct inventory table to match the computed final balance
        $inventoryFixCount = 0;
        $invFixStmt  = $conn->prepare("
            INSERT INTO inventory (variation_id, store_id, quantity)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE quantity = ?
        ");
        foreach ($expectedFinalBalance as $vid => $finalBal) {
            $current = $currentInventory[$vid] ?? null;
            if ($current === null || abs($current - $finalBal) > 0.001) {
                $invFixStmt->execute([$vid, $finalBal, $finalBal]);
                $inventoryFixCount++;
            }
        }

        $conn->commit();
        $fixResults = [
            'movement_rows_fixed' => $fixCount,
            'inventory_rows_fixed' => $inventoryFixCount,
        ];

        // Reload after fix
        header("Location: fix_stock_chain.php?action=report&fixed=1" . ($limitTo ? "&variation_id=$limitTo" : ''));
        exit;

    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $fixError = $e->getMessage();
    }
}

$totalBroken = count($allFixes);
$totalVariationsBroken = count($brokenVariations);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Stock Chain Diagnostic & Repair</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; padding: 24px; }
  h1 { font-size: 1.8rem; font-weight: 800; margin-bottom: 4px; color: #f8fafc; }
  h2 { font-size: 1.15rem; font-weight: 700; color: #94a3b8; margin-bottom: 12px; }
  h3 { font-size: 1rem; font-weight: 700; color: #f1f5f9; margin: 0 0 8px; }
  .shell { max-width: 1400px; margin: 0 auto; }
  .card { background: #1e293b; border: 1px solid #334155; border-radius: 12px; padding: 20px; margin-bottom: 18px; }
  .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px,1fr)); gap: 12px; margin-bottom: 18px; }
  .stat { background: #0f172a; border: 1px solid #334155; border-radius: 10px; padding: 16px; }
  .stat .num { font-size: 2rem; font-weight: 800; color: #38bdf8; }
  .stat .num.red  { color: #f87171; }
  .stat .num.grn  { color: #4ade80; }
  .stat .lbl { font-size: .78rem; color: #94a3b8; margin-top: 4px; }
  .btn { display: inline-block; padding: 10px 22px; border-radius: 8px; font-weight: 700; font-size: .9rem; cursor: pointer; border: none; text-decoration: none; }
  .btn-red    { background: #ef4444; color: #fff; }
  .btn-blue   { background: #3b82f6; color: #fff; }
  .btn-gray   { background: #334155; color: #e2e8f0; }
  .btn:hover  { opacity: .85; }
  .alert      { border-radius: 10px; padding: 14px 18px; margin-bottom: 14px; font-weight: 600; }
  .alert-ok   { background: #14532d; border: 1px solid #22c55e; color: #bbf7d0; }
  .alert-warn { background: #78350f; border: 1px solid #f59e0b; color: #fef3c7; }
  .alert-err  { background: #450a0a; border: 1px solid #ef4444; color: #fecaca; }
  table { width: 100%; border-collapse: collapse; font-size: .82rem; }
  th { background: #0f172a; color: #94a3b8; text-transform: uppercase; letter-spacing: .05em; padding: 8px 10px; text-align: left; font-size: .72rem; }
  td { padding: 7px 10px; border-bottom: 1px solid #1e293b; vertical-align: top; }
  tr:hover td { background: #1e3a5f22; }
  .bad  { color: #f87171; font-weight: 700; }
  .ok   { color: #4ade80; }
  .muted { color: #64748b; }
  .tag { display: inline-block; font-size: .7rem; padding: 2px 8px; border-radius: 99px; font-weight: 700; }
  .tag-sales { background: #7f1d1d; color: #fca5a5; }
  .tag-in    { background: #14532d; color: #86efac; }
  .tag-adj   { background: #1e3a5f; color: #7dd3fc; }
  .tag-ret   { background: #3b0764; color: #d8b4fe; }
  .tag-out   { background: #431407; color: #fdba74; }
  .tag-void  { background: #1e293b; color: #64748b; }
  .variation-block { margin-bottom: 24px; }
  .vname { font-weight: 700; color: #f1f5f9; font-size: 1rem; }
  .vsku  { font-size: .75rem; color: #64748b; margin-left: 8px; }
  .broken-count { float: right; background: #ef4444; color: #fff; border-radius: 99px; padding: 2px 10px; font-size: .75rem; font-weight: 700; }
  .scroll { overflow-x: auto; }
</style>
</head>
<body>
<div class="shell">

<div class="card">
  <h1>🔗 Stock Chain Diagnostic & Repair</h1>
  <h2>Rebuilds previous_stock / new_stock values and corrects inventory totals</h2>

  <?php if (isset($_GET['fixed'])): ?>
  <div class="alert alert-ok">✅ Fix applied successfully! Movement chain and inventory table have been corrected.</div>
  <?php endif; ?>

  <?php if (isset($fixError)): ?>
  <div class="alert alert-err">❌ Error during fix: <?= e($fixError) ?></div>
  <?php endif; ?>

  <div class="stat-grid">
    <div class="stat"><div class="num <?= $totalVariationsBroken > 0 ? 'red' : 'grn' ?>"><?= number_format($totalVariationsBroken) ?></div><div class="lbl">Variations with chain errors</div></div>
    <div class="stat"><div class="num <?= $totalBroken > 0 ? 'red' : 'grn' ?>"><?= number_format($totalBroken) ?></div><div class="lbl">Movement rows to fix</div></div>
    <div class="stat"><div class="num"><?= number_format(count($byVar)) ?></div><div class="lbl">Total variations in history</div></div>
    <div class="stat"><div class="num"><?= number_format(count($rows)) ?></div><div class="lbl">Total movements scanned</div></div>
    <?php
      $invDrift = 0;
      foreach ($expectedFinalBalance as $vid => $exp) {
          $cur = $currentInventory[$vid] ?? 0;
          if (abs($cur - $exp) > 0.001) $invDrift++;
      }
    ?>
    <div class="stat"><div class="num <?= $invDrift > 0 ? 'red' : 'grn' ?>"><?= number_format($invDrift) ?></div><div class="lbl">Inventory rows drifted from ledger</div></div>
  </div>

  <?php if ($totalBroken === 0 && $invDrift === 0): ?>
    <div class="alert alert-ok">✅ All stock chains are consistent and inventory matches the ledger. No fixes needed.</div>
  <?php else: ?>
    <div class="alert alert-warn">
      ⚠️ Found <?= number_format($totalBroken) ?> movement row(s) with incorrect previous_stock/new_stock values across
      <?= number_format($totalVariationsBroken) ?> variation(s), plus <?= number_format($invDrift) ?> inventory rows drifted from their ledger total.
      Click <strong>Apply Fix</strong> below to repair.
    </div>
    <form method="POST" action="fix_stock_chain.php?action=fix<?= $limitTo ? '&variation_id='.$limitTo : '' ?>"
          onsubmit="return confirm('Apply chain repair now? This will rewrite previous_stock/new_stock in stock_movements and correct inventory quantities. Make sure you have a DB backup!');">
      <button type="submit" class="btn btn-red">🔧 Apply Fix (Repair Chain + Inventory)</button>
    </form>
  <?php endif; ?>

  <div style="margin-top:14px;">
    <a href="fix_stock_chain.php?action=report" class="btn btn-gray">🔄 Refresh Report</a>
    <a href="views/inventory/index.php" class="btn btn-gray" style="margin-left:8px;">← Back to Inventory</a>
  </div>
</div>

<?php if (!empty($brokenVariations)): ?>
<div class="card">
  <h2>Broken Variations (showing first 200)</h2>
  <?php $shown = 0; foreach ($brokenVariations as $vid => $info):
    if ($shown++ >= 200) break; ?>
  <div class="variation-block">
    <div style="margin-bottom:8px;">
      <span class="vname"><?= e($info['label']) ?></span>
      <span class="vsku"><?= e($info['sku'] ?: 'No SKU') ?></span>
      <span class="broken-count"><?= count($info['broken']) ?> broken</span>
    </div>
    <div class="scroll">
    <table>
      <thead>
        <tr>
          <th>#ID</th><th>Type</th><th>Qty</th>
          <th>Stored Prev</th><th>Stored New</th>
          <th>Expected Prev</th><th>Expected New</th>
          <th>Status</th><th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $brokenIds = array_column($info['broken'], 'movement_id');
        foreach ($info['movements'] as $m):
          $isBroken = in_array($m['movement_id'], $brokenIds);
          $typeTag  = match($m['type']) {
            'sales','online_sale' => 'tag-sales',
            'stock_in'            => 'tag-in',
            'adjustment','online_adjustment' => 'tag-adj',
            'return'              => 'tag-ret',
            'stock_out'           => 'tag-out',
            default               => 'tag-void',
          };
        ?>
        <tr>
          <td class="muted"><?= (int)$m['movement_id'] ?></td>
          <td><span class="tag <?= $typeTag ?>"><?= e($m['type']) ?></span></td>
          <td><?= number_format((float)$m['quantity'], 2) ?></td>
          <td class="<?= $isBroken ? 'bad' : 'ok' ?>"><?= number_format((float)$m['previous_stock'], 2) ?></td>
          <td class="<?= $isBroken ? 'bad' : 'ok' ?>"><?= number_format((float)$m['new_stock'], 2) ?></td>
          <?php if ($isBroken):
            $fix = $allFixes[$m['movement_id']] ?? null; ?>
          <td class="ok"><?= $fix ? number_format($fix[0], 2) : '—' ?></td>
          <td class="ok"><?= $fix ? number_format($fix[1], 2) : '—' ?></td>
          <?php else: ?>
          <td class="muted">—</td>
          <td class="muted">—</td>
          <?php endif; ?>
          <td class="muted"><?= e($m['status'] ?: 'active') ?></td>
          <td class="muted"><?= e($m['created_at']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php
// Show inventory drift table
$driftRows = [];
foreach ($expectedFinalBalance as $vid => $exp) {
    $cur = $currentInventory[$vid] ?? 0;
    if (abs($cur - $exp) > 0.001) {
        $info = $byVar[$vid][0] ?? [];
        $label = trim(($info['product_name'] ?? '') . ' ' . ($info['variation_name'] ?? ''));
        $driftRows[] = [
            'vid'     => $vid,
            'label'   => $label ?: "Variation #$vid",
            'sku'     => $info['sku'] ?? '',
            'current' => $cur,
            'expected'=> $exp,
            'diff'    => $exp - $cur,
        ];
    }
}
if (!empty($driftRows)): ?>
<div class="card">
  <h2>Inventory Table vs Ledger — Drift (<?= count($driftRows) ?> row<?= count($driftRows) !== 1 ? 's' : '' ?>)</h2>
  <p style="color:#94a3b8;font-size:.85rem;margin-bottom:12px;">These items have a different quantity in the <code>inventory</code> table compared to what the movement ledger computes. The fix above will correct these.</p>
  <div class="scroll">
  <table>
    <thead>
      <tr><th>Var ID</th><th>Product</th><th>SKU</th><th>Current Inventory</th><th>Ledger Total</th><th>Difference</th></tr>
    </thead>
    <tbody>
    <?php foreach ($driftRows as $d): ?>
    <tr>
      <td class="muted"><?= (int)$d['vid'] ?></td>
      <td><?= e($d['label']) ?></td>
      <td class="muted"><?= e($d['sku'] ?: '-') ?></td>
      <td class="bad"><?= number_format($d['current'], 2) ?></td>
      <td class="ok"><?= number_format($d['expected'], 2) ?></td>
      <td class="<?= $d['diff'] > 0 ? 'ok' : 'bad' ?>"><?= ($d['diff'] > 0 ? '+' : '') . number_format($d['diff'], 2) ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>
<?php endif; ?>

</div>
</body>
</html>
