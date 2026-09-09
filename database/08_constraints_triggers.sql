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
