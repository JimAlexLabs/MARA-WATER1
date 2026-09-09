USE mara_water;

-- Insert SKUs (Products)
INSERT IGNORE INTO `skus` (`id`, `code`, `name`, `size_liters`, `unit`, `expiry_days`, `active`) VALUES
('550e8400-e29b-41d4-a716-446655440301', 'SKU001', 'Pure Water 0.5L', 0.50, 'bottle', 30, 1),
('550e8400-e29b-41d4-a716-446655440302', 'SKU002', 'Pure Water 1L', 1.00, 'bottle', 30, 1),
('550e8400-e29b-41d4-a716-446655440303', 'SKU003', 'Pure Water 1.5L', 1.50, 'bottle', 30, 1);

-- Insert Warehouses
INSERT IGNORE INTO `warehouses` (`id`, `code`, `name`, `address`) VALUES
('550e8400-e29b-41d4-a716-446655440401', 'WH001', 'Main Factory', 'Nairobi, Kenya'),
('550e8400-e29b-41d4-a716-446655440402', 'WH002', 'Distribution Center', 'Mombasa, Kenya');

-- Insert Customers
INSERT IGNORE INTO `customers` (`id`, `code`, `name`, `type`, `phone`, `email`, `address`) VALUES
('550e8400-e29b-41d4-a716-446655440501', 'CUST001', 'ABC Supermarket', 'retail', '+254700000001', 'abc@example.com', 'Nairobi CBD'),
('550e8400-e29b-41d4-a716-446655440502', 'CUST002', 'XYZ Hotel', 'corporate', '+254700000002', 'xyz@example.com', 'Westlands, Nairobi'),
('550e8400-e29b-41d4-a716-446655440503', 'CUST003', 'Local Shop', 'retail', '+254700000003', 'local@example.com', 'Eastleigh, Nairobi');

-- Insert Vehicles
INSERT IGNORE INTO `vehicles` (`id`, `reg_no`, `make`, `model`, `year`, `capacity`, `active`) VALUES
('550e8400-e29b-41d4-a716-446655440601', 'KCA 123A', 'Toyota', 'Hilux', 2020, 1000, 1),
('550e8400-e29b-41d4-a716-446655440602', 'KCB 456B', 'Isuzu', 'NPR', 2019, 2000, 1);

-- Insert Water Tests (QA Data)
INSERT IGNORE INTO `water_tests` (`id`, `test_type`, `recorded_at`, `ph`, `tds`, `chlorine`, `status`, `recorded_by`) VALUES
('550e8400-e29b-41d4-a716-446655440701', 'baseline', NOW(), 7.2, 150.5, 0.5, 'pass', '550e8400-e29b-41d4-a716-446655440201'),
('550e8400-e29b-41d4-a716-446655440702', 'random', NOW(), 7.1, 148.2, 0.4, 'pass', '550e8400-e29b-41d4-a716-446655440201');

-- Insert Batches
INSERT IGNORE INTO `batches` (`id`, `code`, `sku_id`, `manufacture_date`, `expiry_date`, `planned_qty`, `status`, `opened_by`) VALUES
('550e8400-e29b-41d4-a716-446655440801', 'BATCH001', '550e8400-e29b-41d4-a716-446655440301', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 1000, 'open', '550e8400-e29b-41d4-a716-446655440201'),
('550e8400-e29b-41d4-a716-446655440802', 'BATCH002', '550e8400-e29b-41d4-a716-446655440302', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 30 DAY), 500, 'open', '550e8400-e29b-41d4-a716-446655440201');

-- Insert Orders
INSERT IGNORE INTO `orders` (`id`, `order_no`, `customer_id`, `status`, `order_date`) VALUES
('550e8400-e29b-41d4-a716-446655440901', 'ORD001', '550e8400-e29b-41d4-a716-446655440501', 'confirmed', CURDATE()),
('550e8400-e29b-41d4-a716-446655440902', 'ORD002', '550e8400-e29b-41d4-a716-446655440502', 'dispatched', CURDATE());

-- Insert Manifests
INSERT IGNORE INTO `manifests` (`id`, `manifest_no`, `vehicle_id`, `driver_id`, `sales_officer_id`, `odometer_out`, `status`, `created_by`) VALUES
('550e8400-e29b-41d4-a716-446655441001', 'MAN001', '550e8400-e29b-41d4-a716-446655440601', '550e8400-e29b-41d4-a716-446655440201', '550e8400-e29b-41d4-a716-446655440201', 50000, 'open', '550e8400-e29b-41d4-a716-446655440201');

-- Insert Stock Items
INSERT IGNORE INTO `stock_items` (`id`, `item_type`, `sku_id`, `warehouse_id`, `qty`) VALUES
('550e8400-e29b-41d4-a716-446655441101', 'sku', '550e8400-e29b-41d4-a716-446655440301', '550e8400-e29b-41d4-a716-446655440401', 500),
('550e8400-e29b-41d4-a716-446655441102', 'sku', '550e8400-e29b-41d4-a716-446655440302', '550e8400-e29b-41d4-a716-446655440401', 300);

-- Insert Tasks
INSERT IGNORE INTO `tasks` (`id`, `title`, `description`, `assigned_to`, `due_date`, `status`, `priority`) VALUES
('550e8400-e29b-41d4-a716-446655441201', 'Daily QA Check', 'Perform daily water quality tests', '550e8400-e29b-41d4-a716-446655440201', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'todo', 'high'),
('550e8400-e29b-41d4-a716-446655441202', 'Vehicle Maintenance', 'Schedule vehicle inspection', '550e8400-e29b-41d4-a716-446655440201', DATE_ADD(CURDATE(), INTERVAL 7 DAY), 'todo', 'medium');

-- Insert Notifications
INSERT IGNORE INTO `notifications` (`id`, `user_id`, `title`, `message`, `type`) VALUES
('550e8400-e29b-41d4-a716-446655441301', '550e8400-e29b-41d4-a716-446655440201', 'System Welcome', 'Welcome to MARA-WATER Management System', 'info'),
('550e8400-e29b-41d4-a716-446655441302', '550e8400-e29b-41d4-a716-446655440201', 'Daily Reminder', 'Please perform daily QA checks', 'reminder');
