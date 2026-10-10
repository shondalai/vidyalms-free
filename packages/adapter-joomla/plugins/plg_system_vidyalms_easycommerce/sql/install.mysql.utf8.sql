CREATE TABLE IF NOT EXISTS `#__vidyalms_easycommerce_sync` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `object_type` VARCHAR(16) NOT NULL,
    `object_id` BIGINT UNSIGNED NOT NULL,
    `revision` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `user_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `course_ids` TEXT NOT NULL,
    `mapping_snapshot` TEXT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `next_attempt_at` DATETIME NOT NULL,
    `checked_at` DATETIME NULL,
    `last_error` VARCHAR(255) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    UNIQUE KEY `object_key` (`object_type`, `object_id`),
    KEY `due` (`next_attempt_at`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `#__vidyalms_easycommerce_cursors` (
    `object_type` VARCHAR(16) NOT NULL,
    `last_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`object_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `#__vidyalms_easycommerce_cursors` (`object_type`) VALUES ('order'), ('subscription');
