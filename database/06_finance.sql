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
