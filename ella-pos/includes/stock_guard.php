<?php
declare(strict_types=1);

if (!class_exists('InsufficientPhysicalStockException')) {
    class InsufficientPhysicalStockException extends RuntimeException
    {
        private array $items;

        public function __construct(array $items)
        {
            $this->items = $items;
            $count = count($items);
            parent::__construct(
                'Insufficient total stock for ' . $count . ' item' . ($count === 1 ? '' : 's') . '. Please check available inventory before checkout.'
            );
        }

        public function getItems(): array
        {
            return $this->items;
        }
    }
}

function stockGuardFirstValue(array $row, array $keys, $default = null)
{
    foreach ($keys as $key) {
        if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') {
            return $row[$key];
        }
    }

    return $default;
}

function buildPhysicalStockRequirements(array $items, array $options = []): array
{
    $quantityKeys = $options['quantity_keys'] ?? ['quantity', 'qty'];
    $nameKeys = $options['name_keys'] ?? ['product_name', 'name'];
    $variationNameKeys = $options['variation_name_keys'] ?? ['variation_name', 'variation', 'unit_type'];
    $multiplierKey = $options['multiplier_key'] ?? 'multiplier';

    $requirements = [];
    $labels = [];

    foreach ($items as $index => $item) {
        if (!is_array($item)) {
            continue;
        }

        $variationId = (int)($item['variation_id'] ?? 0);
        $quantity = (int)stockGuardFirstValue($item, $quantityKeys, 0);
        $multiplier = max(1, (int)($item[$multiplierKey] ?? 1));
        $deductQty = $quantity * $multiplier;

        if ($variationId <= 0 || $deductQty <= 0) {
            continue;
        }

        $requirements[$variationId] = ($requirements[$variationId] ?? 0) + $deductQty;

        if (!isset($labels[$variationId])) {
            $name = trim((string)stockGuardFirstValue($item, $nameKeys, 'Item ' . ($index + 1)));
            $variation = trim((string)stockGuardFirstValue($item, $variationNameKeys, ''));
            $labels[$variationId] = trim($name . ($variation !== '' ? ' - ' . $variation : ''));
        }
    }

    return ['requirements' => $requirements, 'labels' => $labels];
}

function assertPhysicalStockAvailable(PDO $conn, array $requirements, array $labels = []): void
{
    if (empty($requirements)) {
        return;
    }

    $stockStmt = $conn->prepare("
        SELECT SUM(quantity)
        FROM inventory
        WHERE variation_id = ?
        FOR UPDATE
    ");

    $labelStmt = $conn->prepare("
        SELECT
            p.product_name,
            pv.variation_name,
            pv.sku,
            pv.barcode
        FROM product_variations pv
        LEFT JOIN products p ON p.product_id = pv.product_id
        WHERE pv.variation_id = ?
        LIMIT 1
    ");

    $shortages = [];

    foreach ($requirements as $variationId => $requiredQty) {
        $variationId = (int)$variationId;
        $requiredQty = (int)$requiredQty;

        $stockStmt->execute([$variationId]);
        $current = $stockStmt->fetchColumn();
        $availableQty = $current === false ? 0 : (int)$current;

        if ($availableQty >= $requiredQty) {
            continue;
        }

        $name = trim((string)($labels[$variationId] ?? ''));
        $sku = '';
        $barcode = '';

        $labelStmt->execute([$variationId]);
        $label = $labelStmt->fetch(PDO::FETCH_ASSOC);
        if ($label) {
            $labelName = trim((string)($label['product_name'] ?? ''));
            $labelVariation = trim((string)($label['variation_name'] ?? ''));
            $name = trim($labelName . ($labelVariation !== '' ? ' - ' . $labelVariation : '')) ?: $name;
            $sku = trim((string)($label['sku'] ?? ''));
            $barcode = trim((string)($label['barcode'] ?? ''));
        }

        $shortages[] = [
            'variation_id' => $variationId,
            'name' => $name !== '' ? $name : 'Variation #' . $variationId,
            'sku' => $sku,
            'barcode' => $barcode,
            'requested' => $requiredQty,
            'available' => $availableQty,
            'shortfall' => $requiredQty - $availableQty,
        ];
    }

    if (!empty($shortages)) {
        throw new InsufficientPhysicalStockException($shortages);
    }
}
