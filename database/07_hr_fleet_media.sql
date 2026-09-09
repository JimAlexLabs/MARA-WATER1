-- =====================================================
-- HR, FLEET & MEDIA MODULE
-- Human Resources, Fleet Management, Media Storage, Voice Notes, Tasks, and Notifications
-- =====================================================

-- Shifts table
CREATE TABLE `shifts` (
  `id` char(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_shifts_created_by` (`created_by`),
  KEY `fk_shifts_updated_by` (`updated_by`),
  CONSTRAINT `fk_shifts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_shifts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Attendances table
CREATE TABLE `attendances` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `check_in_at` timestamp NULL DEFAULT NULL,
  `check_in_latlng` varchar(50) DEFAULT NULL,
  `check_in_photo_id` char(36) DEFAULT NULL,
  `check_out_at` timestamp NULL DEFAULT NULL,
  `check_out_latlng` varchar(50) DEFAULT NULL,
  `check_out_photo_id` char(36) DEFAULT NULL,
  `device_info` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_attendances_user_date` (`user_id`, DATE(`check_in_at`)),
  KEY `fk_attendances_user` (`user_id`),
  KEY `fk_attendances_created_by` (`created_by`),
  KEY `fk_attendances_updated_by` (`updated_by`),
  CONSTRAINT `fk_attendances_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_attendances_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_attendances_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uniform checks table
CREATE TABLE `uniform_checks` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `date` date NOT NULL,
  `dust_coat` tinyint(1) NOT NULL DEFAULT '0',
  `boots` tinyint(1) NOT NULL DEFAULT '0',
  `cap` tinyint(1) NOT NULL DEFAULT '0',
  `result` enum('pass','fail') NOT NULL,
  `photo_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_uniform_checks_user_date` (`user_id`, `date`),
  KEY `fk_uniform_checks_user` (`user_id`),
  KEY `fk_uniform_checks_created_by` (`created_by`),
  KEY `fk_uniform_checks_updated_by` (`updated_by`),
  CONSTRAINT `fk_uniform_checks_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_uniform_checks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_uniform_checks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Safety checks table
CREATE TABLE `safety_checks` (
  `id` char(36) NOT NULL,
  `date` date NOT NULL,
  `foot_bath_ok` tinyint(1) NOT NULL DEFAULT '0',
  `chlorine_photo_id` char(36) DEFAULT NULL,
  `notes` text,
  `checked_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_safety_checks_date` (`date`),
  KEY `fk_safety_checks_checked_by` (`checked_by`),
  KEY `fk_safety_checks_created_by` (`created_by`),
  KEY `fk_safety_checks_updated_by` (`updated_by`),
  CONSTRAINT `fk_safety_checks_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_safety_checks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_safety_checks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Disciplinary actions table
CREATE TABLE `disciplinary_actions` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `incident_date` date NOT NULL,
  `description` text NOT NULL,
  `action_taken` text NOT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `evidence_file_id` char(36) DEFAULT NULL,
  `created_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_disciplinary_actions_user` (`user_id`),
  KEY `fk_disciplinary_actions_created_by` (`created_by`),
  KEY `fk_disciplinary_actions_updated_by` (`updated_by`),
  CONSTRAINT `fk_disciplinary_actions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_disciplinary_actions_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_disciplinary_actions_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Leave requests table
CREATE TABLE `leave_requests` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `decided_by` char(36) DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_leave_requests_user` (`user_id`),
  KEY `fk_leave_requests_decided_by` (`decided_by`),
  KEY `fk_leave_requests_created_by` (`created_by`),
  KEY `fk_leave_requests_updated_by` (`updated_by`),
  CONSTRAINT `fk_leave_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_leave_requests_decided_by` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leave_requests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_leave_requests_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicle checks table
CREATE TABLE `vehicle_checks` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `date` date NOT NULL,
  `clean` tinyint(1) NOT NULL DEFAULT '0',
  `tyres_ok` tinyint(1) NOT NULL DEFAULT '0',
  `lights_ok` tinyint(1) NOT NULL DEFAULT '0',
  `docs_ok` tinyint(1) NOT NULL DEFAULT '0',
  `issues_text` text,
  `photo_id` char(36) DEFAULT NULL,
  `checked_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vehicle_checks_vehicle_date` (`vehicle_id`, `date`),
  KEY `fk_vehicle_checks_vehicle` (`vehicle_id`),
  KEY `fk_vehicle_checks_checked_by` (`checked_by`),
  KEY `fk_vehicle_checks_created_by` (`created_by`),
  KEY `fk_vehicle_checks_updated_by` (`updated_by`),
  CONSTRAINT `fk_vehicle_checks_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_vehicle_checks_checked_by` FOREIGN KEY (`checked_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_vehicle_checks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vehicle_checks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Services table
CREATE TABLE `services` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `service_date` date NOT NULL,
  `odometer` int NOT NULL,
  `service_type` varchar(100) NOT NULL,
  `cost` decimal(14,2) NOT NULL,
  `invoice_doc_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_services_vehicle` (`vehicle_id`),
  KEY `fk_services_created_by` (`created_by`),
  KEY `fk_services_updated_by` (`updated_by`),
  CONSTRAINT `fk_services_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_services_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_services_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fuel logs table
CREATE TABLE `fuel_logs` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `date` date NOT NULL,
  `liters` decimal(10,2) NOT NULL,
  `cost` decimal(14,2) NOT NULL,
  `odometer` int NOT NULL,
  `receipt_photo_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_fuel_logs_vehicle` (`vehicle_id`),
  KEY `fk_fuel_logs_created_by` (`created_by`),
  KEY `fk_fuel_logs_updated_by` (`updated_by`),
  CONSTRAINT `fk_fuel_logs_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_fuel_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fuel_logs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Driver assignments table
CREATE TABLE `driver_assignments` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `driver_id` char(36) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_driver_assignments_vehicle` (`vehicle_id`),
  KEY `fk_driver_assignments_driver` (`driver_id`),
  KEY `fk_driver_assignments_created_by` (`created_by`),
  KEY `fk_driver_assignments_updated_by` (`updated_by`),
  CONSTRAINT `fk_driver_assignments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_driver_assignments_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_driver_assignments_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_driver_assignments_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insurance policies table
CREATE TABLE `insurance_policies` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `provider` varchar(100) NOT NULL,
  `policy_no` varchar(50) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `doc_file_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_insurance_policies_vehicle` (`vehicle_id`),
  KEY `fk_insurance_policies_created_by` (`created_by`),
  KEY `fk_insurance_policies_updated_by` (`updated_by`),
  CONSTRAINT `fk_insurance_policies_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_insurance_policies_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_insurance_policies_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- NTSA inspections table
CREATE TABLE `ntsa_inspections` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `inspection_date` date NOT NULL,
  `result` enum('pass','fail') NOT NULL,
  `next_due` date NOT NULL,
  `doc_file_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_ntsa_inspections_vehicle` (`vehicle_id`),
  KEY `fk_ntsa_inspections_created_by` (`created_by`),
  KEY `fk_ntsa_inspections_updated_by` (`updated_by`),
  CONSTRAINT `fk_ntsa_inspections_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_ntsa_inspections_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ntsa_inspections_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Speed governor logs table
CREATE TABLE `speed_governor_logs` (
  `id` char(36) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `check_date` date NOT NULL,
  `status` enum('active','faulty','removed') NOT NULL,
  `notes` text,
  `evidence_file_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_speed_governor_logs_vehicle` (`vehicle_id`),
  KEY `fk_speed_governor_logs_created_by` (`created_by`),
  KEY `fk_speed_governor_logs_updated_by` (`updated_by`),
  CONSTRAINT `fk_speed_governor_logs_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_speed_governor_logs_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_speed_governor_logs_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Files table
CREATE TABLE `files` (
  `id` char(36) NOT NULL,
  `storage_key` varchar(500) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `size_bytes` bigint NOT NULL,
  `sha256` varchar(64) NOT NULL,
  `uploaded_by` char(36) NOT NULL,
  `caption` varchar(500) DEFAULT NULL,
  `tags` json DEFAULT NULL,
  `entity` varchar(50) DEFAULT NULL,
  `entity_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_files_sha256` (`sha256`),
  KEY `fk_files_uploaded_by` (`uploaded_by`),
  KEY `idx_files_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_files_uploaded_by` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Voice notes table
CREATE TABLE `voice_notes` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `recorded_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `file_id` char(36) NOT NULL,
  `transcript_text` text,
  `language` varchar(10) DEFAULT 'en',
  `auto_tags` json DEFAULT NULL,
  `linked_entity` varchar(50) DEFAULT NULL,
  `linked_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_voice_notes_user` (`user_id`),
  KEY `fk_voice_notes_file` (`file_id`),
  KEY `idx_voice_notes_linked` (`linked_entity`, `linked_id`),
  CONSTRAINT `fk_voice_notes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_voice_notes_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Media albums table
CREATE TABLE `media_albums` (
  `id` char(36) NOT NULL,
  `name` varchar(200) NOT NULL,
  `description` text,
  `created_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_media_albums_created_by` (`created_by`),
  CONSTRAINT `fk_media_albums_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Album items table
CREATE TABLE `album_items` (
  `id` char(36) NOT NULL,
  `album_id` char(36) NOT NULL,
  `file_id` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_album_items_album_file` (`album_id`, `file_id`),
  KEY `fk_album_items_album` (`album_id`),
  KEY `fk_album_items_file` (`file_id`),
  CONSTRAINT `fk_album_items_album` FOREIGN KEY (`album_id`) REFERENCES `media_albums` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_album_items_file` FOREIGN KEY (`file_id`) REFERENCES `files` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tasks table
CREATE TABLE `tasks` (
  `id` char(36) NOT NULL,
  `title` varchar(200) NOT NULL,
  `description` text,
  `assigned_to` char(36) NOT NULL,
  `due_at` timestamp NOT NULL,
  `priority` enum('low','normal','high') NOT NULL DEFAULT 'normal',
  `status` enum('todo','doing','done') NOT NULL DEFAULT 'todo',
  `ref_entity` varchar(50) DEFAULT NULL,
  `ref_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_tasks_assigned_to` (`assigned_to`),
  KEY `idx_tasks_ref` (`ref_entity`, `ref_id`),
  KEY `fk_tasks_created_by` (`created_by`),
  KEY `fk_tasks_updated_by` (`updated_by`),
  CONSTRAINT `fk_tasks_assigned_to` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_tasks_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_tasks_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Task comments table
CREATE TABLE `task_comments` (
  `id` char(36) NOT NULL,
  `task_id` char(36) NOT NULL,
  `comment_text` text NOT NULL,
  `commented_by` char(36) NOT NULL,
  `commented_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_task_comments_task` (`task_id`),
  KEY `fk_task_comments_commented_by` (`commented_by`),
  CONSTRAINT `fk_task_comments_task` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_task_comments_commented_by` FOREIGN KEY (`commented_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notifications table
CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `channel` enum('in_app','email','sms') NOT NULL DEFAULT 'in_app',
  `title` varchar(200) NOT NULL,
  `body` text NOT NULL,
  `sent_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_notifications_user` (`user_id`),
  KEY `idx_notifications_sent_at` (`sent_at`),
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLAs table
CREATE TABLE `slas` (
  `id` char(36) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `target_minutes` int NOT NULL,
  `applies_to_entity` varchar(50) NOT NULL,
  `applies_to_action` varchar(50) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slas_code` (`code`),
  KEY `fk_slas_created_by` (`created_by`),
  KEY `fk_slas_updated_by` (`updated_by`),
  CONSTRAINT `fk_slas_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_slas_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SLA breaches table
CREATE TABLE `sla_breaches` (
  `id` char(36) NOT NULL,
  `sla_id` char(36) NOT NULL,
  `entity` varchar(50) NOT NULL,
  `entity_id` char(36) NOT NULL,
  `breached_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_sla_breaches_sla` (`sla_id`),
  KEY `idx_sla_breaches_entity` (`entity`, `entity_id`),
  CONSTRAINT `fk_sla_breaches_sla` FOREIGN KEY (`sla_id`) REFERENCES `slas` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA FOR HR, FLEET & MEDIA
-- =====================================================

-- Insert shifts
INSERT INTO `shifts` (`id`, `name`, `start_time`, `end_time`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441701', 'Morning Shift', '06:00:00', '14:00:00', NOW()),
('550e8400-e29b-41d4-a716-446655441702', 'Afternoon Shift', '14:00:00', '22:00:00', NOW()),
('550e8400-e29b-41d4-a716-446655441703', 'Night Shift', '22:00:00', '06:00:00', NOW());

-- Insert SLAs
INSERT INTO `slas` (`id`, `code`, `name`, `target_minutes`, `applies_to_entity`, `applies_to_action`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441801', 'QA_TEST_RESPONSE', 'QA Test Response Time', 30, 'water_tests', 'verification', NOW()),
('550e8400-e29b-41d4-a716-446655441802', 'DELIVERY_CONFIRMATION', 'Delivery Confirmation Time', 60, 'deliveries', 'confirmation', NOW()),
('550e8400-e29b-41d4-a716-446655441803', 'RECONCILIATION_COMPLETION', 'Daily Reconciliation Completion', 120, 'reconciliations', 'completion', NOW());
