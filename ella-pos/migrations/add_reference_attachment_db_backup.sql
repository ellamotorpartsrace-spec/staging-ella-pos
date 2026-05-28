ALTER TABLE `reference_attachments`
    ADD COLUMN IF NOT EXISTS `image_data` LONGBLOB NULL AFTER `image_path`,
    ADD COLUMN IF NOT EXISTS `mime_type` VARCHAR(100) NULL AFTER `image_data`,
    ADD COLUMN IF NOT EXISTS `original_filename` VARCHAR(255) NULL AFTER `mime_type`,
    ADD COLUMN IF NOT EXISTS `local_saved` TINYINT(1) NOT NULL DEFAULT 1 AFTER `original_filename`;
