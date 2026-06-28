ALTER TABLE `stock_movements` ADD COLUMN IF NOT EXISTS `status` ENUM('active','voided') NOT NULL DEFAULT 'active';
