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
