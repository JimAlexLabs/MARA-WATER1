-- =====================================================
-- MARA-WATER FIXED DATABASE SETUP
-- Water Company Management System - Fixed Installation
-- =====================================================

-- Use the existing database
USE `mara_water`;

-- Set SQL mode for strict compliance
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- =====================================================
-- SECURITY & ORGANIZATION TABLES
-- =====================================================

-- Departments table
CREATE TABLE IF NOT EXISTS `departments` (
  `id` char(36) NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_departments_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles table
CREATE TABLE IF NOT EXISTS `roles` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `is_system` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table
CREATE TABLE IF NOT EXISTS `permissions` (
  `id` char(36) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `module` varchar(50) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_permissions_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table
CREATE TABLE IF NOT EXISTS `users` (
  `id` char(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `avatar_url` varchar(512) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` datetime DEFAULT NULL,
  `role_id` char(36) DEFAULT NULL,
  `department_id` char(36) DEFAULT NULL,
  `two_factor_secret` varchar(255) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_department` (`department_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insert basic roles
INSERT IGNORE INTO `roles` (`id`, `code`, `name`, `description`, `is_system`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'ADMIN', 'Director / Admin', 'Full system access and administration', 1),
('550e8400-e29b-41d4-a716-446655440002', 'QA', 'Quality Assurance', 'QA testing and batch management', 1),
('550e8400-e29b-41d4-a716-446655440003', 'B_P', 'Bottling & Packaging', 'Production and packaging operations', 1),
('550e8400-e29b-41d4-a716-446655440004', 'RIC', 'RIC / Lab Tech', 'Laboratory testing and validation', 1),
('550e8400-e29b-41d4-a716-446655440005', 'SMM', 'Sales & Marketing Manager', 'Sales management and customer relations', 1),
('550e8400-e29b-41d4-a716-446655440006', 'SO', 'Sales Officer', 'Customer orders and delivery', 1),
('550e8400-e29b-41d4-a716-446655440007', 'DRV', 'Driver', 'Vehicle operations and delivery', 1),
('550e8400-e29b-41d4-a716-446655440008', 'FO', 'Finance Officer', 'Financial operations and reconciliation', 1),
('550e8400-e29b-41d4-a716-446655440009', 'STK', 'Storekeeper', 'Inventory and stock management', 1),
('550e8400-e29b-41d4-a716-446655440010', 'AUD', 'Auditor', 'Read-only access for auditing', 1);

-- Insert basic departments
INSERT IGNORE INTO `departments` (`id`, `code`, `name`) VALUES
('550e8400-e29b-41d4-a716-446655440101', 'ADMIN', 'Administration'),
('550e8400-e29b-41d4-a716-446655440102', 'QA', 'Quality Assurance'),
('550e8400-e29b-41d4-a716-446655440103', 'PROD', 'Production'),
('550e8400-e29b-41d4-a716-446655440104', 'SALES', 'Sales & Marketing'),
('550e8400-e29b-41d4-a716-446655440105', 'FIN', 'Finance'),
('550e8400-e29b-41d4-a716-446655440106', 'FLEET', 'Fleet Management'),
('550e8400-e29b-41d4-a716-446655440107', 'HR', 'Human Resources');

-- Insert director user
INSERT IGNORE INTO `users` (`id`, `email`, `phone`, `password_hash`, `first_name`, `last_name`, `status`, `role_id`, `department_id`) VALUES
('550e8400-e29b-41d4-a716-446655440201', 'director@marawater.com', '+254700000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Managing', 'Director', 'active', '550e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440101');

COMMIT;
