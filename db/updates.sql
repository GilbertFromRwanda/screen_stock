
-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');


-- ── Decimal quantity support (one decimal place, e.g. 1.5) ─────────────────
-- Quantities across bulk sales, purchases, retail sales and stock adjustments
-- were whole-number-only (int(11)), so entering something like "1.5 packages"
-- silently truncated to "1". Widened every quantity column that feeds those
-- four flows to decimal(10,1). purchase_levels.qty_per_parent is deliberately
-- left as int — it's a packaging ratio (e.g. "12 pieces per box"), not a
-- sellable/purchasable quantity, and is always whole. MODIFY COLUMN can't be
-- guarded with IF EXISTS/IF NOT EXISTS (MariaDB doesn't support it there),
-- matching the precedent in db/stock_db_migration.sql — safe to re-run since
-- widening int -> decimal is idempotent.
ALTER TABLE `stock`           MODIFY COLUMN `quantity`        decimal(10,1) NOT NULL;
ALTER TABLE `purchases`       MODIFY COLUMN `quantity`        decimal(10,1) NOT NULL;
ALTER TABLE `sales_bulk`      MODIFY COLUMN `quantity`        decimal(10,1) NOT NULL;
ALTER TABLE `sales_retail`    MODIFY COLUMN `pieces_sold`     decimal(10,1) NOT NULL;
ALTER TABLE `retail_stock`    MODIFY COLUMN `pieces_quantity` decimal(10,1) NOT NULL DEFAULT 0;
ALTER TABLE `refunds`         MODIFY COLUMN `quantity`        decimal(10,1) NOT NULL DEFAULT 0;
ALTER TABLE `stock_movements` MODIFY COLUMN `pieces_moved`    decimal(10,1) NOT NULL;


-- ── company_settings: per-company feature toggles (Orders module, exchange
-- rate box on new-purchase.php, order notifications, external sale, server IP
-- display) — one row per company, created on first save from settings.php.
-- Missing row == all features on (see getCompanySettings() defaults in
-- functions.php).
CREATE TABLE IF NOT EXISTS `company_settings` (
  `company_id` int(11) NOT NULL,
  `enable_orders` tinyint(1) NOT NULL DEFAULT 1,
  `enable_exchange_rate` tinyint(1) NOT NULL DEFAULT 1,
  `enable_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `enable_external_sale` tinyint(1) NOT NULL DEFAULT 1,
  `enable_ip` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Installs that already had company_settings from before these columns existed.
ALTER TABLE `company_settings` ADD COLUMN IF NOT EXISTS `enable_external_sale` tinyint(1) NOT NULL DEFAULT 1 AFTER `enable_notifications`;
ALTER TABLE `company_settings` ADD COLUMN IF NOT EXISTS `enable_ip` tinyint(1) NOT NULL DEFAULT 1 AFTER `enable_external_sale`;



