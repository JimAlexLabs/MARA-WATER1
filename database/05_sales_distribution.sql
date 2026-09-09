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
