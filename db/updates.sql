
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



