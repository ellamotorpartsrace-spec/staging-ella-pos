CREATE TABLE IF NOT EXISTS `shopee_sync_queue` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `mapping_id` BIGINT(20) UNSIGNED NOT NULL,
  `pos_product_id` BIGINT(20) UNSIGNED NOT NULL,
  `shopee_item_id` BIGINT(20) UNSIGNED NOT NULL,
  `shopee_model_id` BIGINT(20) UNSIGNED DEFAULT NULL,
  `target_stock` INT(11) NOT NULL,
  `status` ENUM('pending', 'success', 'failed') NOT NULL DEFAULT 'pending',
  `attempts` INT(11) NOT NULL DEFAULT 0,
  `error_message` TEXT DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `processed_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`),
  KEY `idx_mapping_id` (`mapping_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
