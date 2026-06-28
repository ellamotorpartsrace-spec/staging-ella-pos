-- 1. Shopee Orders
CREATE TABLE IF NOT EXISTS `shopee_orders` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_sn` VARCHAR(50) NOT NULL UNIQUE,
    `order_status` VARCHAR(50) NOT NULL,
    `create_time` DATETIME NOT NULL,
    `update_time` DATETIME DEFAULT NULL,
    `buyer_username` VARCHAR(100) DEFAULT NULL,
    `total_amount` DECIMAL(10,2) DEFAULT 0.00,
    `estimated_shipping_fee` DECIMAL(10,2) DEFAULT 0.00,
    `payment_method` VARCHAR(100) DEFAULT NULL,
    `shipping_carrier` VARCHAR(100) DEFAULT NULL,
    `tracking_number` VARCHAR(100) DEFAULT NULL,
    `cancel_reason` VARCHAR(255) DEFAULT NULL,
    `financial_status` ENUM('PENDING', 'RELEASED', 'REFUNDED') DEFAULT 'PENDING',
    `escrow_amount` DECIMAL(10,2) DEFAULT NULL, -- Net payout
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_order_sn` (`order_sn`),
    INDEX `idx_order_status` (`order_status`),
    INDEX `idx_create_time` (`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Shopee Order Items
CREATE TABLE IF NOT EXISTS `shopee_order_items` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_sn` VARCHAR(50) NOT NULL,
    `item_id` BIGINT NOT NULL,
    `model_id` BIGINT DEFAULT 0,
    `item_name` VARCHAR(500) NOT NULL,
    `item_sku` VARCHAR(100) DEFAULT NULL,
    `model_name` VARCHAR(255) DEFAULT NULL,
    `model_sku` VARCHAR(100) DEFAULT NULL,
    `original_price` DECIMAL(10,2) DEFAULT 0.00,
    `discounted_price` DECIMAL(10,2) DEFAULT 0.00,
    `quantity_purchased` INT NOT NULL DEFAULT 1,
    `pos_unit_id` INT DEFAULT NULL, -- Linked during sync for capital cost
    `capital_cost` DECIMAL(10,2) DEFAULT 0.00, -- Snapshot of capital cost at order time
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_order_sn_item` (`order_sn`),
    INDEX `idx_item_sku` (`item_sku`),
    CONSTRAINT `fk_shopee_order_items_sn` FOREIGN KEY (`order_sn`) REFERENCES `shopee_orders`(`order_sn`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Shopee Financial Transactions (Escrow Details)
CREATE TABLE IF NOT EXISTS `shopee_financial_transactions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `order_sn` VARCHAR(50) NOT NULL UNIQUE,
    `payout_amount` DECIMAL(10,2) NOT NULL, -- Final net released to seller
    `escrow_tax` DECIMAL(10,2) DEFAULT 0.00,
    `buyer_total_amount` DECIMAL(10,2) DEFAULT 0.00, -- Gross sales
    `shipping_fee_paid_by_buyer` DECIMAL(10,2) DEFAULT 0.00, -- Included in gross
    `shipping_fee_paid_by_seller` DECIMAL(10,2) DEFAULT 0.00, -- Actual shipping cost
    `commission_fee` DECIMAL(10,2) DEFAULT 0.00,
    `transaction_fee` DECIMAL(10,2) DEFAULT 0.00,
    `service_fee` DECIMAL(10,2) DEFAULT 0.00,
    `marketing_fee` DECIMAL(10,2) DEFAULT 0.00,
    `seller_voucher` DECIMAL(10,2) DEFAULT 0.00,
    `shopee_voucher` DECIMAL(10,2) DEFAULT 0.00,
    `escrow_release_time` DATETIME DEFAULT NULL,
    `settlement_id` VARCHAR(50) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_fin_order_sn` (`order_sn`),
    INDEX `idx_escrow_time` (`escrow_release_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Shopee Settlements (Payouts)
CREATE TABLE IF NOT EXISTS `shopee_settlements` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `settlement_id` VARCHAR(50) NOT NULL UNIQUE,
    `payout_amount` DECIMAL(10,2) NOT NULL,
    `payout_time` DATETIME NOT NULL,
    `status` VARCHAR(50) DEFAULT NULL,
    `bank_account` VARCHAR(100) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_settlement_id` (`settlement_id`),
    INDEX `idx_payout_time` (`payout_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
