<?php
/**
 * Shared helpers for Shopee mappings that can point to a custom product unit.
 */

if (!function_exists('ensureShopeeUnitMappingColumn')) {
    function ensureShopeeUnitMappingColumn(PDO $conn): void
    {
        $stmt = $conn->query("SHOW COLUMNS FROM shopee_product_mappings LIKE 'pos_unit_id'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE shopee_product_mappings ADD COLUMN pos_unit_id INT DEFAULT NULL AFTER pos_product_id");
        }
    }
}

if (!function_exists('ensureShopeeBundleMappingColumn')) {
    function ensureShopeeBundleMappingColumn(PDO $conn): void
    {
        $stmt = $conn->query("SHOW COLUMNS FROM shopee_product_mappings LIKE 'pos_bundle_set_id'");
        if (!$stmt || !$stmt->fetch(PDO::FETCH_ASSOC)) {
            $conn->exec("ALTER TABLE shopee_product_mappings ADD COLUMN pos_bundle_set_id INT DEFAULT NULL AFTER pos_unit_id");
        }
    }
}

if (!function_exists('normalizeShopeePosUnitId')) {
    function normalizeShopeePosUnitId(PDO $conn, $posProductId, $posUnitId): ?int
    {
        $productId = (int)($posProductId ?? 0);
        $unitId = (int)($posUnitId ?? 0);

        if ($productId <= 0 || $unitId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id FROM product_units WHERE id = ? AND variation_id = ? LIMIT 1");
        $stmt->execute([$unitId, $productId]);

        return $stmt->fetchColumn() ? $unitId : null;
    }
}

if (!function_exists('normalizeShopeeBundleSetId')) {
    function normalizeShopeeBundleSetId(PDO $conn, $bundleSetId): ?int
    {
        $setId = (int)($bundleSetId ?? 0);
        if ($setId <= 0) {
            return null;
        }

        $stmt = $conn->prepare("SELECT id FROM product_unit_sets WHERE id = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$setId]);

        return $stmt->fetchColumn() ? $setId : null;
    }
}
