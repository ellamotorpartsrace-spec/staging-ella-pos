-- migration: create_product_unit_set_items.sql
-- purpose: store set/bundle composition for custom unit types

CREATE TABLE IF NOT EXISTS `product_unit_set_items` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `product_unit_id` INT NOT NULL COMMENT 'Parent custom unit that represents the set',
  `component_variation_id` INT NOT NULL COMMENT 'Product variation included in the set',
  `component_unit_id` INT NULL COMMENT 'Optional custom unit for the component product',
  `component_qty` DECIMAL(12,4) UNSIGNED NOT NULL DEFAULT 1.0000 COMMENT 'How many component units are included in one set',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_unit_component` (`product_unit_id`, `component_variation_id`, `component_unit_id`),
  KEY `idx_pusi_product_unit` (`product_unit_id`),
  KEY `idx_pusi_component_variation` (`component_variation_id`),
  KEY `idx_pusi_component_unit` (`component_unit_id`),
  CONSTRAINT `fk_pusi_product_unit`
    FOREIGN KEY (`product_unit_id`) REFERENCES `product_units` (`id`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pusi_component_variation`
    FOREIGN KEY (`component_variation_id`) REFERENCES `product_variations` (`variation_id`)
    ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_pusi_component_unit`
    FOREIGN KEY (`component_unit_id`) REFERENCES `product_units` (`id`)
    ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
