-- =====================================================
-- SEED DATA FOR MARA-WATER SYSTEM
-- =====================================================

USE `MARA-WATER`;

START TRANSACTION;

-- =====================================================
-- DEPARTMENTS
-- =====================================================

INSERT INTO `departments` (`id`, `code`, `name`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'QA', 'Quality Assurance', NOW()),
('550e8400-e29b-41d4-a716-446655440002', 'PROD', 'Production', NOW()),
('550e8400-e29b-41d4-a716-446655440003', 'SALES', 'Sales & Marketing', NOW()),
('550e8400-e29b-41d4-a716-446655440004', 'FIN', 'Finance', NOW()),
('550e8400-e29b-41d4-a716-446655440005', 'INV', 'Inventory', NOW()),
('550e8400-e29b-41d4-a716-446655440006', 'FLEET', 'Fleet Management', NOW()),
('550e8400-e29b-41d4-a716-446655440007', 'HR', 'Human Resources', NOW()),
('550e8400-e29b-41d4-a716-446655440008', 'ADMIN', 'Administration', NOW());

-- =====================================================
-- ROLES
-- =====================================================

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

-- =====================================================
-- PERMISSIONS
-- =====================================================

INSERT INTO `permissions` (`id`, `code`, `name`, `module`, `description`, `created_at`) VALUES
-- Admin permissions
('550e8400-e29b-41d4-a716-446655440201', 'admin:*', 'Full Admin Access', 'admin', 'Complete system access', NOW()),
('550e8400-e29b-41d4-a716-446655440202', 'users:*', 'User Management', 'admin', 'Manage users and roles', NOW()),
('550e8400-e29b-41d4-a716-446655440203', 'reports:*', 'All Reports', 'admin', 'Access to all reports', NOW()),

-- QA permissions
('550e8400-e29b-41d4-a716-446655440204', 'qa:*', 'QA Management', 'qa', 'Full QA module access', NOW()),
('550e8400-e29b-41d4-a716-446655440205', 'qa:tests:create', 'Create Water Tests', 'qa', 'Create water quality tests', NOW()),
('550e8400-e29b-41d4-a716-446655440206', 'qa:tests:verify', 'Verify Water Tests', 'qa', 'Verify and approve water tests', NOW()),
('550e8400-e29b-41d4-a716-446655440207', 'qa:batches:*', 'Batch Management', 'qa', 'Manage production batches', NOW()),
('550e8400-e29b-41d4-a716-446655440208', 'qa:calibrations:*', 'Instrument Calibrations', 'qa', 'Manage instrument calibrations', NOW()),

-- Production permissions
('550e8400-e29b-41d4-a716-446655440209', 'production:*', 'Production Management', 'production', 'Full production module access', NOW()),
('550e8400-e29b-41d4-a716-446655440210', 'production:plans:*', 'Production Plans', 'production', 'Manage production plans', NOW()),
('550e8400-e29b-41d4-a716-446655440211', 'production:runs:*', 'Packaging Runs', 'production', 'Manage packaging runs', NOW()),
('550e8400-e29b-41d4-a716-446655440212', 'cleaning:*', 'Cleaning Management', 'production', 'Manage cleaning tasks and logs', NOW()),

-- Inventory permissions
('550e8400-e29b-41d4-a716-446655440213', 'inventory:*', 'Inventory Management', 'inventory', 'Full inventory module access', NOW()),
('550e8400-e29b-41d4-a716-446655440214', 'inventory:stock:*', 'Stock Management', 'inventory', 'Manage stock levels', NOW()),
('550e8400-e29b-41d4-a716-446655440215', 'inventory:moves:*', 'Stock Movements', 'inventory', 'Manage stock movements', NOW()),
('550e8400-e29b-41d4-a716-446655440216', 'inventory:po:*', 'Purchase Orders', 'inventory', 'Manage purchase orders', NOW()),
('550e8400-e29b-41d4-a716-446655440217', 'inventory:grn:*', 'Goods Receipts', 'inventory', 'Manage goods receipts', NOW()),

-- Sales permissions
('550e8400-e29b-41d4-a716-446655440218', 'sales:*', 'Sales Management', 'sales', 'Full sales module access', NOW()),
('550e8400-e29b-41d4-a716-446655440219', 'sales:customers:*', 'Customer Management', 'sales', 'Manage customers', NOW()),
('550e8400-e29b-41d4-a716-446655440220', 'sales:orders:*', 'Order Management', 'sales', 'Manage customer orders', NOW()),
('550e8400-e29b-41d4-a716-446655440221', 'sales:manifests:*', 'Manifest Management', 'sales', 'Manage delivery manifests', NOW()),
('550e8400-e29b-41d4-a716-446655440222', 'sales:deliveries:*', 'Delivery Management', 'sales', 'Manage deliveries', NOW()),

-- Finance permissions
('550e8400-e29b-41d4-a716-446655440223', 'finance:*', 'Finance Management', 'finance', 'Full finance module access', NOW()),
('550e8400-e29b-41d4-a716-446655440224', 'finance:invoices:*', 'Invoice Management', 'finance', 'Manage invoices', NOW()),
('550e8400-e29b-41d4-a716-446655440225', 'finance:receipts:*', 'Receipt Management', 'finance', 'Manage receipts', NOW()),
('550e8400-e29b-41d4-a716-446655440226', 'finance:reconciliation:*', 'Reconciliation', 'finance', 'Manage daily reconciliations', NOW()),
('550e8400-e29b-41d4-a716-446655440227', 'finance:bank:*', 'Bank Management', 'finance', 'Manage bank accounts and statements', NOW()),

-- Fleet permissions
('550e8400-e29b-41d4-a716-446655440228', 'fleet:*', 'Fleet Management', 'fleet', 'Full fleet module access', NOW()),
('550e8400-e29b-41d4-a716-446655440229', 'fleet:vehicles:*', 'Vehicle Management', 'fleet', 'Manage vehicles', NOW()),
('550e8400-e29b-41d4-a716-446655440230', 'fleet:checks:*', 'Vehicle Checks', 'fleet', 'Manage vehicle checks', NOW()),
('550e8400-e29b-41d4-a716-446655440231', 'fleet:services:*', 'Vehicle Services', 'fleet', 'Manage vehicle services', NOW()),

-- HR permissions
('550e8400-e29b-41d4-a716-446655440232', 'hr:*', 'HR Management', 'hr', 'Full HR module access', NOW()),
('550e8400-e29b-41d4-a716-446655440233', 'hr:attendance:*', 'Attendance Management', 'hr', 'Manage attendance', NOW()),
('550e8400-e29b-41d4-a716-446655440234', 'hr:uniform:*', 'Uniform Checks', 'hr', 'Manage uniform checks', NOW()),
('550e8400-e29b-41d4-a716-446655440235', 'hr:safety:*', 'Safety Checks', 'hr', 'Manage safety checks', NOW()),

-- Audit permissions
('550e8400-e29b-41d4-a716-446655440236', 'audit:*', 'Audit Access', 'audit', 'Read-only audit access', NOW());

-- =====================================================
-- ROLE PERMISSIONS (Assign permissions to roles)
-- =====================================================

-- Director (ADMIN) - Full access
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440101', id, NOW()
FROM permissions;

-- QA Officer
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440102', id, NOW()
FROM permissions 
WHERE code IN ('qa:*', 'qa:tests:create', 'qa:batches:*', 'audit:*');

-- RIC/Lab Tech
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440103', id, NOW()
FROM permissions 
WHERE code IN ('qa:tests:verify', 'qa:calibrations:*', 'audit:*');

-- Bottling & Packaging
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440104', id, NOW()
FROM permissions 
WHERE code IN ('production:*', 'production:plans:*', 'production:runs:*', 'cleaning:*', 'audit:*');

-- Sales & Marketing Manager
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440105', id, NOW()
FROM permissions 
WHERE code IN ('sales:*', 'sales:customers:*', 'sales:orders:*', 'sales:manifests:*', 'audit:*');

-- Sales Officer
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440106', id, NOW()
FROM permissions 
WHERE code IN ('sales:orders:*', 'sales:deliveries:*', 'audit:*');

-- Driver
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440107', id, NOW()
FROM permissions 
WHERE code IN ('sales:manifests:*', 'fleet:checks:*', 'audit:*');

-- Finance Officer
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440108', id, NOW()
FROM permissions 
WHERE code IN ('finance:*', 'finance:invoices:*', 'finance:receipts:*', 'finance:reconciliation:*', 'finance:bank:*', 'audit:*');

-- Storekeeper
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440109', id, NOW()
FROM permissions 
WHERE code IN ('inventory:*', 'inventory:stock:*', 'inventory:moves:*', 'inventory:po:*', 'inventory:grn:*', 'audit:*');

-- Auditor
INSERT INTO `role_permissions` (`id`, `role_id`, `permission_id`, `created_at`)
SELECT UUID(), '550e8400-e29b-41d4-a716-446655440110', id, NOW()
FROM permissions 
WHERE code IN ('audit:*');

-- =====================================================
-- WAREHOUSES
-- =====================================================

INSERT INTO `warehouses` (`id`, `code`, `name`, `address`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440301', 'MAIN', 'Main Warehouse', 'Mara Water Factory, Industrial Area, Nairobi', NOW()),
('550e8400-e29b-41d4-a716-446655440302', 'COLD', 'Cold Storage', 'Mara Water Factory, Cold Storage Unit', NOW());

-- =====================================================
-- ROUTES
-- =====================================================

INSERT INTO `routes` (`id`, `name`, `description`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440401', 'Route A - Westlands', 'Westlands, Kilimani, Lavington area', NOW()),
('550e8400-e29b-41d4-a716-446655440402', 'Route B - Eastlands', 'Eastlands, Buruburu, Donholm area', NOW()),
('550e8400-e29b-41d4-a716-446655440403', 'Route C - CBD', 'Central Business District and surrounding areas', NOW()),
('550e8400-e29b-41d4-a716-446655440404', 'Route D - Industrial', 'Industrial Area and nearby commercial zones', NOW());

-- =====================================================
-- VEHICLES
-- =====================================================

INSERT INTO `vehicles` (`id`, `reg_no`, `make`, `model`, `year`, `capacity`, `active`, `insurance_expiry`, `inspection_expiry`, `speed_gov_status`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440501', 'KCA 123A', 'Isuzu', 'NQR75', 2020, 5000.00, 1, '2024-12-31', '2024-06-30', 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440502', 'KCA 456B', 'Isuzu', 'NQR75', 2021, 5000.00, 1, '2024-12-31', '2024-07-15', 'active', NOW()),
('550e8400-e29b-41d4-a716-446655440503', 'KCA 789C', 'Toyota', 'Hilux', 2019, 1000.00, 1, '2024-11-30', '2024-05-20', 'active', NOW());

-- =====================================================
-- SKUs (Finished Products)
-- =====================================================

INSERT INTO `skus` (`id`, `code`, `name`, `size_liters`, `unit`, `expiry_days`, `active`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440601', 'WATER-0.5L', 'Mara Water 0.5L Bottle', 0.50, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440602', 'WATER-1L', 'Mara Water 1L Bottle', 1.00, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440603', 'WATER-1.5L', 'Mara Water 1.5L Bottle', 1.50, 'BOTTLE', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440604', 'WATER-5L', 'Mara Water 5L Container', 5.00, 'CONTAINER', 365, 1, NOW()),
('550e8400-e29b-41d4-a716-446655440605', 'WATER-20L', 'Mara Water 20L Container', 20.00, 'CONTAINER', 365, 1, NOW());

-- =====================================================
-- MATERIALS (Raw Materials)
-- =====================================================

INSERT INTO `materials` (`id`, `code`, `name`, `category`, `uom`, `is_consumable`, `min_level`, `lead_time_days`, `created_at`) VALUES
-- Bottles and Containers
('550e8400-e29b-41d4-a716-446655440701', 'BOTTLE-0.5L', '0.5L PET Bottles', 'Packaging', 'PCS', 1, 10000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440702', 'BOTTLE-1L', '1L PET Bottles', 'Packaging', 'PCS', 1, 8000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440703', 'BOTTLE-1.5L', '1.5L PET Bottles', 'Packaging', 'PCS', 1, 6000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440704', 'CONTAINER-5L', '5L Water Containers', 'Packaging', 'PCS', 1, 500.000, 14, NOW()),
('550e8400-e29b-41d4-a716-446655440705', 'CONTAINER-20L', '20L Water Containers', 'Packaging', 'PCS', 1, 200.000, 14, NOW()),

-- Caps and Seals
('550e8400-e29b-41d4-a716-446655440706', 'CAP-0.5L', '0.5L Bottle Caps', 'Packaging', 'PCS', 1, 12000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440707', 'CAP-1L', '1L Bottle Caps', 'Packaging', 'PCS', 1, 10000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440708', 'CAP-1.5L', '1.5L Bottle Caps', 'Packaging', 'PCS', 1, 8000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440709', 'SEAL-5L', '5L Container Seals', 'Packaging', 'PCS', 1, 600.000, 14, NOW()),
('550e8400-e29b-41d4-a716-446655440710', 'SEAL-20L', '20L Container Seals', 'Packaging', 'PCS', 1, 250.000, 14, NOW()),

-- Labels and Sleeves
('550e8400-e29b-41d4-a716-446655440711', 'LABEL-0.5L', '0.5L Bottle Labels', 'Packaging', 'PCS', 1, 12000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440712', 'LABEL-1L', '1L Bottle Labels', 'Packaging', 'PCS', 1, 10000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440713', 'LABEL-1.5L', '1.5L Bottle Labels', 'Packaging', 'PCS', 1, 8000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440714', 'LABEL-5L', '5L Container Labels', 'Packaging', 'PCS', 1, 600.000, 14, NOW()),
('550e8400-e29b-41d4-a716-446655440715', 'LABEL-20L', '20L Container Labels', 'Packaging', 'PCS', 1, 250.000, 14, NOW()),

-- Chemicals and Treatment
('550e8400-e29b-41d4-a716-446655440716', 'CHLORINE', 'Chlorine Solution', 'Chemicals', 'LITERS', 1, 50.000, 3, NOW()),
('550e8400-e29b-41d4-a716-446655440717', 'FILTER-CARBON', 'Carbon Filters', 'Chemicals', 'PCS', 1, 10.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440718', 'FILTER-SEDIMENT', 'Sediment Filters', 'Chemicals', 'PCS', 1, 15.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440719', 'FILTER-RO', 'Reverse Osmosis Membranes', 'Chemicals', 'PCS', 1, 5.000, 14, NOW()),

-- Shrink Wrap and Cartons
('550e8400-e29b-41d4-a716-446655440720', 'SHRINK-6PACK', '6-Pack Shrink Wrap', 'Packaging', 'PCS', 1, 2000.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440721', 'SHRINK-12PACK', '12-Pack Shrink Wrap', 'Packaging', 'PCS', 1, 1500.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440722', 'CARTON-0.5L', '0.5L Bottle Cartons', 'Packaging', 'PCS', 1, 500.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440723', 'CARTON-1L', '1L Bottle Cartons', 'Packaging', 'PCS', 1, 400.000, 7, NOW()),
('550e8400-e29b-41d4-a716-446655440724', 'CARTON-1.5L', '1.5L Bottle Cartons', 'Packaging', 'PCS', 1, 300.000, 7, NOW());

-- =====================================================
-- BOM (Bill of Materials) - Link SKUs to Materials
-- =====================================================

INSERT INTO `bom_items` (`id`, `sku_id`, `material_id`, `qty_per_unit`, `uom`, `created_at`) VALUES
-- 0.5L Bottle BOM
('550e8400-e29b-41d4-a716-446655440801', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440701', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440802', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440706', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440803', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440711', 1.0000, 'PCS', NOW()),

-- 1L Bottle BOM
('550e8400-e29b-41d4-a716-446655440804', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440702', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440805', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440707', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440806', '550e8400-e29b-41d4-a716-446655440602', '550e8400-e29b-41d4-a716-446655440712', 1.0000, 'PCS', NOW()),

-- 1.5L Bottle BOM
('550e8400-e29b-41d4-a716-446655440807', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440703', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440808', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440708', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440809', '550e8400-e29b-41d4-a716-446655440603', '550e8400-e29b-41d4-a716-446655440713', 1.0000, 'PCS', NOW()),

-- 5L Container BOM
('550e8400-e29b-41d4-a716-446655440810', '550e8400-e29b-41d4-a716-446655440604', '550e8400-e29b-41d4-a716-446655440704', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440811', '550e8400-e29b-41d4-a716-446655440604', '550e8400-e29b-41d4-a716-446655440709', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440812', '550e8400-e29b-41d4-a716-446655440604', '550e8400-e29b-41d4-a716-446655440714', 1.0000, 'PCS', NOW()),

-- 20L Container BOM
('550e8400-e29b-41d4-a716-446655440813', '550e8400-e29b-41d4-a716-446655440605', '550e8400-e29b-41d4-a716-446655440705', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440814', '550e8400-e29b-41d4-a716-446655440605', '550e8400-e29b-41d4-a716-446655440710', 1.0000, 'PCS', NOW()),
('550e8400-e29b-41d4-a716-446655440815', '550e8400-e29b-41d4-a716-446655440605', '550e8400-e29b-41d4-a716-446655440715', 1.0000, 'PCS', NOW());

-- =====================================================
-- QA THRESHOLDS
-- =====================================================

INSERT INTO `qa_thresholds` (`id`, `parameter`, `min_value`, `max_value`, `unit`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655440901', 'ph', 6.500, 8.500, 'pH', NOW()),
('550e8400-e29b-41d4-a716-446655440902', 'tds', 0.000, 500.000, 'ppm', NOW()),
('550e8400-e29b-41d4-a716-446655440903', 'chlorine', 0.200, 2.000, 'ppm', NOW());

-- =====================================================
-- PRICE LISTS
-- =====================================================

INSERT INTO `price_lists` (`id`, `name`, `is_default`, `valid_from`, `valid_to`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655441001', 'Standard Price List 2024', 1, '2024-01-01', '2024-12-31', NOW()),
('550e8400-e29b-41d4-a716-446655441002', 'Wholesale Price List 2024', 0, '2024-01-01', '2024-12-31', NOW()),
('550e8400-e29b-41d4-a716-446655441003', 'Corporate Price List 2024', 0, '2024-01-01', '2024-12-31', NOW());

-- =====================================================
-- PRICE LIST ITEMS
-- =====================================================

INSERT INTO `price_list_items` (`id`, `price_list_id`, `sku_id`, `unit_price`, `currency`, `created_at`) VALUES
-- Standard Prices
('550e8400-e29b-41d4-a716-446655441101', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440601', 25.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441102', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440602', 45.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441103', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440603', 65.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441104', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440604', 200.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441105', '550e8400-e29b-41d4-a716-446655441001', '550e8400-e29b-41d4-a716-446655440605', 750.00, 'KES', NOW()),

-- Wholesale Prices (10% discount)
('550e8400-e29b-41d4-a716-446655441106', '550e8400-e29b-41d4-a716-446655441002', '550e8400-e29b-41d4-a716-446655440601', 22.50, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441107', '550e8400-e29b-41d4-a716-446655441002', '550e8400-e29b-41d4-a716-446655440602', 40.50, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441108', '550e8400-e29b-41d4-a716-446655441002', '550e8400-e29b-41d4-a716-446655440603', 58.50, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441109', '550e8400-e29b-41d4-a716-446655441002', '550e8400-e29b-41d4-a716-446655440604', 180.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441110', '550e8400-e29b-41d4-a716-446655441002', '550e8400-e29b-41d4-a716-446655440605', 675.00, 'KES', NOW()),

-- Corporate Prices (15% discount)
('550e8400-e29b-41d4-a716-446655441111', '550e8400-e29b-41d4-a716-446655441003', '550e8400-e29b-41d4-a716-446655440601', 21.25, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441112', '550e8400-e29b-41d4-a716-446655441003', '550e8400-e29b-41d4-a716-446655440602', 38.25, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441113', '550e8400-e29b-41d4-a716-446655441003', '550e8400-e29b-41d4-a716-446655440603', 55.25, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441114', '550e8400-e29b-41d4-a716-446655441003', '550e8400-e29b-41d4-a716-446655440604', 170.00, 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655441115', '550e8400-e29b-41d4-a716-446655441003', '550e8400-e29b-41d4-a716-446655440605', 637.50, 'KES', NOW());

-- =====================================================
-- CLEANING TASKS
-- =====================================================

INSERT INTO `cleaning_tasks` (`id`, `title`, `frequency`, `checklist_json`, `active`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655442001', 'Daily Production Line Cleaning', 'daily', 
'["Clean bottling line surfaces", "Sanitize filling nozzles", "Clean conveyor belts", "Check foot bath chlorine levels", "Clean production floor"]', 1, NOW()),
('550e8400-e29b-41d4-a716-446655442002', 'Weekly Deep Cleaning', 'weekly', 
'["Deep clean all equipment", "Sanitize storage tanks", "Clean filters", "Inspect and clean pipes", "Deep clean production area"]', 1, NOW()),
('550e8400-e29b-41d4-a716-446655442003', 'Monthly Equipment Maintenance', 'monthly', 
'["Inspect all equipment", "Calibrate instruments", "Check safety equipment", "Review cleaning procedures", "Update maintenance logs"]', 1, NOW());

-- =====================================================
-- SHIFTS
-- =====================================================

INSERT INTO `shifts` (`id`, `name`, `start_time`, `end_time`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655442101', 'Morning Shift', '06:00:00', '14:00:00', NOW()),
('550e8400-e29b-41d4-a716-446655442102', 'Afternoon Shift', '14:00:00', '22:00:00', NOW()),
('550e8400-e29b-41d4-a716-446655442103', 'Night Shift', '22:00:00', '06:00:00', NOW());

-- =====================================================
-- BANK ACCOUNTS
-- =====================================================

INSERT INTO `bank_accounts` (`id`, `bank_name`, `account_no`, `currency`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655442201', 'Equity Bank', '1234567890', 'KES', NOW()),
('550e8400-e29b-41d4-a716-446655442202', 'Cooperative Bank', '0987654321', 'KES', NOW());

-- =====================================================
-- TAXES
-- =====================================================

INSERT INTO `taxes` (`id`, `tax_type`, `rate`, `effective_from`, `effective_to`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655442301', 'VAT', 16.000, '2024-01-01', NULL, NOW()),
('550e8400-e29b-41d4-a716-446655442302', 'Withholding Tax', 5.000, '2024-01-01', NULL, NOW());

-- =====================================================
-- SLAs
-- =====================================================

INSERT INTO `slas` (`id`, `code`, `name`, `target_minutes`, `applies_to_entity`, `applies_to_action`, `created_at`) VALUES
('550e8400-e29b-41d4-a716-446655442401', 'QA_TEST_RESPONSE', 'QA Test Response Time', 30, 'water_tests', 'verify', NOW()),
('550e8400-e29b-41d4-a716-446655442402', 'DELIVERY_TIME', 'Delivery Time', 240, 'orders', 'deliver', NOW()),
('550e8400-e29b-41d4-a716-446655442403', 'RECONCILIATION', 'Daily Reconciliation', 1440, 'reconciliations', 'complete', NOW()),
('550e8400-e29b-41d4-a716-446655442404', 'STOCK_COUNT', 'Stock Count Completion', 480, 'stock_counts', 'complete', NOW());

COMMIT;
