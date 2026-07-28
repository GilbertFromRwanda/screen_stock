

-- ── cart_drafts table ────────────────────────────────────────────────────────
-- Server-side backup of in-progress carts saved via the "Save Draft" button on
-- sale_bulk/sale_retail/sale_external.php. IndexedDB (js/cart-drafts.js) is the
-- primary store — this table just lets a draft survive a browser/device switch.
CREATE TABLE IF NOT EXISTS `cart_drafts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `draft_ref` varchar(64) NOT NULL,
  `sale_type` varchar(10) NOT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `draft_json` LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(draft_json)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_cart_drafts_ref` (`draft_ref`),
  KEY `idx_cart_drafts_company_type` (`company_id`, `sale_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── loan_settings table ─────────────────────────────────────────────────────
-- Per-company admin-configurable rules for the loan eligibility feature: how
-- long a borrower has to repay (payment_period_days), how much their next
-- eligible amount grows by after a 100%-on-time payoff (growth_rate_percent),
-- and the minimum they're still offered if they pay nothing at all
-- (zero_payment_floor). One row per company_id (NULL = single-tenant/no scope).
CREATE TABLE IF NOT EXISTS `loan_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `payment_period_days` int(11) NOT NULL DEFAULT 7,
  `growth_rate_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `zero_payment_floor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_loan_settings_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── loans.due_date column ────────────────────────────────────────────────────
-- Frozen at loan-creation time from loan_settings.payment_period_days (so
-- changing the setting later never retroactively shifts past loans' due
-- dates). Used to judge whether a repayment happened "during the period".
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `due_date` date DEFAULT NULL AFTER `loan_date`;


-- ── loan_clients per-borrower loan-setting overrides ────────────────────────
-- NULL (the default) means "inherit the general/company loan_settings row".
-- A non-NULL value here overrides that field for this one borrower only —
-- e.g. a trusted client can get a longer payment period or a higher
-- zero-payment floor than everyone else. See getEffectiveLoanSettings().
ALTER TABLE `loan_clients`
  ADD COLUMN IF NOT EXISTS `payment_period_days` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `growth_rate_percent` decimal(5,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `zero_payment_floor` decimal(10,2) DEFAULT NULL;


-- ── Missing company_id indexes ──────────────────────────────────────────────
-- purchases, stock_movements and loans are filtered by company_id (via
-- cidSql()/cidAnd()) on every list/report query but had no index covering
-- that column, unlike sales_bulk/sales_retail/sales_external which already
-- get it for free from their (company_id, client_ref) unique key.
ALTER TABLE `purchases` ADD INDEX IF NOT EXISTS `idx_purchases_company_date` (`company_id`, `purchase_date`);
ALTER TABLE `stock_movements` ADD INDEX IF NOT EXISTS `idx_stock_movements_company_date` (`company_id`, `moved_date`);
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_loans_company_date` (`company_id`, `loan_date`);


-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');




