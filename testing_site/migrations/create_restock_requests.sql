CREATE TABLE IF NOT EXISTS `restock_requests` (
    `request_id` int(11) NOT NULL AUTO_INCREMENT,
    `batch_id` varchar(100) DEFAULT NULL,
    `variation_id` int(11) NOT NULL,
    `quantity` int(11) NOT NULL,
    `cost` decimal(10,2) NOT NULL DEFAULT 0.00,
    `supplier_id` int(11) DEFAULT NULL,
    `supplier_name` varchar(255) DEFAULT NULL,
    `reference` varchar(255) DEFAULT NULL,
    `payment_status` varchar(50) DEFAULT 'paid',
    `due_date` date DEFAULT NULL,
    `credit_terms` text DEFAULT NULL,
    `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    `requested_by` int(11) NOT NULL,
    `approved_by` int(11) DEFAULT NULL,
    `created_at` datetime NOT NULL DEFAULT current_timestamp(),
    `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
    PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `users` ADD COLUMN `can_approve_restocks` TINYINT(1) NOT NULL DEFAULT 0;
