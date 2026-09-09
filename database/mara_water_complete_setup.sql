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
