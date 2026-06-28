-- ============================================================
-- Migration: Inventory Snapshot, Backup & Recovery System
-- Created: 2026-06-05
-- Run this once on your database (local XAMPP + Hostinger).
-- ============================================================

-- 1. Main snapshot registry
CREATE TABLE IF NOT EXISTS `inventory_snapshots` (
    `id`               INT          NOT NULL AUTO_INCREMENT,
    `snapshot_name`    VARCHAR(255) NOT NULL,
    `notes`            TEXT         NULL,
    `total_products`   INT          NOT NULL DEFAULT 0,
    `trigger_type`     ENUM('manual','auto','pre_restore') NOT NULL DEFAULT 'manual',
    `created_by`       INT          NULL     COMMENT 'User ID (FK to users)',
    `created_by_name`  VARCHAR(255) NULL     COMMENT 'Denormalized for audit permanence',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_trigger_type` (`trigger_type`),
    INDEX `idx_created_at`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Per-product snapshot data
--    total_stock      = store_id=1 qty + store_id=2 qty
--    shopee_allocated = store_id=2 qty
--    current_pos_stock= store_id=1 qty  (display only — always recalculated on restore)
CREATE TABLE IF NOT EXISTS `inventory_snapshot_items` (
    `id`                INT          NOT NULL AUTO_INCREMENT,
    `snapshot_id`       INT          NOT NULL,
    `variation_id`      INT          NOT NULL,
    `sku`               VARCHAR(100) NULL,
    `product_name`      VARCHAR(500) NULL,
    `total_stock`       INT          NOT NULL DEFAULT 0,
    `shopee_allocated`  INT          NOT NULL DEFAULT 0,
    `current_pos_stock` INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    INDEX `idx_snapshot_id`  (`snapshot_id`),
    INDEX `idx_variation_id` (`variation_id`),
    CONSTRAINT `fk_snap_items_snapshot`
        FOREIGN KEY (`snapshot_id`)
        REFERENCES `inventory_snapshots` (`id`)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Auto-snapshot configuration
CREATE TABLE IF NOT EXISTS `inventory_snapshot_settings` (
    `id`            INT          NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default settings (safe to re-run)
INSERT IGNORE INTO `inventory_snapshot_settings` (`setting_key`, `setting_value`) VALUES
    ('auto_snapshot_enabled',   '0'),
    ('auto_snapshot_frequency', 'daily'),
    ('auto_snapshot_retention', '30'),
    ('last_auto_snapshot_at',   NULL);

-- 4. Audit log — every critical action recorded here
CREATE TABLE IF NOT EXISTS `inventory_snapshot_audit` (
    `id`                       INT          NOT NULL AUTO_INCREMENT,
    `action_type`              VARCHAR(50)  NOT NULL
                                   COMMENT 'RESTORE | EMERGENCY_RESTORE | DELETE_SNAPSHOT | CREATE_SNAPSHOT | SHOPEE_RESYNC | AUTO_SNAPSHOT',
    `snapshot_id`              INT          NULL,
    `snapshot_name`            VARCHAR(255) NULL,
    `pre_restore_snapshot_id`  INT          NULL     COMMENT 'Auto-created pre-restore backup ID',
    `user_id`                  INT          NULL,
    `user_name`                VARCHAR(255) NULL,
    `ip_address`               VARCHAR(45)  NULL,
    `products_affected`        INT          NULL DEFAULT 0,
    `notes`                    TEXT         NULL,
    `created_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_action`   (`action_type`),
    INDEX `idx_audit_date`     (`created_at`),
    INDEX `idx_audit_user`     (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
