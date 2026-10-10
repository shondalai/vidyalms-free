-- SECTION 2: adapter-owned tables.
--
-- The job queue is deliberately outside the neutral core schema: the same
-- SchedulerPort is served on WordPress by Action Scheduler, which brings its
-- own storage. JoomlaScheduler writes pending rows here and plg_task_vidyalms
-- drains them.

CREATE TABLE IF NOT EXISTS `#__vidyalms_joomla_jobs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_type` VARCHAR(100) NOT NULL,
  `payload` TEXT NULL,
  `dedupe_key` VARCHAR(190) NULL,
  `group_key` VARCHAR(190) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'pending',
  `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
  `last_error` TEXT NULL,
  `run_at` DATETIME NOT NULL,
  `reserved_at` DATETIME NULL,
  `finished_at` DATETIME NULL,
  `created_at` DATETIME NOT NULL,
  `updated_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `joomla_jobs_dedupe_key_uq` (`dedupe_key`),
  KEY `joomla_jobs_group_status_ix` (`group_key`, `status`),
  KEY `joomla_jobs_status_run_at_ix` (`status`, `run_at`),
  KEY `joomla_jobs_status_finished_at_ix` (`status`, `finished_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
