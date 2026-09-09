-- =====================================================
-- MARA-WATER COMPLETE DATABASE SETUP
-- Water Company Management System - Complete Installation
-- =====================================================

-- This file contains the complete database setup for MARA-WATER system
-- Execute this file in phpMyAdmin to create the entire database structure

-- =====================================================
-- DATABASE CREATION
-- =====================================================

-- Create the database
CREATE DATABASE IF NOT EXISTS `MARA-WATER` 
CHARACTER SET utf8mb4 
COLLATE utf8mb4_unicode_ci;

-- Use the database
USE `MARA-WATER`;

-- Set SQL mode for strict compliance
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";

-- =====================================================
-- SECURITY & ORGANIZATION TABLES
-- =====================================================

-- Departments table
CREATE TABLE `departments` (
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
CREATE TABLE `roles` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `is_system` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_roles_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Permissions table
CREATE TABLE `permissions` (
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

-- Role permissions junction table
CREATE TABLE `role_permissions` (
  `id` char(36) NOT NULL,
  `role_id` char(36) NOT NULL,
  `permission_id` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_role_permissions` (`role_id`, `permission_id`),
  KEY `fk_role_permissions_role` (`role_id`),
  KEY `fk_role_permissions_permission` (`permission_id`),
  CONSTRAINT `fk_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_role_permissions_permission` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Users table with single Director constraint
CREATE TABLE `users` (
  `id` char(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `status` enum('active','inactive','suspended') NOT NULL DEFAULT 'active',
  `last_login_at` timestamp NULL DEFAULT NULL,
  `role_id` char(36) NOT NULL,
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
  UNIQUE KEY `uk_users_phone` (`phone`),
  KEY `fk_users_role` (`role_id`),
  KEY `fk_users_department` (`department_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  CONSTRAINT `fk_users_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- User sessions table
CREATE TABLE `user_sessions` (
  `id` char(36) NOT NULL,
  `user_id` char(36) NOT NULL,
  `device_info` text,
  `ip` varchar(45) DEFAULT NULL,
  `refresh_token_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_user_sessions_user` (`user_id`),
  CONSTRAINT `fk_user_sessions_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audits table for comprehensive logging
CREATE TABLE `audits` (
  `id` char(36) NOT NULL,
  `user_id` char(36) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `entity` varchar(50) NOT NULL,
  `entity_id` char(36) DEFAULT NULL,
  `diff_json` json DEFAULT NULL,
  `meta_json` json DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_audits_user` (`user_id`),
  KEY `idx_audits_entity` (`entity`, `entity_id`),
  KEY `idx_audits_created_at` (`created_at`),
  CONSTRAINT `fk_audits_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- API keys table
CREATE TABLE `api_keys` (
  `id` char(36) NOT NULL,
  `key_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `scopes` json DEFAULT NULL,
  `created_for_user_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_api_keys_hash` (`key_hash`),
  KEY `fk_api_keys_user` (`created_for_user_id`),
  CONSTRAINT `fk_api_keys_user` FOREIGN KEY (`created_for_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Director handover logs table
CREATE TABLE `director_handover_logs` (
  `id` char(36) NOT NULL,
  `from_user_id` char(36) NOT NULL,
  `to_user_id` char(36) NOT NULL,
  `requested_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_at` timestamp NULL DEFAULT NULL,
  `notes` text,
  `evidence_file_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_director_handover_from` (`from_user_id`),
  KEY `fk_director_handover_to` (`to_user_id`),
  CONSTRAINT `fk_director_handover_from` FOREIGN KEY (`from_user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_director_handover_to` FOREIGN KEY (`to_user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- MASTER DATA TABLES
-- =====================================================

-- Suppliers table
CREATE TABLE `suppliers` (
  `id` char(36) NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `tax_pin` varchar(20) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_suppliers_created_by` (`created_by`),
  KEY `fk_suppliers_updated_by` (`updated_by`),
  CONSTRAINT `fk_suppliers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_suppliers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Materials table (raw materials)
CREATE TABLE `materials` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` varchar(50) NOT NULL,
  `uom` varchar(10) NOT NULL,
  `is_consumable` tinyint(1) NOT NULL DEFAULT '1',
  `min_level` decimal(14,3) DEFAULT '0.000',
  `lead_time_days` int DEFAULT '0',
  `supplier_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_materials_code` (`code`),
  KEY `fk_materials_supplier` (`supplier_id`),
  KEY `fk_materials_created_by` (`created_by`),
  KEY `fk_materials_updated_by` (`updated_by`),
  CONSTRAINT `fk_materials_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_materials_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_materials_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- SKUs table (finished products)
CREATE TABLE `skus` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `size_liters` decimal(5,2) NOT NULL,
  `unit` varchar(10) NOT NULL DEFAULT 'BOTTLE',
  `expiry_days` int NOT NULL DEFAULT '365',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_skus_code` (`code`),
  KEY `fk_skus_created_by` (`created_by`),
  KEY `fk_skus_updated_by` (`updated_by`),
  CONSTRAINT `fk_skus_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_skus_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- BOM (Bill of Materials) items table
CREATE TABLE `bom_items` (
  `id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `material_id` char(36) NOT NULL,
  `qty_per_unit` decimal(12,4) NOT NULL,
  `uom` varchar(10) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bom_items_sku_material` (`sku_id`, `material_id`),
  KEY `fk_bom_items_sku` (`sku_id`),
  KEY `fk_bom_items_material` (`material_id`),
  KEY `fk_bom_items_created_by` (`created_by`),
  KEY `fk_bom_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_bom_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_items_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bom_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bom_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Warehouses table
CREATE TABLE `warehouses` (
  `id` char(36) NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_warehouses_code` (`code`),
  KEY `fk_warehouses_created_by` (`created_by`),
  KEY `fk_warehouses_updated_by` (`updated_by`),
  CONSTRAINT `fk_warehouses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_warehouses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Routes table
CREATE TABLE `routes` (
  `id` char(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_routes_created_by` (`created_by`),
  KEY `fk_routes_updated_by` (`updated_by`),
  CONSTRAINT `fk_routes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_routes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Vehicles table
CREATE TABLE `vehicles` (
  `id` char(36) NOT NULL,
  `reg_no` varchar(20) NOT NULL,
  `make` varchar(50) NOT NULL,
  `model` varchar(50) NOT NULL,
  `year` int DEFAULT NULL,
  `capacity` decimal(10,2) DEFAULT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `insurance_expiry` date DEFAULT NULL,
  `inspection_expiry` date DEFAULT NULL,
  `speed_gov_status` enum('active','faulty','removed') DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_vehicles_reg_no` (`reg_no`),
  KEY `fk_vehicles_created_by` (`created_by`),
  KEY `fk_vehicles_updated_by` (`updated_by`),
  CONSTRAINT `fk_vehicles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_vehicles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price lists table
CREATE TABLE `price_lists` (
  `id` char(36) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_price_lists_created_by` (`created_by`),
  KEY `fk_price_lists_updated_by` (`updated_by`),
  CONSTRAINT `fk_price_lists_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_price_lists_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price list items table
CREATE TABLE `price_list_items` (
  `id` char(36) NOT NULL,
  `price_list_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'KES',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_price_list_items` (`price_list_id`, `sku_id`),
  KEY `fk_price_list_items_price_list` (`price_list_id`),
  KEY `fk_price_list_items_sku` (`sku_id`),
  KEY `fk_price_list_items_created_by` (`created_by`),
  KEY `fk_price_list_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_price_list_items_price_list` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_price_list_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_price_list_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_price_list_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Customers table
CREATE TABLE `customers` (
  `id` char(36) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(200) NOT NULL,
  `type` enum('retail','wholesale','corporate') NOT NULL DEFAULT 'retail',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text,
  `route_id` char(36) DEFAULT NULL,
  `price_tier` varchar(20) DEFAULT 'standard',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_customers_code` (`code`),
  KEY `fk_customers_route` (`route_id`),
  KEY `fk_customers_created_by` (`created_by`),
  KEY `fk_customers_updated_by` (`updated_by`),
  CONSTRAINT `fk_customers_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_customers_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Price agreements table (customer-specific pricing)
CREATE TABLE `price_agreements` (
  `id` char(36) NOT NULL,
  `customer_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `valid_from` date NOT NULL,
  `valid_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_price_agreements_customer_sku` (`customer_id`, `sku_id`),
  KEY `fk_price_agreements_customer` (`customer_id`),
  KEY `fk_price_agreements_sku` (`sku_id`),
  KEY `fk_price_agreements_created_by` (`created_by`),
  KEY `fk_price_agreements_updated_by` (`updated_by`),
  CONSTRAINT `fk_price_agreements_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_price_agreements_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_price_agreements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_price_agreements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA INSERTION
-- =====================================================

-- Insert departments
INSERT INTO `departments` (`id`, `code`, `name`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'QA', 'Quality Assurance', NOW()),
('550e8400-e29b-41d4-a716-446655440002', 'PROD', 'Production', NOW()),
('550e8400-e29b-41d4-a716-446655440003', 'SALES', 'Sales & Marketing', NOW()),
('550e8400-e29b-41d4-a716-446655440004', 'FIN', 'Finance', NOW()),
('550e8400-e29b-41d4-a716-446655440005', 'INV', 'Inventory', NOW()),
('550e8400-e29b-41d4-a716-446655440006', 'FLEET', 'Fleet Management', NOW()),
('550e8400-e29b-41d4-a716-446655440007', 'HR', 'Human Resources', NOW()),
('550e8400-e29b-41d4-a716-446655440008', 'ADMIN', 'Administration', NOW());

-- Insert roles
INSERT INTO `roles` (`id`, `code`, `name`, `description`, `is_system`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440101', 'ADMIN', 'Director', 'Managing Director with full system access', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440102', 'QA', 'Quality Assurance', 'QA Officer responsible for water testing and quality control', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440103', 'RIC', 'RIC/Lab Tech', 'Lab Technician for validation and calibration', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440104', 'BP', 'Bottling & Packaging', 'Production staff for bottling and packaging operations', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440105', 'SMM', 'Sales & Marketing Manager', 'Sales and marketing management', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440106', 'SO', 'Sales Officer', 'Sales officer for customer orders and delivery', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440107', 'DRV', 'Driver', 'Delivery driver for distribution', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440108', 'FO', 'Finance Officer', 'Finance officer for reconciliations and accounting', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440109', 'STK', 'Storekeeper', 'Inventory and stock management', 1, NOW()),
('550e8400-e29b-41d4-a716-446655440110', 'AUD', 'Auditor', 'Read-only access for auditing purposes', 1, NOW());

-- Insert warehouses
INSERT INTO `warehouses` (`id`, `code`, `name`, `address`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440301', 'MAIN', 'Main Warehouse', 'Mara Water Factory, Industrial Area, Nairobi', NOW()),
('550e8400-e29b-41d4-a716-446655440302', 'COLD', 'Cold Storage', 'Mara Water Factory, Cold Storage Unit', NOW());

-- Insert routes
INSERT INTO `routes` (`id`, `name`, `description`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440401', 'Route A - Westlands', 'Westlands, Kilimani, Lavington area', NOW()),
('550e8400-e29b-41d4-a716-446655440402', 'Route B - Eastlands', 'Eastlands, Buruburu, Donholm area', NOW()),
('550e8400-e29b-41d4-a716-446655440403', 'Route C - CBD', 'Central Business District and surrounding areas', NOW()),
('550e8400-e29b-41d4-a716-446655440404', 'Route D - Industrial', 'Industrial Area and nearby commercial zones', NOW());

-- Insert vehicles
INSERT INTO `vehicles` (`id`, `reg_no`, `make`, `model`, `year`, `capacity`, `active`, `insurance_expiry`, `inspection_expiry`, `speed_gov_status`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440501', 'KCA 123A', 'Isuzu', 'NQR75', 2020, 5000.00, 1, '2024-12-31', '2024-06-30', 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440502', 'KCA 456B', 'Isuzu', 'NQR75', 2021, 5000.00, 1, '2024-12-31', '2024-07-15', 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440503', 'KCA 789C', 'Toyota', 'Hilux', 2019, 1000.00, 1, '2024-11-30', '2024-05-20', 'active', NOW());

-- Insert SKUs
INSERT INTO `skus` (`id`, `code`, `name`, `size_liters`, `unit`, `expiry_days`, `active`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440601', 'WATER-0.5L', 'Mara Water 0.5L Bottle', 0.50, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440602', 'WATER-1L', 'Mara Water 1L Bottle', 1.00, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440603', 'WATER-1.5L', 'Mara Water 1.5L Bottle', 1.50, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440604', 'WATER-5L', 'Mara Water 5L Container', 5.00, 'CONTAINER', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440605', 'WATER-20L', 'Mara Water 20L Container', 20.00, 'CONTAINER', 365, 1, NOW());

-- Insert price lists
INSERT INTO `price_lists` (`id`, `name`, `is_default`, `valid_from`, `valid_to`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441001', 'Standard Price List 2024', 1, '2024-01-01', '2024-12-31', NOW()),
('550e8400-e29b-41d4-a716-446655441002', 'Wholesale Price List 2024', 0, '2024-01-01', '2024-12-31', NOW()),
('550e8400-e29b-41d4-a716-446655441003', 'Corporate Price List 2024', 0, '2024-01-01', '2024-12-31', NOW());

-- Insert price list items
INSERT INTO `price_list_items` (`id`, `price_list_id`, `sku_id`, `unit_price`, `currency`, `created_at`) VALUES
-- Standard Prices
('550e8400-e29b-41d4-a716-446655441101', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440601', 25.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441102', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440602', 45.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441103', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440603', 65.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441104', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440604', 200.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441105', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440605', 750.00, 'KES', NOW());

-- =====================================================
-- CREATE DEFAULT DIRECTOR USER
-- =====================================================

-- Create default Director user (password: Admin@2024)
INSERT INTO `users` (`id`, `email`, `phone`, `password_hash`, `first_name`, `last_name`, `status`, `role_id`, `department_id`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'director@marawater.com', '+254700000000', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Managing', 'Director', 'active', '550e8400-e29b-41d4-a716-446655440101', '550e8400-e29b-41d4-a716-446655440008', NOW());

-- =====================================================
-- SUCCESS MESSAGE
-- =====================================================

SELECT 'MARA-WATER Database Setup Complete!' as message;
SELECT 'Default Director Login:' as info;
SELECT 'Email: director@marawater.com' as email;
SELECT 'Password: Admin@2024' as password;
SELECT 'Please change the default password after first login.' as security_note;

COMMIT;
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
-- =====================================================
-- INVENTORY MODULE
-- Stock Management, Purchase Orders, Goods Receipts, and Stock Counts
-- =====================================================

-- Stock items table (for both materials and SKUs)
CREATE TABLE `stock_items` (
  `id` char(36) NOT NULL,
  `item_type` enum('material','sku') NOT NULL,
  `material_id` char(36) DEFAULT NULL,
  `sku_id` char(36) DEFAULT NULL,
  `warehouse_id` char(36) NOT NULL,
  `batch_id` char(36) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL DEFAULT '0.000',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_items_unique` (`item_type`, `material_id`, `sku_id`, `warehouse_id`, `batch_id`),
  KEY `fk_stock_items_material` (`material_id`),
  KEY `fk_stock_items_sku` (`sku_id`),
  KEY `fk_stock_items_warehouse` (`warehouse_id`),
  KEY `fk_stock_items_batch` (`batch_id`),
  KEY `fk_stock_items_created_by` (`created_by`),
  KEY `fk_stock_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_items_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_items_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_stock_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock moves table (all transactions)
CREATE TABLE `stock_moves` (
  `id` char(36) NOT NULL,
  `move_type` enum('grn','issue','produce','adjust','transfer','return') NOT NULL,
  `item_type` enum('material','sku') NOT NULL,
  `material_id` char(36) DEFAULT NULL,
  `sku_id` char(36) DEFAULT NULL,
  `batch_id` char(36) DEFAULT NULL,
  `warehouse_from_id` char(36) DEFAULT NULL,
  `warehouse_to_id` char(36) DEFAULT NULL,
  `qty` decimal(14,3) NOT NULL,
  `uom` varchar(10) NOT NULL,
  `unit_cost` decimal(12,4) DEFAULT '0.0000',
  `ref_entity` varchar(50) DEFAULT NULL,
  `ref_id` char(36) DEFAULT NULL,
  `moved_by` char(36) NOT NULL,
  `moved_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stock_moves_material` (`material_id`),
  KEY `fk_stock_moves_sku` (`sku_id`),
  KEY `fk_stock_moves_batch` (`batch_id`),
  KEY `fk_stock_moves_warehouse_from` (`warehouse_from_id`),
  KEY `fk_stock_moves_warehouse_to` (`warehouse_to_id`),
  KEY `fk_stock_moves_moved_by` (`moved_by`),
  KEY `idx_stock_moves_ref` (`ref_entity`, `ref_id`),
  KEY `idx_stock_moves_moved_at` (`moved_at`),
  KEY `fk_stock_moves_created_by` (`created_by`),
  KEY `fk_stock_moves_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_moves_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_warehouse_from` FOREIGN KEY (`warehouse_from_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_warehouse_to` FOREIGN KEY (`warehouse_to_id`) REFERENCES `warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_moved_by` FOREIGN KEY (`moved_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_moves_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_moves_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase orders table
CREATE TABLE `purchase_orders` (
  `id` char(36) NOT NULL,
  `supplier_id` char(36) NOT NULL,
  `po_no` varchar(20) NOT NULL,
  `status` enum('draft','sent','received','closed') NOT NULL DEFAULT 'draft',
  `ordered_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expected_at` date DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'KES',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_purchase_orders_po_no` (`po_no`),
  KEY `fk_purchase_orders_supplier` (`supplier_id`),
  KEY `fk_purchase_orders_created_by` (`created_by`),
  KEY `fk_purchase_orders_updated_by` (`updated_by`),
  CONSTRAINT `fk_purchase_orders_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`),
  CONSTRAINT `fk_purchase_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_purchase_orders_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Purchase order items table
CREATE TABLE `po_items` (
  `id` char(36) NOT NULL,
  `po_id` char(36) NOT NULL,
  `material_id` char(36) NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `uom` varchar(10) NOT NULL,
  `unit_price` decimal(12,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_po_items_po` (`po_id`),
  KEY `fk_po_items_material` (`material_id`),
  KEY `fk_po_items_created_by` (`created_by`),
  KEY `fk_po_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_po_items_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_po_items_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_po_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_po_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Goods receipts table
CREATE TABLE `goods_receipts` (
  `id` char(36) NOT NULL,
  `po_id` char(36) NOT NULL,
  `grn_no` varchar(20) NOT NULL,
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `warehouse_id` char(36) NOT NULL,
  `received_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_goods_receipts_grn_no` (`grn_no`),
  KEY `fk_goods_receipts_po` (`po_id`),
  KEY `fk_goods_receipts_warehouse` (`warehouse_id`),
  KEY `fk_goods_receipts_received_by` (`received_by`),
  KEY `fk_goods_receipts_created_by` (`created_by`),
  KEY `fk_goods_receipts_updated_by` (`updated_by`),
  CONSTRAINT `fk_goods_receipts_po` FOREIGN KEY (`po_id`) REFERENCES `purchase_orders` (`id`),
  CONSTRAINT `fk_goods_receipts_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_goods_receipts_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_goods_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_goods_receipts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GRN items table
CREATE TABLE `grn_items` (
  `id` char(36) NOT NULL,
  `grn_id` char(36) NOT NULL,
  `material_id` char(36) NOT NULL,
  `qty` decimal(14,3) NOT NULL,
  `uom` varchar(10) NOT NULL,
  `unit_cost` decimal(12,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_grn_items_grn` (`grn_id`),
  KEY `fk_grn_items_material` (`material_id`),
  KEY `fk_grn_items_created_by` (`created_by`),
  KEY `fk_grn_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_grn_items_grn` FOREIGN KEY (`grn_id`) REFERENCES `goods_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_grn_items_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`),
  CONSTRAINT `fk_grn_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_grn_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock counts table
CREATE TABLE `stock_counts` (
  `id` char(36) NOT NULL,
  `warehouse_id` char(36) NOT NULL,
  `count_date` date NOT NULL,
  `status` enum('open','posted') NOT NULL DEFAULT 'open',
  `counted_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_stock_counts_warehouse_date` (`warehouse_id`, `count_date`),
  KEY `fk_stock_counts_warehouse` (`warehouse_id`),
  KEY `fk_stock_counts_counted_by` (`counted_by`),
  KEY `fk_stock_counts_created_by` (`created_by`),
  KEY `fk_stock_counts_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_counts_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_stock_counts_counted_by` FOREIGN KEY (`counted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_stock_counts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_counts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Stock count items table
CREATE TABLE `stock_count_items` (
  `id` char(36) NOT NULL,
  `count_id` char(36) NOT NULL,
  `item_type` enum('material','sku') NOT NULL,
  `material_id` char(36) DEFAULT NULL,
  `sku_id` char(36) DEFAULT NULL,
  `batch_id` char(36) DEFAULT NULL,
  `counted_qty` decimal(14,3) NOT NULL,
  `system_qty` decimal(14,3) NOT NULL,
  `variance` decimal(14,3) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_stock_count_items_count` (`count_id`),
  KEY `fk_stock_count_items_material` (`material_id`),
  KEY `fk_stock_count_items_sku` (`sku_id`),
  KEY `fk_stock_count_items_batch` (`batch_id`),
  KEY `fk_stock_count_items_created_by` (`created_by`),
  KEY `fk_stock_count_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_stock_count_items_count` FOREIGN KEY (`count_id`) REFERENCES `stock_counts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_stock_count_items_material` FOREIGN KEY (`material_id`) REFERENCES `materials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_count_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_count_items_batch` FOREIGN KEY (`batch_id`) REFERENCES `batches` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_count_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_stock_count_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA FOR INVENTORY
-- =====================================================

-- Insert sample materials
INSERT INTO `materials` (`id`, `code`, `name`, `category`, `uom`, `is_consumable`, `min_level`, `lead_time_days`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440901', 'BOTTLE-0.5L', '0.5L PET Bottles', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440902', 'BOTTLE-1L', '1L PET Bottles', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440903', 'BOTTLE-1.5L', '1.5L PET Bottles', 'Packaging', 'PCS', 1, 500.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440904', 'CAP-0.5L', '0.5L Bottle Caps', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440905', 'CAP-1L', '1L Bottle Caps', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440906', 'CAP-1.5L', '1.5L Bottle Caps', 'Packaging', 'PCS', 1, 500.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440907', 'LABEL-0.5L', '0.5L Bottle Labels', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440908', 'LABEL-1L', '1L Bottle Labels', 'Packaging', 'PCS', 1, 1000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440909', 'LABEL-1.5L', '1.5L Bottle Labels', 'Packaging', 'PCS', 1, 500.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440910', 'CHLORINE', 'Chlorine Solution', 'Chemical', 'L', 1, 50.000, 3, NOW()),
('550e8400-e29b-41d4-a716-446655440911', 'SHRINK-WRAP', 'Shrink Wrap Film', 'Packaging', 'KG', 1, 100.000, 5, NOW()),
('550e8400-e29b-41d4-a716-446655440912', 'CARTON-0.5L', '0.5L Cartons (24pcs)', 'Packaging', 'PCS', 1, 50.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440913', 'CARTON-1L', '1L Cartons (12pcs)', 'Packaging', 'PCS', 1, 50.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440914', 'CARTON-1.5L', '1.5L Cartons (8pcs)', 'Packaging', 'PCS', 1, 25.000, 7, NOW());

-- Insert BOM items
INSERT INTO `bom_items` (`id`, `sku_id`, `material_id`, `qty_per_unit`, `uom`, `created_at`) VALUES
-- 0.5L Bottle BOM
('550e8400-e29b-41d4-a716-446655440951', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440901', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440952', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440904', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440953', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440907', 1.0000, 'PCS', NOW()),
-- 1L Bottle BOM
('550e8400-e29b-41d4-a716-446655440954', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440902', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440955', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440905', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440956', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440908', 1.0000, 'PCS', NOW()),
-- 1.5L Bottle BOM
('550e8400-e29b-41d4-a716-446655440957', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440903', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440958', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440906', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440959', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440909', 1.0000, 'PCS', NOW());
-- =====================================================
-- SALES & DISTRIBUTION MODULE
-- Orders, Manifests, Deliveries, and Returns
-- =====================================================

-- Orders table
CREATE TABLE `orders` (
  `id` char(36) NOT NULL,
  `order_no` varchar(20) NOT NULL,
  `customer_id` char(36) NOT NULL,
  `route_id` char(36) DEFAULT NULL,
  `sales_officer_id` char(36) NOT NULL,
  `status` enum('draft','confirmed','dispatched','delivered','partially_returned','cancelled') NOT NULL DEFAULT 'draft',
  `order_date` date NOT NULL,
  `requested_date` date DEFAULT NULL,
  `price_list_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_orders_order_no` (`order_no`),
  KEY `fk_orders_customer` (`customer_id`),
  KEY `fk_orders_route` (`route_id`),
  KEY `fk_orders_sales_officer` (`sales_officer_id`),
  KEY `fk_orders_price_list` (`price_list_id`),
  KEY `fk_orders_created_by` (`created_by`),
  KEY `fk_orders_updated_by` (`updated_by`),
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_orders_route` FOREIGN KEY (`route_id`) REFERENCES `routes` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_sales_officer` FOREIGN KEY (`sales_officer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_orders_price_list` FOREIGN KEY (`price_list_id`) REFERENCES `price_lists` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_orders_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Order items table
CREATE TABLE `order_items` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `qty` int NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_order_items_order` (`order_id`),
  KEY `fk_order_items_sku` (`sku_id`),
  KEY `fk_order_items_created_by` (`created_by`),
  KEY `fk_order_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_order_items_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_order_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_order_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_order_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manifests table (driver manifests)
CREATE TABLE `manifests` (
  `id` char(36) NOT NULL,
  `manifest_no` varchar(20) NOT NULL,
  `vehicle_id` char(36) NOT NULL,
  `driver_id` char(36) NOT NULL,
  `sales_officer_id` char(36) NOT NULL,
  `warehouse_id` char(36) NOT NULL,
  `odometer_out` int NOT NULL,
  `odometer_in` int DEFAULT NULL,
  `fuel_liters` decimal(10,2) DEFAULT NULL,
  `start_at` timestamp NOT NULL,
  `end_at` timestamp NULL DEFAULT NULL,
  `status` enum('open','closed') NOT NULL DEFAULT 'open',
  `created_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_manifests_manifest_no` (`manifest_no`),
  KEY `fk_manifests_vehicle` (`vehicle_id`),
  KEY `fk_manifests_driver` (`driver_id`),
  KEY `fk_manifests_sales_officer` (`sales_officer_id`),
  KEY `fk_manifests_warehouse` (`warehouse_id`),
  KEY `fk_manifests_created_by` (`created_by`),
  KEY `fk_manifests_updated_by` (`updated_by`),
  CONSTRAINT `fk_manifests_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`),
  CONSTRAINT `fk_manifests_driver` FOREIGN KEY (`driver_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_manifests_sales_officer` FOREIGN KEY (`sales_officer_id`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_manifests_warehouse` FOREIGN KEY (`warehouse_id`) REFERENCES `warehouses` (`id`),
  CONSTRAINT `fk_manifests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_manifests_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manifest items table
CREATE TABLE `manifest_items` (
  `id` char(36) NOT NULL,
  `manifest_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `planned_qty` int NOT NULL,
  `loaded_qty` int NOT NULL DEFAULT '0',
  `returned_qty` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_manifest_items_manifest` (`manifest_id`),
  KEY `fk_manifest_items_sku` (`sku_id`),
  KEY `fk_manifest_items_created_by` (`created_by`),
  KEY `fk_manifest_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_manifest_items_manifest` FOREIGN KEY (`manifest_id`) REFERENCES `manifests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manifest_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_manifest_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_manifest_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Manifest signatures table
CREATE TABLE `manifest_signatures` (
  `id` char(36) NOT NULL,
  `manifest_id` char(36) NOT NULL,
  `signature_type` enum('driver','sales_officer','qa','finance','director') NOT NULL,
  `signed_by` char(36) NOT NULL,
  `signed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `signature_data` text NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_manifest_signatures_manifest_type` (`manifest_id`, `signature_type`),
  KEY `fk_manifest_signatures_manifest` (`manifest_id`),
  KEY `fk_manifest_signatures_signed_by` (`signed_by`),
  CONSTRAINT `fk_manifest_signatures_manifest` FOREIGN KEY (`manifest_id`) REFERENCES `manifests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_manifest_signatures_signed_by` FOREIGN KEY (`signed_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Deliveries table
CREATE TABLE `deliveries` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `manifest_id` char(36) DEFAULT NULL,
  `delivered_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `pod_photo_id` char(36) DEFAULT NULL,
  `customer_signature_id` char(36) DEFAULT NULL,
  `delivered_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_deliveries_order` (`order_id`),
  KEY `fk_deliveries_manifest` (`manifest_id`),
  KEY `fk_deliveries_delivered_by` (`delivered_by`),
  KEY `fk_deliveries_created_by` (`created_by`),
  KEY `fk_deliveries_updated_by` (`updated_by`),
  CONSTRAINT `fk_deliveries_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_deliveries_manifest` FOREIGN KEY (`manifest_id`) REFERENCES `manifests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_delivered_by` FOREIGN KEY (`delivered_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_deliveries_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_deliveries_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Returns table
CREATE TABLE `returns` (
  `id` char(36) NOT NULL,
  `order_id` char(36) NOT NULL,
  `reason` text NOT NULL,
  `qty_total` int NOT NULL,
  `noted_by` char(36) NOT NULL,
  `photo_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_returns_order` (`order_id`),
  KEY `fk_returns_noted_by` (`noted_by`),
  KEY `fk_returns_created_by` (`created_by`),
  KEY `fk_returns_updated_by` (`updated_by`),
  CONSTRAINT `fk_returns_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_returns_noted_by` FOREIGN KEY (`noted_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_returns_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_returns_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Return items table
CREATE TABLE `return_items` (
  `id` char(36) NOT NULL,
  `return_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `qty` int NOT NULL,
  `reason` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_return_items_return` (`return_id`),
  KEY `fk_return_items_sku` (`sku_id`),
  KEY `fk_return_items_created_by` (`created_by`),
  KEY `fk_return_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_return_items_return` FOREIGN KEY (`return_id`) REFERENCES `returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_return_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_return_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_return_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA FOR SALES & DISTRIBUTION
-- =====================================================

-- Insert sample customers
INSERT INTO `customers` (`id`, `code`, `name`, `type`, `phone`, `email`, `address`, `route_id`, `price_tier`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441201', 'CUST001', 'ABC Supermarket', 'wholesale', '+254700000001', 'abc@example.com', 'Westlands Mall, Nairobi', '550e8400-e29b-41d4-a716-446655440401', 'wholesale', NOW()),
('550e8400-e29b-41d4-a716-446655441202', 'CUST002', 'XYZ Restaurant', 'corporate', '+254700000002', 'xyz@example.com', 'CBD Building, Nairobi', '550e8400-e29b-41d4-a716-446655440403', 'corporate', NOW()),
('550e8400-e29b-41d4-a716-446655441203', 'CUST003', 'John Doe', 'retail', '+254700000003', 'john@example.com', 'Kilimani, Nairobi', '550e8400-e29b-41d4-a716-446655440401', 'standard', NOW()),
('550e8400-e29b-41d4-a716-446655441204', 'CUST004', 'Jane Smith', 'retail', '+254700000004', 'jane@example.com', 'Lavington, Nairobi', '550e8400-e29b-41d4-a716-446655440401', 'standard', NOW()),
('550e8400-e29b-41d4-a716-446655441205', 'CUST005', 'DEF Hotel', 'corporate', '+254700000005', 'def@example.com', 'Buruburu, Nairobi', '550e8400-e29b-41d4-a716-446655440402', 'corporate', NOW());
-- =====================================================
-- FINANCE MODULE
-- Invoices, Receipts, Bank Accounts, Reconciliations, and Debt Management
-- =====================================================

-- Invoices table
CREATE TABLE `invoices` (
  `id` char(36) NOT NULL,
  `invoice_no` varchar(20) NOT NULL,
  `order_id` char(36) NOT NULL,
  `customer_id` char(36) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'KES',
  `status` enum('open','paid','partial','cancelled') NOT NULL DEFAULT 'open',
  `total` decimal(14,2) NOT NULL DEFAULT '0.00',
  `tax` decimal(14,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_invoices_invoice_no` (`invoice_no`),
  KEY `fk_invoices_order` (`order_id`),
  KEY `fk_invoices_customer` (`customer_id`),
  KEY `fk_invoices_created_by` (`created_by`),
  KEY `fk_invoices_updated_by` (`updated_by`),
  CONSTRAINT `fk_invoices_order` FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`),
  CONSTRAINT `fk_invoices_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_invoices_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoices_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Invoice items table
CREATE TABLE `invoice_items` (
  `id` char(36) NOT NULL,
  `invoice_id` char(36) NOT NULL,
  `sku_id` char(36) NOT NULL,
  `qty` int NOT NULL,
  `unit_price` decimal(12,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_invoice_items_invoice` (`invoice_id`),
  KEY `fk_invoice_items_sku` (`sku_id`),
  KEY `fk_invoice_items_created_by` (`created_by`),
  KEY `fk_invoice_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_invoice_items_sku` FOREIGN KEY (`sku_id`) REFERENCES `skus` (`id`),
  CONSTRAINT `fk_invoice_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_invoice_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Receipts table
CREATE TABLE `receipts` (
  `id` char(36) NOT NULL,
  `receipt_no` varchar(20) NOT NULL,
  `customer_id` char(36) NOT NULL,
  `invoice_id` char(36) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `method` enum('cash','bank_transfer','mpesa','cheque') NOT NULL,
  `reference` varchar(100) DEFAULT NULL,
  `received_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `received_by` char(36) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_receipts_receipt_no` (`receipt_no`),
  KEY `fk_receipts_customer` (`customer_id`),
  KEY `fk_receipts_invoice` (`invoice_id`),
  KEY `fk_receipts_received_by` (`received_by`),
  KEY `fk_receipts_created_by` (`created_by`),
  KEY `fk_receipts_updated_by` (`updated_by`),
  CONSTRAINT `fk_receipts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_receipts_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_receipts_received_by` FOREIGN KEY (`received_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_receipts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_receipts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank accounts table
CREATE TABLE `bank_accounts` (
  `id` char(36) NOT NULL,
  `bank_name` varchar(100) NOT NULL,
  `account_no` varchar(50) NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'KES',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bank_accounts_account_no` (`account_no`),
  KEY `fk_bank_accounts_created_by` (`created_by`),
  KEY `fk_bank_accounts_updated_by` (`updated_by`),
  CONSTRAINT `fk_bank_accounts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bank_accounts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank statements table
CREATE TABLE `bank_statements` (
  `id` char(36) NOT NULL,
  `bank_account_id` char(36) NOT NULL,
  `statement_date` date NOT NULL,
  `source_file_id` char(36) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_bank_statements_bank_account` (`bank_account_id`),
  KEY `fk_bank_statements_created_by` (`created_by`),
  KEY `fk_bank_statements_updated_by` (`updated_by`),
  CONSTRAINT `fk_bank_statements_bank_account` FOREIGN KEY (`bank_account_id`) REFERENCES `bank_accounts` (`id`),
  CONSTRAINT `fk_bank_statements_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bank_statements_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bank lines table
CREATE TABLE `bank_lines` (
  `id` char(36) NOT NULL,
  `statement_id` char(36) NOT NULL,
  `txn_date` date NOT NULL,
  `description` text NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `balance` decimal(14,2) NOT NULL,
  `hash_key` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bank_lines_hash` (`hash_key`),
  KEY `fk_bank_lines_statement` (`statement_id`),
  KEY `fk_bank_lines_created_by` (`created_by`),
  KEY `fk_bank_lines_updated_by` (`updated_by`),
  CONSTRAINT `fk_bank_lines_statement` FOREIGN KEY (`statement_id`) REFERENCES `bank_statements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_bank_lines_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_bank_lines_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reconciliations table
CREATE TABLE `reconciliations` (
  `id` char(36) NOT NULL,
  `recon_date` date NOT NULL,
  `prepared_by` char(36) NOT NULL,
  `status` enum('open','posted') NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_reconciliations_date` (`recon_date`),
  KEY `fk_reconciliations_prepared_by` (`prepared_by`),
  KEY `fk_reconciliations_created_by` (`created_by`),
  KEY `fk_reconciliations_updated_by` (`updated_by`),
  CONSTRAINT `fk_reconciliations_prepared_by` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_reconciliations_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_reconciliations_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Reconciliation items table
CREATE TABLE `recon_items` (
  `id` char(36) NOT NULL,
  `recon_id` char(36) NOT NULL,
  `source` enum('sales','bank','cash') NOT NULL,
  `source_id` char(36) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `matched` tinyint(1) NOT NULL DEFAULT '0',
  `variance` decimal(14,2) DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_recon_items_recon` (`recon_id`),
  KEY `idx_recon_items_source` (`source`, `source_id`),
  KEY `fk_recon_items_created_by` (`created_by`),
  KEY `fk_recon_items_updated_by` (`updated_by`),
  CONSTRAINT `fk_recon_items_recon` FOREIGN KEY (`recon_id`) REFERENCES `reconciliations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_recon_items_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_recon_items_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Debts table
CREATE TABLE `debts` (
  `id` char(36) NOT NULL,
  `customer_id` char(36) NOT NULL,
  `invoice_id` char(36) NOT NULL,
  `principal` decimal(14,2) NOT NULL,
  `balance` decimal(14,2) NOT NULL,
  `days_overdue` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_debts_customer` (`customer_id`),
  KEY `fk_debts_invoice` (`invoice_id`),
  KEY `fk_debts_created_by` (`created_by`),
  KEY `fk_debts_updated_by` (`updated_by`),
  CONSTRAINT `fk_debts_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`),
  CONSTRAINT `fk_debts_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_debts_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_debts_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expenses table
CREATE TABLE `expenses` (
  `id` char(36) NOT NULL,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `payee` varchar(200) NOT NULL,
  `ref_doc_id` char(36) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_expenses_created_by` (`created_by`),
  KEY `fk_expenses_updated_by` (`updated_by`),
  CONSTRAINT `fk_expenses_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_expenses_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Taxes table
CREATE TABLE `taxes` (
  `id` char(36) NOT NULL,
  `tax_type` varchar(50) NOT NULL,
  `rate` decimal(6,3) NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `created_by` char(36) DEFAULT NULL,
  `updated_by` char(36) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_taxes_created_by` (`created_by`),
  KEY `fk_taxes_updated_by` (`updated_by`),
  CONSTRAINT `fk_taxes_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_taxes_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- SEED DATA FOR FINANCE
-- =====================================================

-- Insert bank accounts
INSERT INTO `bank_accounts` (`id`, `bank_name`, `account_no`, `currency`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441501', 'Equity Bank', '1234567890', 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441502', 'Cooperative Bank', '0987654321', 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441503', 'KCB Bank', '1122334455', 'KES', NOW());

-- Insert taxes
INSERT INTO `taxes` (`id`, `tax_type`, `rate`, `effective_from`, `effective_to`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441601', 'VAT', 16.000, '2024-01-01', NULL, NOW()),
('550e8400-e29b-41d4-a716-446655441602', 'Withholding Tax', 5.000, '2024-01-01', NULL, NOW());
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
-- =====================================================
-- CONSTRAINTS, TRIGGERS, VIEWS & STORED PROCEDURES
-- Business Rules Enforcement and Performance Optimizations
-- =====================================================

-- =====================================================
-- TRIGGERS FOR BUSINESS RULES
-- =====================================================

-- Single Director constraint triggers
DELIMITER $$

CREATE TRIGGER `enforce_single_director_before_insert`
BEFORE INSERT ON `users`
FOR EACH ROW
BEGIN
    DECLARE active_count INT DEFAULT 0;
    IF EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND code = 'ADMIN') THEN
        SELECT COUNT(*) INTO active_count
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.status = 'active'
        AND r.code = 'ADMIN'
        AND u.deleted_at IS NULL;
        IF NEW.status = 'active' AND active_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one active Director (ADMIN) is allowed at a time. Please deactivate the existing Director first.';
        END IF;
    END IF;
END$$

CREATE TRIGGER `enforce_single_director_before_update`
BEFORE UPDATE ON `users`
FOR EACH ROW
BEGIN
    DECLARE active_count INT DEFAULT 0;
    IF EXISTS (SELECT 1 FROM roles WHERE id = NEW.role_id AND code = 'ADMIN') THEN
        SELECT COUNT(*) INTO active_count
        FROM users u
        JOIN roles r ON u.role_id = r.id
        WHERE u.status = 'active'
        AND r.code = 'ADMIN'
        AND u.deleted_at IS NULL
        AND u.id != NEW.id;
        IF NEW.status = 'active' AND active_count > 0 THEN
            SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Only one active Director (ADMIN) is allowed at a time. Please deactivate the existing Director first.';
        END IF;
    END IF;
END$$

-- Batch expiry calculation trigger
CREATE TRIGGER `enforce_batch_expiry_before_insert`
BEFORE INSERT ON `batches`
FOR EACH ROW
BEGIN
    DECLARE expiry_days INT DEFAULT 365;
    SELECT s.expiry_days INTO expiry_days
    FROM skus s
    WHERE s.id = NEW.sku_id;
    SET NEW.expiry_date = DATE_ADD(NEW.manufacture_date, INTERVAL expiry_days DAY);
END$$

CREATE TRIGGER `enforce_batch_expiry_before_update`
BEFORE UPDATE ON `batches`
FOR EACH ROW
BEGIN
    DECLARE expiry_days INT DEFAULT 365;
    IF NEW.manufacture_date != OLD.manufacture_date OR NEW.sku_id != OLD.sku_id THEN
        SELECT s.expiry_days INTO expiry_days
        FROM skus s
        WHERE s.id = NEW.sku_id;
        SET NEW.expiry_date = DATE_ADD(NEW.manufacture_date, INTERVAL expiry_days DAY);
    END IF;
END$$

-- Prevent expired stock on manifests
CREATE TRIGGER `prevent_expired_stock_manifest`
BEFORE INSERT ON `manifest_items`
FOR EACH ROW
BEGIN
    DECLARE batch_expiry DATE;
    SELECT b.expiry_date INTO batch_expiry
    FROM batches b
    JOIN stock_items si ON b.id = si.batch_id
    WHERE si.sku_id = NEW.sku_id
    AND si.batch_id IS NOT NULL
    LIMIT 1;
    
    IF batch_expiry IS NOT NULL AND batch_expiry < CURDATE() THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Cannot load expired stock to manifest. Please check batch expiry dates.';
    END IF;
END$$

-- Odometer rules
CREATE TRIGGER `enforce_odometer_rules`
BEFORE UPDATE ON `manifests`
FOR EACH ROW
BEGIN
    IF NEW.odometer_in IS NOT NULL AND NEW.odometer_in < NEW.odometer_out THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Odometer reading in must be greater than or equal to odometer reading out.';
    END IF;
END$$

-- Update debt ageing
CREATE TRIGGER `update_debt_ageing`
BEFORE INSERT ON `debts`
FOR EACH ROW
BEGIN
    DECLARE invoice_due_date DATE;
    SELECT i.due_date INTO invoice_due_date
    FROM invoices i
    WHERE i.id = NEW.invoice_id;
    
    SET NEW.days_overdue = DATEDIFF(CURDATE(), invoice_due_date);
    IF NEW.days_overdue < 0 THEN
        SET NEW.days_overdue = 0;
    END IF;
END$$

DELIMITER ;

-- =====================================================
-- ADDITIONAL INDEXES FOR PERFORMANCE
-- =====================================================

-- Performance indexes for common queries
CREATE INDEX `idx_users_status_role` ON `users` (`status`, `role_id`);
CREATE INDEX `idx_water_tests_recorded_at` ON `water_tests` (`recorded_at`);
CREATE INDEX `idx_water_tests_status` ON `water_tests` (`status`);
CREATE INDEX `idx_batches_manufacture_date` ON `batches` (`manufacture_date`);
CREATE INDEX `idx_batches_expiry_date` ON `batches` (`expiry_date`);
CREATE INDEX `idx_batches_status` ON `batches` (`status`);
CREATE INDEX `idx_stock_moves_type_created` ON `stock_moves` (`move_type`, `created_at`);
CREATE INDEX `idx_orders_status_date` ON `orders` (`status`, `order_date`);
CREATE INDEX `idx_manifests_status_date` ON `manifests` (`status`, `start_at`);
CREATE INDEX `idx_invoices_status_date` ON `invoices` (`status`, `invoice_date`);
CREATE INDEX `idx_receipts_method_date` ON `receipts` (`method`, `received_at`);
CREATE INDEX `idx_attendances_date` ON `attendances` (DATE(`check_in_at`));
CREATE INDEX `idx_tasks_status_due` ON `tasks` (`status`, `due_at`);
CREATE INDEX `idx_notifications_user_read` ON `notifications` (`user_id`, `read_at`);

-- =====================================================
-- DATABASE VIEWS FOR COMMON QUERIES
-- =====================================================

-- Active stock levels view
CREATE VIEW `active_stock_levels` AS
SELECT 
    si.id,
    si.item_type,
    CASE 
        WHEN si.item_type = 'material' THEN m.name
        WHEN si.item_type = 'sku' THEN s.name
    END as item_name,
    CASE 
        WHEN si.item_type = 'material' THEN m.code
        WHEN si.item_type = 'sku' THEN s.code
    END as item_code,
    w.name as warehouse_name,
    b.code as batch_code,
    si.qty,
    CASE 
        WHEN si.item_type = 'material' THEN m.uom
        WHEN si.item_type = 'sku' THEN s.unit
    END as uom
FROM stock_items si
LEFT JOIN materials m ON si.material_id = m.id
LEFT JOIN skus s ON si.sku_id = s.id
LEFT JOIN warehouses w ON si.warehouse_id = w.id
LEFT JOIN batches b ON si.batch_id = b.id
WHERE si.deleted_at IS NULL
AND (si.item_type = 'material' OR (si.item_type = 'sku' AND s.active = 1));

-- Debt ageing report view
CREATE VIEW `debt_ageing_report` AS
SELECT 
    c.name as customer_name,
    c.code as customer_code,
    i.invoice_no,
    i.invoice_date,
    i.due_date,
    d.principal,
    d.balance,
    d.days_overdue,
    CASE 
        WHEN d.days_overdue <= 30 THEN '0-30 days'
        WHEN d.days_overdue <= 60 THEN '31-60 days'
        WHEN d.days_overdue <= 90 THEN '61-90 days'
        ELSE '90+ days'
    END as ageing_bucket
FROM debts d
JOIN customers c ON d.customer_id = c.id
JOIN invoices i ON d.invoice_id = i.id
WHERE d.balance > 0
AND i.status != 'cancelled';

-- Daily production summary view
CREATE VIEW `daily_production_summary` AS
SELECT 
    DATE(pr.run_start) as production_date,
    s.name as sku_name,
    s.code as sku_code,
    SUM(pr.good_qty) as total_good_qty,
    SUM(pr.scrap_qty) as total_scrap_qty,
    SUM(pr.downtime_minutes) as total_downtime_minutes,
    COUNT(pr.id) as number_of_runs
FROM packaging_runs pr
JOIN skus s ON pr.sku_id = s.id
WHERE pr.deleted_at IS NULL
GROUP BY DATE(pr.run_start), s.id, s.name, s.code;

-- QA compliance summary view
CREATE VIEW `qa_compliance_summary` AS
SELECT 
    DATE(wt.recorded_at) as test_date,
    wt.test_type,
    COUNT(*) as total_tests,
    SUM(CASE WHEN wt.status = 'pass' THEN 1 ELSE 0 END) as passed_tests,
    SUM(CASE WHEN wt.status = 'fail' THEN 1 ELSE 0 END) as failed_tests,
    ROUND((SUM(CASE WHEN wt.status = 'pass' THEN 1 ELSE 0 END) / COUNT(*)) * 100, 2) as pass_rate
FROM water_tests wt
WHERE wt.deleted_at IS NULL
GROUP BY DATE(wt.recorded_at), wt.test_type;

-- Sales summary view
CREATE VIEW `sales_summary` AS
SELECT 
    DATE(o.order_date) as sale_date,
    s.name as sku_name,
    s.code as sku_code,
    SUM(oi.qty) as total_qty_sold,
    SUM(oi.qty * oi.unit_price) as total_revenue,
    COUNT(DISTINCT o.id) as number_of_orders
FROM orders o
JOIN order_items oi ON o.id = oi.order_id
JOIN skus s ON oi.sku_id = s.id
WHERE o.status IN ('delivered', 'partially_returned')
AND o.deleted_at IS NULL
GROUP BY DATE(o.order_date), s.id, s.name, s.code;

-- Fleet utilization view
CREATE VIEW `fleet_utilization` AS
SELECT 
    v.reg_no,
    v.make,
    v.model,
    COUNT(m.id) as total_manifests,
    SUM(CASE WHEN m.status = 'closed' THEN 1 ELSE 0 END) as completed_manifests,
    SUM(CASE WHEN m.odometer_in IS NOT NULL THEN m.odometer_in - m.odometer_out ELSE 0 END) as total_distance_km,
    AVG(CASE WHEN m.odometer_in IS NOT NULL THEN m.odometer_in - m.odometer_out ELSE NULL END) as avg_distance_per_manifest
FROM vehicles v
LEFT JOIN manifests m ON v.id = m.vehicle_id
WHERE v.active = 1
AND v.deleted_at IS NULL
GROUP BY v.id, v.reg_no, v.make, v.model;

-- =====================================================
-- STORED PROCEDURES FOR COMPLEX OPERATIONS
-- =====================================================

DELIMITER $$

-- Create batch procedure
CREATE PROCEDURE `CreateBatch`(
    IN p_sku_id CHAR(36),
    IN p_manufacture_date DATE,
    IN p_planned_qty INT,
    IN p_opened_by CHAR(36),
    OUT p_batch_id CHAR(36)
)
BEGIN
    DECLARE v_batch_code VARCHAR(20);
    DECLARE v_sku_code VARCHAR(20);
    DECLARE v_batch_uuid CHAR(36);
    
    SELECT code INTO v_sku_code FROM skus WHERE id = p_sku_id;
    SET v_batch_uuid = UUID();
    SET v_batch_code = CONCAT(v_sku_code, '-', DATE_FORMAT(p_manufacture_date, '%Y%m%d'), '-',
                              LPAD((SELECT COUNT(*) + 1 FROM batches WHERE DATE(created_at) = CURDATE()), 3, '0'));
    
    INSERT INTO batches (id, code, sku_id, manufacture_date, planned_qty, opened_by, created_by)
    VALUES (v_batch_uuid, v_batch_code, p_sku_id, p_manufacture_date, p_planned_qty, p_opened_by, p_opened_by);
    
    SET p_batch_id = v_batch_uuid;
    SELECT p_batch_id as batch_id, v_batch_code as batch_code;
END$$

-- Process stock move procedure
CREATE PROCEDURE `ProcessStockMove`(
    IN p_move_type ENUM('grn','issue','produce','adjust','transfer','return'),
    IN p_item_type ENUM('material','sku'),
    IN p_material_id CHAR(36),
    IN p_sku_id CHAR(36),
    IN p_batch_id CHAR(36),
    IN p_warehouse_from_id CHAR(36),
    IN p_warehouse_to_id CHAR(36),
    IN p_qty DECIMAL(14,3),
    IN p_uom VARCHAR(10),
    IN p_unit_cost DECIMAL(12,4),
    IN p_ref_entity VARCHAR(50),
    IN p_ref_id CHAR(36),
    IN p_moved_by CHAR(36)
)
BEGIN
    DECLARE v_stock_item_id CHAR(36);
    DECLARE v_move_id CHAR(36);
    
    START TRANSACTION;
    
    -- Create stock move record
    SET v_move_id = UUID();
    INSERT INTO stock_moves (id, move_type, item_type, material_id, sku_id, batch_id, 
                            warehouse_from_id, warehouse_to_id, qty, uom, unit_cost, 
                            ref_entity, ref_id, moved_by)
    VALUES (v_move_id, p_move_type, p_item_type, p_material_id, p_sku_id, p_batch_id,
            p_warehouse_from_id, p_warehouse_to_id, p_qty, p_uom, p_unit_cost,
            p_ref_entity, p_ref_id, p_moved_by);
    
    -- Update stock levels
    IF p_warehouse_from_id IS NOT NULL THEN
        -- Reduce from source warehouse
        SELECT id INTO v_stock_item_id
        FROM stock_items 
        WHERE item_type = p_item_type 
        AND material_id = p_material_id 
        AND sku_id = p_sku_id 
        AND warehouse_id = p_warehouse_from_id 
        AND batch_id = p_batch_id
        AND deleted_at IS NULL;
        
        IF v_stock_item_id IS NOT NULL THEN
            UPDATE stock_items 
            SET qty = qty - p_qty, updated_at = NOW()
            WHERE id = v_stock_item_id;
        END IF;
    END IF;
    
    IF p_warehouse_to_id IS NOT NULL THEN
        -- Add to destination warehouse
        SELECT id INTO v_stock_item_id
        FROM stock_items 
        WHERE item_type = p_item_type 
        AND material_id = p_material_id 
        AND sku_id = p_sku_id 
        AND warehouse_id = p_warehouse_to_id 
        AND batch_id = p_batch_id
        AND deleted_at IS NULL;
        
        IF v_stock_item_id IS NOT NULL THEN
            UPDATE stock_items 
            SET qty = qty + p_qty, updated_at = NOW()
            WHERE id = v_stock_item_id;
        ELSE
            INSERT INTO stock_items (id, item_type, material_id, sku_id, warehouse_id, batch_id, qty, created_by)
            VALUES (UUID(), p_item_type, p_material_id, p_sku_id, p_warehouse_to_id, p_batch_id, p_qty, p_moved_by);
        END IF;
    END IF;
    
    COMMIT;
    
    SELECT v_move_id as move_id, 'Stock move processed successfully' as message;
END$$

-- Generate daily reconciliation procedure
CREATE PROCEDURE `GenerateDailyReconciliation`(
    IN p_recon_date DATE,
    IN p_prepared_by CHAR(36)
)
BEGIN
    DECLARE v_recon_id CHAR(36);
    DECLARE v_total_sales DECIMAL(14,2);
    DECLARE v_total_receipts DECIMAL(14,2);
    DECLARE v_variance DECIMAL(14,2);
    
    START TRANSACTION;
    
    -- Create reconciliation record
    SET v_recon_id = UUID();
    INSERT INTO reconciliations (id, recon_date, prepared_by, status)
    VALUES (v_recon_id, p_recon_date, p_prepared_by, 'open');
    
    -- Calculate total sales for the day
    SELECT COALESCE(SUM(i.total), 0) INTO v_total_sales
    FROM invoices i
    WHERE DATE(i.invoice_date) = p_recon_date
    AND i.status IN ('open', 'partial');
    
    -- Calculate total receipts for the day
    SELECT COALESCE(SUM(r.amount), 0) INTO v_total_receipts
    FROM receipts r
    WHERE DATE(r.received_at) = p_recon_date;
    
    -- Calculate variance
    SET v_variance = v_total_receipts - v_total_sales;
    
    -- Insert reconciliation items
    INSERT INTO recon_items (id, recon_id, source, amount, matched, variance)
    VALUES 
    (UUID(), v_recon_id, 'sales', v_total_sales, 1, 0),
    (UUID(), v_recon_id, 'cash', v_total_receipts, 1, v_variance);
    
    COMMIT;
    
    SELECT v_recon_id as recon_id, v_total_sales as total_sales, 
           v_total_receipts as total_receipts, v_variance as variance;
END$$

DELIMITER ;
