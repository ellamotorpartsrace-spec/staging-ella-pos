<?php
if (!function_exists('propagateStockToPos')) {
    /**
     * Propagate stock updates from Lazada to POS online inventory (store_id = 3)
     */
    function propagateStockToPos($conn, $posProductId, $lazadaStock, $mapId = null) {
        if (empty($posProductId)) return;

        try {
            $lazadaStock = (int)$lazadaStock;

            // Update the mapping's lazada_stock FIRST so the SUM below is accurate.
            if (!empty($mapId)) {
                $conn->prepare("UPDATE lazada_product_mappings SET lazada_stock = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$lazadaStock, $mapId]);
            }

            // Resolve the POS product's SKU to use for SKU-based stock aggregation
            $skuStmt = $conn->prepare("SELECT sku FROM product_variations WHERE variation_id = ?");
            $skuStmt->execute([$posProductId]);
            $posSku = $skuStmt->fetchColumn();
            
            if (empty($posSku)) {
                $posSku = '';
            }
            
            $stmtSum = $conn->prepare("
                SELECT COALESCE(SUM(m.lazada_stock * COALESCE(u.multiplier, 1)), 0) 
                FROM lazada_product_mappings m
                LEFT JOIN product_units u ON m.pos_unit_id = u.id
                WHERE (m.pos_product_id = ? OR (m.matched_pos_sku = ? AND m.matched_pos_sku NOT IN ('', '-', 'N/A', 'NA', 'none', 'null')))
                  AND m.mapping_status IN ('auto','manual')
            ");
            $stmtSum->execute([$posProductId, $posSku]);
            $totalLazadaStock = (int)$stmtSum->fetchColumn();

            // Get previous stock in POS online store (store_id = 3)
            $stmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 3");
            $stmt->execute([$posProductId]);
            $prevStockRow = $stmt->fetch();
            $prevStock = $prevStockRow !== false ? (float) $prevStockRow['quantity'] : 0;

            if ($prevStockRow === false || $prevStock !== (float)$totalLazadaStock) {
                // Update POS Online Shop (store_id = 3) to reflect Lazada stock
                $updStore = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 3, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updStore->execute([$posProductId, $totalLazadaStock]);

                // Fetch physical store stock (store_id = 1) to calculate total and deduct
                $physStmt = $conn->prepare("SELECT quantity FROM inventory WHERE variation_id = ? AND store_id = 1");
                $physStmt->execute([$posProductId]);
                $physQty = (int)($physStmt->fetchColumn() ?? 0);
                
                $totalQty = $physQty + $prevStock;
                
                // Deduct from physical store to maintain total POS stock
                $newPhysQty = $totalQty - $totalLazadaStock;
                $updPhys = $conn->prepare("
                    INSERT INTO inventory (variation_id, store_id, quantity) 
                    VALUES (?, 1, ?)
                    ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)
                ");
                $updPhys->execute([$posProductId, $newPhysQty]);

                $newRatio = $totalQty > 0 ? (int)round(($totalLazadaStock / $totalQty) * 100) : 100;

                // Update the stock allocation ratio in mappings
                if (!empty($mapId)) {
                    $conn->prepare("UPDATE lazada_product_mappings SET stock_allocation_ratio = ? WHERE id = ?")
                        ->execute([$newRatio, $mapId]);
                } else {
                    $conn->prepare("UPDATE lazada_product_mappings SET stock_allocation_ratio = ? WHERE pos_product_id = ?")
                        ->execute([$newRatio, $posProductId]);
                }
            }
        } catch (Exception $e) {
            // Suppress error to avoid interrupting batch sync
        }
    }
}
