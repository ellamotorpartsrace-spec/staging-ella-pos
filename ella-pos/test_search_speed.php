<?php
require_once 'config/config.php';
require_once 'config/database.php';

$db = new Database();
$conn = $db->getConnection();

$q = "test"; // search string
$words = preg_split('/\s+/', $q);
$validWords = [];
foreach ($words as $word) {
    $word = trim($word);
    if (strlen($word) >= 1) $validWords[] = $word;
}

$wordConditions = [];
$params = [':barcode' => $q];

foreach ($validWords as $idx => $word) {
    $paramName = ":word{$idx}";
    $params[$paramName] = "%{$word}%";
    $wordConditions[] = "(
        p.product_name LIKE {$paramName}
        OR p.brand_name LIKE {$paramName}
        OR v.variation_name LIKE {$paramName}
        OR v.sku LIKE {$paramName}
    )";
}
if (empty($wordConditions)) {
    $params[':like'] = "%{$q}%";
    $wordConditions[] = "(
        p.product_name LIKE :like
        OR p.brand_name LIKE :like
        OR v.variation_name LIKE :like
        OR v.sku LIKE :like
    )";
}
$searchCondition = !empty($wordConditions) ? implode(' AND ', $wordConditions) : "1=1";

$limit = 50;
$offset = 0;

$sql = "
WITH MatchedProducts AS (
    SELECT 
        v.variation_id, p.product_name, p.brand_name, p.image_path, p.description,
        v.variation_name, v.sku, v.price_capital, v.price_retail, v.price_wholesale,
        v.price_dealer, v.unit_type, v.barcode,
        CAST(1 AS UNSIGNED) AS multiplier, NULL AS unit_id,
        (COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0)) AS physical_stock,
        (
            CASE 
                WHEN v.barcode = :barcode THEN 100
                WHEN v.sku = :barcode THEN 90
                WHEN p.product_name LIKE CONCAT(:barcode, '%') THEN 80
                WHEN v.variation_name LIKE CONCAT(:barcode, '%') THEN 80
                WHEN p.brand_name LIKE CONCAT(:barcode, '%') THEN 80
                ELSE 10
            END
        ) AS relevance_score
    FROM product_variations v
    INNER JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.status = 'active'
    AND (
        v.barcode = :barcode
        OR ({$searchCondition})
    )
    
    UNION ALL
    
    SELECT 
        v.variation_id, p.product_name, p.brand_name, p.image_path,
        COALESCE(u.description, p.description) AS description,
        v.variation_name, v.sku,
        COALESCE(NULLIF(u.price_capital, 0), v.price_capital) AS price_capital,
        u.price_retail, u.price_wholesale, u.price_dealer, u.unit_name AS unit_type,
        u.barcode, u.multiplier, u.id AS unit_id,
        (COALESCE(i_phys.quantity, 0) + COALESCE(i_online.quantity, 0)) AS physical_stock,
        (
            CASE 
                WHEN u.barcode = :barcode THEN 100
                WHEN v.sku = :barcode THEN 90
                WHEN p.product_name LIKE CONCAT(:barcode, '%') THEN 80
                WHEN v.variation_name LIKE CONCAT(:barcode, '%') THEN 80
                WHEN p.brand_name LIKE CONCAT(:barcode, '%') THEN 80
                ELSE 10
            END
        ) AS relevance_score
    FROM product_units u
    INNER JOIN product_variations v ON u.variation_id = v.variation_id
    INNER JOIN products p ON v.product_id = p.product_id
    LEFT JOIN inventory i_phys ON v.variation_id = i_phys.variation_id AND i_phys.store_id = 1
    LEFT JOIN inventory i_online ON v.variation_id = i_online.variation_id AND i_online.store_id = 2
    WHERE v.status = 'active'
    AND (
        u.barcode = :barcode
        OR ({$searchCondition})
    )
)
SELECT * FROM MatchedProducts
ORDER BY relevance_score DESC, physical_stock > 0 DESC, product_name ASC, variation_name ASC, multiplier ASC
LIMIT {$limit} OFFSET {$offset}
";

$start = microtime(true);
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$time = microtime(true) - $start;

echo "Found " . count($rows) . " rows in $time seconds.\n";
