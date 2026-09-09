# MARA-WATER Database Schema Documentation

## 📊 Complete Database Architecture

### Overview

The MARA-WATER database is designed as a comprehensive, normalized schema supporting all aspects of water company operations. It uses UUID primary keys, implements soft deletes, and includes comprehensive audit trails.

**Database Name**: `mara_water`  
**Engine**: MySQL 8.0+  
**Character Set**: utf8mb4  
**Collation**: utf8mb4_unicode_ci

## 🗂️ Table Categories

### 1. Authentication & Authorization

- `users` - System users and authentication
- `roles` - User roles and permissions
- `permissions` - System permissions
- `role_permissions` - Role-permission mapping
- `departments` - Organizational departments
- `user_sessions` - User session management
- `personal_access_tokens` - API authentication tokens

### 2. Master Data

- `skus` - Stock Keeping Units (products)
- `materials` - Raw materials and supplies
- `suppliers` - Vendor information
- `customers` - Customer database
- `vehicles` - Fleet management
- `warehouses` - Storage locations
- `routes` - Delivery routes

### 3. Quality Assurance

- `water_tests` - Water quality testing
- `qa_thresholds` - Quality standards
- `instrument_calibrations` - Equipment calibration
- `batches` - Production batches
- `packaging_checks` - Quality checks during packaging
- `non_conformances` - Quality issues
- `corrective_actions` - Issue resolution

### 4. Production & Operations

- `production_plans` - Daily production planning
- `production_plan_items` - Plan details
- `packaging_runs` - Packaging operations
- `cleaning_tasks` - Maintenance schedules
- `cleaning_logs` - Cleaning records

### 5. Inventory Management

- `stock_items` - Current inventory levels
- `stock_moves` - Inventory transactions
- `purchase_orders` - Procurement orders
- `goods_receipts` - Material receipts
- `stock_counts` - Physical inventory counts

### 6. Sales & Distribution

- `orders` - Customer orders
- `order_items` - Order line items
- `manifests` - Delivery manifests
- `manifest_items` - Manifest details
- `deliveries` - Delivery records
- `returns` - Product returns

### 7. Finance & Accounting

- `invoices` - Customer invoices
- `invoice_items` - Invoice line items
- `payments` - Payment records
- `bank_statements` - Bank transaction imports
- `reconciliations` - Financial reconciliations
- `price_lists` - Pricing structures

### 8. Human Resources

- `attendance` - Employee attendance
- `tasks` - Task assignments
- `notifications` - System notifications

### 9. Media & Files

- `files` - File uploads
- `voice_notes` - Voice recordings
- `audits` - System audit logs

## 📋 Detailed Table Specifications

### Authentication Tables

#### users

```sql
CREATE TABLE users (
    id CHAR(36) PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    avatar_url VARCHAR(500),
    status ENUM('active', 'inactive') DEFAULT 'active',
    last_login_at TIMESTAMP NULL,
    role_id CHAR(36),
    department_id CHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (department_id) REFERENCES departments(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Core user authentication and profile management  
**Key Features**:

- UUID primary key for security
- Soft deletes for data retention
- Audit fields (created_by, updated_by)
- Role and department relationships
- Status tracking for account management

#### roles

```sql
CREATE TABLE roles (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT,
    is_system BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Role-based access control system  
**Key Features**:

- System roles cannot be deleted
- Unique role codes for API access
- Descriptive names for UI display

#### permissions

```sql
CREATE TABLE permissions (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(100) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    module VARCHAR(100) NOT NULL,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Granular permission system  
**Key Features**:

- Module-based organization
- Unique permission codes
- Descriptive names and descriptions

### Business Logic Tables

#### water_tests

```sql
CREATE TABLE water_tests (
    id CHAR(36) PRIMARY KEY,
    test_type ENUM('baseline', 'random', 'retest') NOT NULL,
    recorded_at TIMESTAMP NOT NULL,
    ph DECIMAL(5,2),
    tds DECIMAL(10,2),
    chlorine DECIMAL(10,3),
    unit_notes TEXT,
    location_text VARCHAR(255),
    warehouse_id CHAR(36),
    photo_id CHAR(36),
    recorded_by CHAR(36) NOT NULL,
    ric_verified_by CHAR(36),
    status ENUM('pending', 'pass', 'fail') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (warehouse_id) REFERENCES warehouses(id),
    FOREIGN KEY (photo_id) REFERENCES files(id),
    FOREIGN KEY (recorded_by) REFERENCES users(id),
    FOREIGN KEY (ric_verified_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Water quality testing and monitoring  
**Key Features**:

- Multiple test types (baseline, random, retest)
- Precise decimal measurements
- Photo evidence support
- Verification workflow
- Location tracking

#### batches

```sql
CREATE TABLE batches (
    id CHAR(36) PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    sku_id CHAR(36) NOT NULL,
    manufacture_date DATE NOT NULL,
    expiry_date DATE NOT NULL,
    planned_qty INT NOT NULL,
    status ENUM('open', 'in_progress', 'closed') DEFAULT 'open',
    opened_by CHAR(36),
    closed_by CHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (sku_id) REFERENCES skus(id),
    FOREIGN KEY (opened_by) REFERENCES users(id),
    FOREIGN KEY (closed_by) REFERENCES users(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Production batch management  
**Key Features**:

- Unique batch codes for traceability
- Manufacture and expiry date tracking
- Status workflow management
- User accountability for operations

#### orders

```sql
CREATE TABLE orders (
    id CHAR(36) PRIMARY KEY,
    order_no VARCHAR(50) UNIQUE NOT NULL,
    customer_id CHAR(36) NOT NULL,
    route_id CHAR(36),
    sales_officer_id CHAR(36),
    status ENUM('draft', 'confirmed', 'dispatched', 'delivered', 'partially_returned', 'cancelled') DEFAULT 'draft',
    order_date DATE NOT NULL,
    requested_date DATE,
    price_list_id CHAR(36),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    created_by CHAR(36),
    updated_by CHAR(36),

    FOREIGN KEY (customer_id) REFERENCES customers(id),
    FOREIGN KEY (route_id) REFERENCES routes(id),
    FOREIGN KEY (sales_officer_id) REFERENCES users(id),
    FOREIGN KEY (price_list_id) REFERENCES price_lists(id),
    FOREIGN KEY (created_by) REFERENCES users(id),
    FOREIGN KEY (updated_by) REFERENCES users(id)
);
```

**Purpose**: Customer order management  
**Key Features**:

- Unique order numbers
- Complete status workflow
- Route and sales officer assignment
- Price list integration

## 🔗 Relationships & Constraints

### Foreign Key Relationships

```sql
-- User relationships
users.role_id -> roles.id
users.department_id -> departments.id
users.created_by -> users.id
users.updated_by -> users.id

-- Business relationships
water_tests.warehouse_id -> warehouses.id
water_tests.recorded_by -> users.id
batches.sku_id -> skus.id
orders.customer_id -> customers.id
orders.sales_officer_id -> users.id
```

### Business Rules

```sql
-- Single Director Constraint
-- Only one user can have ADMIN role
CREATE TRIGGER single_director_check
BEFORE INSERT ON users
FOR EACH ROW
BEGIN
    IF NEW.role_id = (SELECT id FROM roles WHERE code = 'ADMIN') THEN
        IF EXISTS (SELECT 1 FROM users WHERE role_id = NEW.role_id AND deleted_at IS NULL) THEN
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only one director allowed';
        END IF;
    END IF;
END;

-- Quality Threshold Validation
-- Water test values must be within acceptable ranges
CREATE TRIGGER water_test_validation
BEFORE INSERT ON water_tests
FOR EACH ROW
BEGIN
    IF NEW.ph < 6.5 OR NEW.ph > 8.5 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'pH value out of acceptable range';
    END IF;

    IF NEW.tds > 500 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'TDS value exceeds maximum';
    END IF;
END;
```

## 📊 Indexes for Performance

### Primary Indexes

```sql
-- Users table
CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_role_id ON users(role_id);
CREATE INDEX idx_users_status ON users(status);
CREATE INDEX idx_users_deleted_at ON users(deleted_at);

-- Water tests
CREATE INDEX idx_water_tests_recorded_at ON water_tests(recorded_at);
CREATE INDEX idx_water_tests_status ON water_tests(status);
CREATE INDEX idx_water_tests_recorded_by ON water_tests(recorded_by);

-- Orders
CREATE INDEX idx_orders_order_date ON orders(order_date);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_customer_id ON orders(customer_id);
CREATE INDEX idx_orders_sales_officer_id ON orders(sales_officer_id);

-- Batches
CREATE INDEX idx_batches_code ON batches(code);
CREATE INDEX idx_batches_manufacture_date ON batches(manufacture_date);
CREATE INDEX idx_batches_status ON batches(status);
```

### Composite Indexes

```sql
-- For reporting queries
CREATE INDEX idx_water_tests_date_status ON water_tests(recorded_at, status);
CREATE INDEX idx_orders_date_status ON orders(order_date, status);
CREATE INDEX idx_batches_date_status ON batches(manufacture_date, status);
```

## 🔄 Data Lifecycle Management

### Soft Deletes

Most tables implement soft deletes using `deleted_at` timestamp:

```sql
-- Example: Soft delete a user
UPDATE users SET deleted_at = CURRENT_TIMESTAMP WHERE id = 'user-uuid';

-- Query to exclude soft-deleted records
SELECT * FROM users WHERE deleted_at IS NULL;
```

### Audit Trail

All tables include audit fields:

```sql
-- Audit fields in every table
created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
created_by CHAR(36),
updated_by CHAR(36)
```

### Data Retention

```sql
-- Archive old records (example)
-- Archive water tests older than 2 years
INSERT INTO water_tests_archive
SELECT * FROM water_tests
WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);

DELETE FROM water_tests
WHERE recorded_at < DATE_SUB(NOW(), INTERVAL 2 YEAR);
```

## 📈 Reporting Views

### Dashboard Views

```sql
-- Daily production summary
CREATE VIEW daily_production_summary AS
SELECT
    DATE(manufacture_date) as production_date,
    COUNT(*) as total_batches,
    SUM(planned_qty) as total_planned_qty,
    COUNT(CASE WHEN status = 'closed' THEN 1 END) as completed_batches
FROM batches
WHERE deleted_at IS NULL
GROUP BY DATE(manufacture_date);

-- Quality compliance summary
CREATE VIEW quality_compliance_summary AS
SELECT
    DATE(recorded_at) as test_date,
    COUNT(*) as total_tests,
    COUNT(CASE WHEN status = 'pass' THEN 1 END) as passed_tests,
    COUNT(CASE WHEN status = 'fail' THEN 1 END) as failed_tests,
    ROUND((COUNT(CASE WHEN status = 'pass' THEN 1 END) / COUNT(*)) * 100, 2) as compliance_rate
FROM water_tests
WHERE deleted_at IS NULL
GROUP BY DATE(recorded_at);
```

## 🔒 Security Considerations

### Data Encryption

```sql
-- Sensitive data encryption (example)
-- Phone numbers and personal information
ALTER TABLE users
ADD COLUMN phone_encrypted VARBINARY(255);

-- Encrypt phone numbers before storage
UPDATE users
SET phone_encrypted = AES_ENCRYPT(phone, 'encryption-key')
WHERE phone IS NOT NULL;
```

### Access Control

```sql
-- Row-level security (example)
-- Users can only see data from their department
CREATE VIEW user_department_data AS
SELECT * FROM water_tests wt
JOIN users u ON wt.recorded_by = u.id
WHERE u.department_id = (SELECT department_id FROM users WHERE id = CURRENT_USER_ID());
```

## 📋 Data Migration & Seeding

### Initial Data Setup

```sql
-- Insert system roles
INSERT INTO roles (id, code, name, description, is_system) VALUES
('550e8400-e29b-41d4-a716-446655440001', 'ADMIN', 'Director / Admin', 'Full system access', TRUE),
('550e8400-e29b-41d4-a716-446655440002', 'QA', 'Quality Assurance', 'QA testing and monitoring', FALSE),
('550e8400-e29b-41d4-a716-446655440003', 'RIC', 'RIC Technician', 'Test validation and calibration', FALSE);

-- Insert departments
INSERT INTO departments (id, code, name) VALUES
('550e8400-e29b-41d4-a716-446655440101', 'ADMIN', 'Administration'),
('550e8400-e29b-41d4-a716-446655440102', 'QA', 'Quality Assurance'),
('550e8400-e29b-41d4-a716-446655440103', 'PROD', 'Production');

-- Insert director user
INSERT INTO users (id, email, password_hash, first_name, last_name, role_id, department_id) VALUES
('550e8400-e29b-41d4-a716-446655440201', 'director@marawater.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Managing', 'Director', '550e8400-e29b-41d4-a716-446655440001', '550e8400-e29b-41d4-a716-446655440101');
```

## 🔧 Maintenance Procedures

### Regular Maintenance

```sql
-- Optimize tables monthly
OPTIMIZE TABLE users, water_tests, batches, orders;

-- Update statistics
ANALYZE TABLE users, water_tests, batches, orders;

-- Check for orphaned records
SELECT 'orphaned water_tests' as issue, COUNT(*) as count
FROM water_tests wt
LEFT JOIN users u ON wt.recorded_by = u.id
WHERE u.id IS NULL AND wt.deleted_at IS NULL
UNION ALL
SELECT 'orphaned batches' as issue, COUNT(*) as count
FROM batches b
LEFT JOIN skus s ON b.sku_id = s.id
WHERE s.id IS NULL AND b.deleted_at IS NULL;
```

### Backup Strategy

```bash
#!/bin/bash
# Daily backup script
DATE=$(date +%Y%m%d_%H%M%S)
mysqldump -u root -p mara_water > backup_${DATE}.sql
gzip backup_${DATE}.sql
# Upload to cloud storage
aws s3 cp backup_${DATE}.sql.gz s3://mara-water-backups/
```

---

**Database Version**: 1.0.0  
**Last Updated**: August 30, 2025  
**Total Tables**: 25+  
**Total Indexes**: 50+  
**Status**: Production Ready ✅
