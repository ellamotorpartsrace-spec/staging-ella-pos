-- migration: create_product_unit_set_items.sql
-- purpose: store bundle/set definitions and their component products

CREATE TABLE IF NOT EXISTS `product_unit_sets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `set_name` VARCHAR(255) NOT NULL COMMENT 'Sellable bundle/set name',
  `set_sku` VARCHAR(100) NULL COMMENT 'Optional SKU/barcode used for POS or online mapping',
  `description` TEXT NULL,
  `price_retail` DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `price_wholesale` DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `price_dealer` DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_product_unit_sets_sku` (`set_sku`),
  KEY `idx_product_unit_sets_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS `product_unit_set_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_set_id` INT NOT NULL COMMENT 'Parent bundle/set record',
  `component_variation_id` INT NOT NULL COMMENT 'Product variation included in the set',
  `component_unit_id` INT NULL COMMENT 'Optional custom unit for the component product',
  `component_qty` DECIMAL(12,4) UNSIGNED NOT NULL DEFAULT 1.0000 COMMENT 'How many component units are included in one set',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_set_component` (`product_set_id`, `component_variation_id`, `component_unit_id`),
  KEY `idx_pusi_product_set` (`product_set_id`),
  KEY `idx_pusi_component_variation` (`component_variation_id`),
  KEY `idx_pusi_component_unit` (`component_unit_id`),
  CONSTRAINT `fk_pusi_product_set`
    FOREIGN KEY (`product_set_id`) REFERENCES `product_unit_sets` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pusi_component_variation`
    FOREIGN KEY (`component_variation_id`) REFERENCES `product_variations` (`variation_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pusi_component_unit`
    FOREIGN KEY (`component_unit_id`) REFERENCES `product_units` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
