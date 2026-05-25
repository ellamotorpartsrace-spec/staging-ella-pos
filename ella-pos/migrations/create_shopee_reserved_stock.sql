-- ══════════════════════════════════════════════
-- SHOPEE SYNC MODULE — RESERVED STOCK SYSTEM
-- Run this in phpMyAdmin on ella_parts_db
-- ══════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS `shopee_reserved_stock` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_sn` VARCHAR(100) NOT NULL,
    `shopee_item_id` BIGINT NOT NULL,
    `shopee_model_id` BIGINT DEFAULT NULL,
    `sku` VARCHAR(100) DEFAULT NULL,
    `quantity` INT NOT NULL DEFAULT 0,
    `order_status` VARCHAR(50) NOT NULL,
    `buyer_username` VARCHAR(255) DEFAULT NULL,
    `reserved_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `released_at` DATETIME DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `idx_order_item` (`order_sn`, `shopee_item_id`, `shopee_model_id`),
    INDEX `idx_item_model` (`shopee_item_id`, `shopee_model_id`),
    INDEX `idx_active` (`is_active`),
    INDEX `idx_order_status` (`order_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
