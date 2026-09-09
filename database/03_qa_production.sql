-- =====================================================
-- QA & PRODUCTION MODULE
-- Quality Assurance, RIC, Batching, and Production Tables
-- =====================================================

-- QA thresholds table
CREATE TABLE `qa_thresholds` (
  `id` char(36) NOT NULL,
  `parameter` enum('ph','tds','chlorine') NOT NULL,
  `min_value` decimal(10,3) NOT NULL,
  `max_value` decimal(10,3) NOT NULL,
  `unit` varchar(10) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_qa_thresholds_parameter` (`parameter`),
  KEY `fk_qa_thresholds_created_by` (`created_by`),
  KEY `fk_qa_thresholds_updated_by` (`updated_by`),
  CONSTRAINT `fk_qa_thresholds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_qa_thresholds_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Water tests table
CREATE TABLE `water_tests` (
  `id` char(36) NOT NULL,
  `test_type` enum('baseline','random','retest') NOT NULL,
  `recorded_at` timestamp NOT NULL,
  `ph` decimal(5,2) DEFAULT NULL,
  `tds` decimal(10,2) DEFAULT NULL,
  `chlorine` decimal(10,3) DEFAULT NULL,
  `unit_notes` text,
  `location_text` varchar(200) DEFAULT NULL,
  `warehouse_id` char(36) DEFAULT NULL,
  `photo_id` char(36) DEFAULT NULL,
  `recorded_by` char(36) NOT NULL,
  `ric_verified_by` char(36) DEFAULT NULL,
  `status` enum('pending','pass','fail') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_water_tests_warehouse` (`warehouse_id`),
  KEY `fk_water_tests_recorded_by` (`recorded_by`),
  KEY `fk_water_tests_ric_verified_by` (`ric_verified_by`),
  KEY `fk_water_tests_created_by` (`created_by`),
  KEY `fk_water_tests_updated_by` (`updated_by`),
  CONSTRAINT `fk_water_tests_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_water_tests_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_water_tests_ric_verified_by` FOREIGN KEY (`ric_verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_water_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_water_tests_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Instrument calibrations table
CREATE TABLE `instrument_calibrations` (
  `id` char(36) NOT NULL,
  `instrument` enum('ph_meter','tds_meter','chlorine_meter') NOT NULL,
  `calibrated_at` timestamp NOT NULL,
  `next_due` date NOT NULL,
  `doc_photo_id` char(36) DEFAULT NULL,
  `by_user_id` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_instrument_calibrations_by_user` (`by_user_id`),
  KEY `fk_instrument_calibrations_created_by` (`created_by`),
  KEY `fk_instrument_calibrations_updated_by` (`updated_by`),
  CONSTRAINT `fk_instrument_calibrations_by_user` FOREIGN KEY (`by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_instrument_calibrations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_instrument_calibrations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Batches table
CREATE TABLE `batches` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `manufacture_date` date NOT NULL,
  `expiry_date` date NOT NULL,
  `planned_qty` int NOT NULL,
  `status` enum('open','in_progress','closed') NOT NULL DEFAULT 'open',
  `opened_by` char(36) NOT NULL,
  `closed_by` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_batches_code` (`code`),
  KEY `fk_batches_sku` (`sku_id`),
  KEY `fk_batches_opened_by` (`opened_by`),
  KEY `fk_batches_closed_by` (`closed_by`),
  KEY `fk_batches_created_by` (`created_by`),
  KEY `fk_batches_updated_by` (`updated_by`),
  CONSTRAINT `fk_batches_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_batches_opened_by` FOREIGN KEY (`opened_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_batches_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_batches_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_batches_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packaging checks table
CREATE TABLE `packaging_checks` (
  `id` char(36) NOT NULL,
  `batch_id` char(36) NOT NULL,
  `check_time` timestamp NOT NULL,
  `ph` decimal(5,2) DEFAULT NULL,
  `tds` decimal(10,2) DEFAULT NULL,
  `chlorine` decimal(10,3) DEFAULT NULL,
  `result` enum('pass','fail') NOT NULL,
  `photo_id` char(36) DEFAULT NULL,
  `checked_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_packaging_checks_batch` (`batch_id`),
  KEY `fk_packaging_checks_checked_by` (`checked_by`),
  KEY `fk_packaging_checks_created_by` (`created_by`),
  KEY `fk_packaging_checks_updated_by` (`updated_by`),
  CONSTRAINT `fk_packaging_checks_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  CONSTRAINT `fk_packaging_checks_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_packaging_checks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_packaging_checks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Non-conformances table
CREATE TABLE `non_conformances` (
  `id` char(36) NOT NULL,
  `source` enum('qa','production','delivery') NOT NULL,
  `description` text NOT NULL,
  `severity` enum('low','med','high') NOT NULL DEFAULT 'med',
  `status` enum('open','investigating','corrected','closed') NOT NULL DEFAULT 'open',
  `raised_by` char(36) NOT NULL,
  `related_entity` varchar(50) DEFAULT NULL,
  `related_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_non_conformances_raised_by` (`raised_by`),
  KEY `idx_non_conformances_related` (`related_entity`, `related_id`),
  KEY `fk_non_conformances_created_by` (`created_by`),
  KEY `fk_non_conformances_updated_by` (`updated_by`),
  CONSTRAINT `fk_non_conformances_raised_by` FOREIGN KEY (`raised_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_non_conformances_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_non_conformances_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Corrective actions table
CREATE TABLE `corrective_actions` (
  `id` char(36) NOT NULL,
  `nc_id` char(36) NOT NULL,
  `action_text` text NOT NULL,
  `owner_id` char(36) NOT NULL,
  `due_date` date NOT NULL,
  `status` enum('todo','doing','done') NOT NULL DEFAULT 'todo',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_corrective_actions_nc` (`nc_id`),
  KEY `fk_corrective_actions_owner` (`owner_id`),
  KEY `fk_corrective_actions_created_by` (`created_by`),
  KEY `fk_corrective_actions_updated_by` (`updated_by`),
  CONSTRAINT `fk_corrective_actions_nc` FOREIGN KEY (`nc_id`) REFERENCES `non_conformances` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_corrective_actions_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_corrective_actions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_corrective_actions_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Production plans table
CREATE TABLE `production_plans` (
  `id` char(36) NOT NULL,
  `plan_date` date NOT NULL,
  `warehouse_id` char(36) NOT NULL,
  `created_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_production_plans_date_warehouse` (`plan_date`, `warehouse_id`),
  KEY `fk_production_plans_warehouse` (`warehouse_id`),
  KEY `fk_production_plans_created_by` (`created_by`),
  KEY `fk_production_plans_updated_by` (`updated_by`),
  CONSTRAINT `fk_production_plans_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_production_plans_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_production_plans_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Production plan items table
CREATE TABLE `production_plan_items` (
  `id` char(36) NOT NULL,
  `plan_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `planned_qty` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_production_plan_items_plan_sku` (`plan_id`, `sku_id`),
  KEY `fk_production_plan_items_plan` (`plan_id`),
  KEY `fk_production_plan_items_sku` (`sku_id`),
  KEY `fk_production_plan_items_created_by` (`created_by`),
  KEY `fk_production_plan_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_production_plan_items_plan` FOREIGN KEY (`plan_id`) REFERENCES `production_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_production_plan_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_production_plan_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_production_plan_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Packaging runs table
CREATE TABLE `packaging_runs` (
  `id` char(36) NOT NULL,
  `batch_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `run_start` timestamp NOT NULL,
  `run_end` timestamp NULL DEFAULT NULL,
  `good_qty` int NOT NULL DEFAULT '0',
  `scrap_qty` int NOT NULL DEFAULT '0',
  `downtime_minutes` int DEFAULT '0',
  `notes` text,
  `run_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_packaging_runs_batch` (`batch_id`),
  KEY `fk_packaging_runs_sku` (`sku_id`),
  KEY `fk_packaging_runs_run_by` (`run_by`),
  KEY `fk_packaging_runs_created_by` (`created_by`),
  KEY `fk_packaging_runs_updated_by` (`updated_by`),
  CONSTRAINT `fk_packaging_runs_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`),
  CONSTRAINT `fk_packaging_runs_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_packaging_runs_run_by` FOREIGN KEY (`run_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_packaging_runs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_packaging_runs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleaning tasks table
CREATE TABLE `cleaning_tasks` (
  `id` char(36) NOT NULL,
  `title` varchar(200) NOT NULL,
  `frequency` enum('daily','weekly','monthly') NOT NULL,
  `checklist_json` json DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_cleaning_tasks_created_by` (`created_by`),
  KEY `fk_cleaning_tasks_updated_by` (`updated_by`),
  CONSTRAINT `fk_cleaning_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cleaning_tasks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cleaning logs table
CREATE TABLE `cleaning_logs` (
  `id` char(36) NOT NULL,
  `task_id` char(36) NOT NULL,
  `performed_at` timestamp NOT NULL,
  `by_user_id` char(36) NOT NULL,
  `result` enum('pass','fail') NOT NULL,
  `notes` text,
  `photo_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_cleaning_logs_task` (`task_id`),
  KEY `fk_cleaning_logs_by_user` (`by_user_id`),
  KEY `fk_cleaning_logs_created_by` (`created_by`),
  KEY `fk_cleaning_logs_updated_by` (`updated_by`),
  CONSTRAINT `fk_cleaning_logs_task` FOREIGN KEY (`task_id`) REFERENCES `cleaning_tasks` (`id`),
  CONSTRAINT `fk_cleaning_logs_by_user` FOREIGN KEY (`by_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_cleaning_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_cleaning_logs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA FOR QA & PRODUCTION
-- =====================================================

-- Insert QA thresholds
INSERT INTO `qa_thresholds` (`id`, `parameter`, `min_value`, `max_value`, `unit`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440701', 'ph', 6.500, 8.500, 'pH', NOW()),
('550e8400-e29b-41d4-a716-446655440702', 'tds', 0.000, 500.000, 'ppm', NOW()),
('550e8400-e29b-41d4-a716-446655440703', 'chlorine', 0.200, 2.000, 'ppm', NOW());

-- Insert cleaning tasks
INSERT INTO `cleaning_tasks` (`id`, `title`, `frequency`, `checklist_json`, `active`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440801', 'Daily Production Line Cleaning', 'daily', '["Clean bottling line", "Sanitize equipment", "Check foot bath chlorine", "Clean floors"]', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440802', 'Weekly Deep Cleaning', 'weekly', '["Deep clean all equipment", "Sanitize entire facility", "Check and clean filters", "Inspect and clean storage tanks"]', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440803', 'Monthly Facility Maintenance', 'monthly', '["Inspect all equipment", "Clean ventilation systems", "Check water treatment systems", "Review safety equipment"]', 1, NOW());
