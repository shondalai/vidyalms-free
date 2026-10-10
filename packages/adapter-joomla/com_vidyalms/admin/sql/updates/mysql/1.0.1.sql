-- Security and privacy upgrade from the published 1.0.0 schema.
-- Additions may already exist on an unreleased development installation.
-- The installer verifies the completed schema separately.
CREATE TABLE IF NOT EXISTS `#__vidyalms_privacy_file_deletions` ( 
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `path` VARCHAR(1024) NOT NULL,
  `path_hash` VARCHAR(64) NOT NULL,
  `user_id` INT UNSIGNED NOT NULL,
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `privacy_file_deletions_path_hash_uq` (`path_hash`),
  KEY `privacy_file_deletions_user_id_ix` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Joomla's database checker requires ADD COLUMN to detect and repair missing columns.
ALTER TABLE `#__vidyalms_notification_queue` ADD COLUMN `recipient_address_digest` VARCHAR(64) NULL /** CAN FAIL **/;
ALTER TABLE `#__vidyalms_import_jobs` ADD COLUMN `organisation_unit_id` INT UNSIGNED NULL /** CAN FAIL **/;
ALTER TABLE `#__vidyalms_import_jobs` ADD KEY `import_jobs_organisation_id_organisation_unit_id_id_ix` (`organisation_id`, `organisation_unit_id`, `id`) /** CAN FAIL **/;
