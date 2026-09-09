# MARA-WATER Database System

## Overview

The MARA-WATER database is a comprehensive management system for a water bottling company, designed to handle all aspects of operations from quality assurance to finance and fleet management. The system enforces a **single Managing Director** requirement and provides role-based access control for all operations.

## Database Structure

### Server Configuration

- **Server**: localhost:3306
- **Database**: MARA-WATER
- **Character Set**: utf8mb4
- **Collation**: utf8mb4_unicode_ci

## Installation Instructions

### Prerequisites

- XAMPP (Apache + MySQL + PHP)
- phpMyAdmin access
- MySQL 5.7+ or MariaDB 10.2+

### Setup Steps

1. **Start XAMPP**

   - Start Apache and MySQL services
   - Open phpMyAdmin (http://localhost/phpmyadmin)

2. **Create Database**

   - Execute `01_database_creation.sql` to create the database and core tables

3. **Create Tables**

   - Execute the following files in order:
     ```
     02_master_data.sql
     03_qa_production.sql
     04_inventory.sql
     05_sales_distribution.sql
     06_finance.sql
     07_hr_fleet_media.sql
     08_constraints_triggers.sql
     09_seed_data.sql
     ```

4. **Verify Installation**
   - Check that all tables are created
   - Verify the single Director constraint is working
   - Confirm seed data is loaded

## Key Features

### 🔐 Single Director Management

- Only **one active Director (ADMIN)** allowed at any time
- Database-level enforcement with triggers
- Director handover process with audit trail
- 2FA support for Director accounts

### 👥 Role-Based Access Control (RBAC)

- **10 System Roles**: Director, QA, RIC, BP, SMM, SO, DRV, FO, STK, AUD
- Granular permissions per module
- Audit logging for all actions
- Session management with refresh tokens

### 🧪 Quality Assurance

- Water quality testing (pH, TDS, Chlorine)
- Batch management with expiry tracking
- Instrument calibration records
- Non-conformance management
- Corrective action tracking

### 🏭 Production Management

- Production planning and scheduling
- Packaging runs with yield tracking
- Cleaning tasks and compliance
- BOM (Bill of Materials) management
- Scrap and downtime tracking

### 📦 Inventory Management

- Raw materials and finished goods
- Stock movements with FIFO costing
- Purchase orders and goods receipts
- Stock counts and variance tracking
- Reorder point management

### 🚚 Sales & Distribution

- Customer management with KYC
- Order processing and tracking
- Delivery manifests with e-signatures
- Route management
- Returns processing

### 💰 Finance & Reconciliation

- Invoice and receipt management
- Bank statement import and matching
- Daily reconciliation with variance tracking
- Debt ageing and recovery
- Expense tracking

### 🚛 Fleet Management

- Vehicle registration and maintenance
- Driver assignments
- Fuel consumption tracking
- NTSA compliance
- Speed governor monitoring

### 👷 HR & Attendance

- Employee attendance with geofencing
- Uniform and safety checks
- Leave management
- Disciplinary actions
- Shift management

### 📱 Media & Voice

- Photo and document storage
- Voice notes with transcription
- Media albums and tagging
- Evidence management

### 📊 Reporting & Analytics

- Real-time dashboards
- KPI tracking
- Export capabilities (PDF, Excel, CSV)
- Scheduled reports

## Database Tables

### Core Tables (50+ tables)

- **Security**: users, roles, permissions, audits
- **Master Data**: skus, materials, customers, suppliers, vehicles
- **QA**: water_tests, batches, calibrations, non_conformances
- **Production**: production_plans, packaging_runs, cleaning_logs
- **Inventory**: stock_items, stock_moves, purchase_orders, goods_receipts
- **Sales**: orders, manifests, deliveries, returns
- **Finance**: invoices, receipts, bank_statements, reconciliations
- **Fleet**: vehicle_checks, services, fuel_logs, insurance
- **HR**: attendances, uniform_checks, leave_requests
- **Media**: files, voice_notes, media_albums

## Business Rules

### Quality Control

- QA thresholds configurable per parameter
- Failed tests block production/dispatch
- Batch expiry enforcement
- Random QA checks during packaging

### Inventory Management

- FIFO costing method
- Negative stock prevention
- Batch tracking for traceability
- Reorder point alerts

### Financial Controls

- Daily reconciliation requirements
- Debt ageing automation
- Bank statement matching
- Variance tracking and alerts

### Fleet Compliance

- Odometer validation (in ≥ out)
- Speed governor monitoring
- NTSA inspection tracking
- Insurance expiry alerts

## Views and Stored Procedures

### Key Views

- `active_stock_levels` - Current stock positions
- `debt_ageing_report` - Customer debt analysis
- `daily_production_summary` - Production metrics
- `qa_compliance_summary` - Quality metrics
- `sales_summary` - Sales performance
- `fleet_utilization` - Vehicle usage

### Stored Procedures

- `CreateBatch()` - Automated batch creation
- `ProcessStockMove()` - Stock movement processing
- `GenerateDailyReconciliation()` - Daily reconciliation

## Security Features

### Authentication

- JWT-based authentication
- Refresh token rotation
- Session management
- 2FA support (TOTP/SMS)

### Authorization

- Role-based permissions
- Module-level access control
- Field-level security
- Audit trail for all changes

### Data Protection

- Soft deletes for data retention
- Encrypted sensitive data
- PII minimization
- Backup and recovery

## API Endpoints

The system provides RESTful APIs for all modules:

### Authentication

- `POST /auth/login` - User login
- `POST /auth/refresh` - Token refresh
- `POST /auth/logout` - User logout

### Core Modules

- `/qa/*` - Quality assurance endpoints
- `/production/*` - Production management
- `/inventory/*` - Inventory operations
- `/sales/*` - Sales and distribution
- `/finance/*` - Financial operations
- `/fleet/*` - Fleet management
- `/hr/*` - Human resources
- `/media/*` - File and voice management

## Monitoring and Alerts

### Automated Alerts

- QA threshold breaches
- Stock level alerts
- Debt overdue notifications
- Fleet compliance issues
- Missing signatures
- Weekend cleaning reminders

### KPIs Tracked

- QA pass rate
- Production efficiency
- Sales performance
- Financial reconciliation
- Fleet utilization
- Attendance compliance

## Backup and Maintenance

### Backup Strategy

- Daily automated backups
- Point-in-time recovery
- Encrypted backup storage
- Off-site backup copies

### Maintenance Tasks

- Regular index optimization
- Audit log cleanup
- Temporary file cleanup
- Performance monitoring

## Troubleshooting

### Common Issues

1. **Single Director Constraint Error**

   - Ensure only one active Director exists
   - Use Director handover process

2. **Foreign Key Constraint Errors**

   - Check referenced records exist
   - Verify data integrity

3. **Performance Issues**
   - Check index usage
   - Monitor query performance
   - Optimize slow queries

### Support

- Check audit logs for error details
- Review system alerts
- Monitor database performance
- Contact system administrator

## Future Enhancements

### Planned Features

- Mobile app development
- Real-time notifications
- Advanced analytics
- Integration with external systems
- Multi-language support
- Advanced reporting

### Scalability

- Horizontal scaling support
- Load balancing
- Caching strategies
- Database sharding

---

**MARA-WATER Database System v1.0**
_Comprehensive water company management solution with single Director control_
