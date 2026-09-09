# MARA-WATER Complete Database Setup

## Overview

This directory contains the complete database setup for the MARA-WATER Water Company Management System. The system is designed for a single Managing Director to handle all end-to-end daily operations across various modules.

## Quick Start

### Option 1: Complete Setup (Recommended)
Use the comprehensive SQL file that includes all modules:

```bash
# Import the complete database setup
mysql -u root -p < mara_water_complete_all_modules.sql
```

Or in phpMyAdmin:
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Click "Import"
3. Select `mara_water_complete_all_modules.sql`
4. Click "Go"

### Option 2: Modular Setup
If you prefer to set up modules individually:

```bash
# Execute files in order
mysql -u root -p < mara_water_complete_setup.sql
mysql -u root -p < 03_qa_production.sql
mysql -u root -p < 04_inventory.sql
mysql -u root -p < 05_sales_distribution.sql
mysql -u root -p < 06_finance.sql
mysql -u root -p < 07_hr_fleet_media.sql
mysql -u root -p < 08_constraints_triggers.sql
```

## Default Login Credentials

After setup, you can login with:

- **Email**: director@marawater.com
- **Password**: Admin@2024

**⚠️ IMPORTANT**: Change the default password immediately after first login!

## Database Structure

### Core Modules

#### 1. Security & Organization
- **Users**: User management with single Director constraint
- **Roles**: 10 system roles (ADMIN, QA, RIC, BP, SMM, SO, DRV, FO, STK, AUD)
- **Permissions**: Granular permissions system
- **Departments**: Organizational structure
- **Audits**: Comprehensive audit logging

#### 2. Master Data
- **Suppliers**: Vendor management
- **Materials**: Raw materials (bottles, caps, labels, chemicals)
- **SKUs**: Finished products (0.5L, 1L, 1.5L, 5L, 20L)
- **BOM**: Bill of Materials for production
- **Warehouses**: Storage locations
- **Routes**: Delivery routes
- **Vehicles**: Fleet management
- **Customers**: Customer database
- **Price Lists**: Pricing structure

#### 3. QA & Production
- **QA Thresholds**: Water quality parameters (pH, TDS, Chlorine)
- **Water Tests**: Daily testing and validation
- **Instrument Calibrations**: Equipment maintenance
- **Batches**: Production batch management
- **Packaging Checks**: Quality control during production
- **Non-Conformances**: Issue tracking and resolution
- **Corrective Actions**: Problem resolution workflow
- **Production Plans**: Daily production scheduling
- **Packaging Runs**: Production execution
- **Cleaning Tasks**: Facility maintenance

#### 4. Inventory Management
- **Stock Items**: Current inventory levels
- **Stock Moves**: All inventory transactions
- **Purchase Orders**: Procurement management
- **Goods Receipts**: Material receiving
- **Stock Counts**: Physical inventory verification

#### 5. Sales & Distribution
- **Orders**: Customer order management
- **Manifests**: Driver delivery manifests
- **Deliveries**: Delivery confirmation
- **Returns**: Product returns processing
- **Signatures**: Electronic signature capture

#### 6. Finance
- **Invoices**: Customer billing
- **Receipts**: Payment collection
- **Bank Accounts**: Financial institution management
- **Bank Statements**: Transaction reconciliation
- **Reconciliations**: Daily cash reconciliation
- **Debts**: Accounts receivable management
- **Expenses**: Cost tracking
- **Taxes**: Tax configuration

#### 7. HR, Fleet & Media
- **Shifts**: Work schedule management
- **Attendance**: Employee time tracking
- **Uniform Checks**: Safety compliance
- **Safety Checks**: Facility safety monitoring
- **Disciplinary Actions**: HR incident management
- **Leave Requests**: Time-off management
- **Vehicle Checks**: Fleet maintenance
- **Services**: Vehicle service records
- **Fuel Logs**: Fuel consumption tracking
- **Driver Assignments**: Fleet allocation
- **Insurance Policies**: Vehicle insurance
- **NTSA Inspections**: Regulatory compliance
- **Speed Governor Logs**: Safety compliance
- **Files**: Document and media storage
- **Voice Notes**: Audio recording with transcription
- **Media Albums**: Photo organization
- **Tasks**: Task management system
- **Notifications**: System notifications
- **SLAs**: Service level agreements

## Business Rules & Constraints

### Single Director Constraint
- Only one active Director (ADMIN) allowed at any time
- Enforced at database level with triggers
- Director handover process with audit trail

### Quality Assurance
- QA thresholds for pH (6.5-8.5), TDS (0-500ppm), Chlorine (0.2-2.0ppm)
- Failed tests automatically create non-conformances
- Batch expiry calculation based on SKU configuration

### Inventory Management
- FIFO costing methodology
- Batch tracking for finished goods
- Expired stock prevention on manifests
- Negative quantity prevention

### Fleet Management
- Odometer validation (in ≥ out)
- Vehicle maintenance scheduling
- Regulatory compliance tracking

### Financial Controls
- Daily reconciliation workflow
- Debt ageing calculation
- Bank statement matching

## Database Views

The system includes several views for common reporting:

1. **active_stock_levels**: Current inventory status
2. **debt_ageing_report**: Accounts receivable analysis
3. **daily_production_summary**: Production metrics
4. **qa_compliance_summary**: Quality control statistics
5. **sales_summary**: Sales performance metrics
6. **fleet_utilization**: Vehicle usage statistics

## Stored Procedures

1. **CreateBatch**: Automated batch creation with code generation
2. **ProcessStockMove**: Inventory transaction processing
3. **GenerateDailyReconciliation**: Automated daily reconciliation

## Performance Optimizations

- Comprehensive indexing strategy
- Optimized foreign key relationships
- Efficient query patterns
- Partitioning considerations for large datasets

## Security Features

- Role-based access control (RBAC)
- Comprehensive audit logging
- Password hashing
- Two-factor authentication support
- API key management
- Session management

## Data Integrity

- Foreign key constraints
- Check constraints
- Unique constraints
- Business rule triggers
- Soft delete implementation
- Audit trail maintenance

## Backup & Recovery

### Recommended Backup Strategy
```sql
-- Daily full backup
mysqldump -u root -p --single-transaction --routines --triggers MARA-WATER > backup_$(date +%Y%m%d).sql

-- Weekly backup with compression
mysqldump -u root -p --single-transaction --routines --triggers MARA-WATER | gzip > backup_$(date +%Y%m%d).sql.gz
```

### Recovery Process
```sql
-- Restore from backup
mysql -u root -p MARA-WATER < backup_20240830.sql
```

## Monitoring & Maintenance

### Key Metrics to Monitor
- Database size and growth
- Query performance
- Index usage
- Lock contention
- Connection count

### Regular Maintenance Tasks
- Analyze table statistics
- Optimize tables
- Clean up old audit logs
- Archive old data
- Update statistics

## Troubleshooting

### Common Issues

1. **Single Director Constraint Error**
   - Ensure only one active Director exists
   - Use Director handover process for role changes

2. **Foreign Key Constraint Errors**
   - Check referenced data exists
   - Verify data integrity before operations

3. **Performance Issues**
   - Check index usage
   - Analyze slow queries
   - Monitor resource usage

### Support

For technical support or questions about the database setup, refer to the main project documentation or contact the development team.

## File Structure

```
database/
├── mara_water_complete_all_modules.sql    # Complete setup (RECOMMENDED)
├── mara_water_complete_setup.sql          # Basic setup only
├── 01_database_creation.sql               # Core tables
├── 02_master_data.sql                     # Master data tables
├── 03_qa_production.sql                   # QA & Production module
├── 04_inventory.sql                       # Inventory module
├── 05_sales_distribution.sql              # Sales & Distribution module
├── 06_finance.sql                         # Finance module
├── 07_hr_fleet_media.sql                  # HR, Fleet & Media module
├── 08_constraints_triggers.sql            # Business rules & optimization
├── 09_seed_data.sql                       # Additional seed data
├── README.md                              # Original documentation
└── README_COMPLETE_SETUP.md               # This file
```

## Next Steps

After database setup:

1. **Change default password** for Director account
2. **Configure application settings** (Laravel/PHP backend)
3. **Set up frontend application** (React/Next.js)
4. **Configure mobile application** (React Native)
5. **Set up file storage** (S3 or local)
6. **Configure email/SMS** for notifications
7. **Set up backup procedures**
8. **Train users** on system workflows

## Version Information

- **Database Version**: 1.0.0
- **Last Updated**: August 30, 2024
- **Compatible With**: MySQL 5.7+, MariaDB 10.2+
- **Character Set**: utf8mb4
- **Collation**: utf8mb4_unicode_ci

---

**Note**: This database setup is designed for the MARA-WATER Water Company Management System. Ensure you have proper backups before making any modifications to production data.
