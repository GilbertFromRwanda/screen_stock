
-- ============================================================
-- Screen Stock — incremental DB updates
-- Run these once on any existing installation.
-- All statements use IF NOT EXISTS / IF EXISTS so they are
-- safe to re-run without errors.
-- ============================================================


-- ── purchase_levels ──────────────────────────────────────────────────────────
-- Stores the multi-level packaging chain for every purchase.
-- Created inline in new-purchase.php; listed here for reference / fresh installs.
CREATE TABLE IF NOT EXISTS `purchase_levels` (
    `id`             INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `purchase_id`    INT          NOT NULL,
    `level_order`    TINYINT      NOT NULL,
    `level_name`     VARCHAR(100) NOT NULL,
    `qty_per_parent` INT          NOT NULL DEFAULT 1,
    `selling_price`  DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    INDEX `idx_purchase_id` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;




-- ── sales_bulk.level_divisor ─────────────────────────────────────────────────
-- Tracks which packaging level was sold so profit reports can correctly
-- convert sub-level quantities back to top-level package cost.
-- DEFAULT 1 = full-package sale (backward compatible with old rows).
ALTER TABLE `sales_bulk` ADD COLUMN IF NOT EXISTS `level_divisor` INT NOT NULL DEFAULT 1 AFTER `quantity`;


-- ── companies ────────────────────────────────────────────────────────────────
-- Each company is an independent tenant. Users belong to exactly one company.
-- Superadmin users have company_id = NULL and can manage all companies.
CREATE TABLE IF NOT EXISTS `companies` (
    `id`         INT          NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(255) NOT NULL,
    `email`      VARCHAR(255) DEFAULT NULL,
    `phone`      VARCHAR(50)  DEFAULT NULL,
    `address`    TEXT         DEFAULT NULL,
    `status`     ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── users.company_id ─────────────────────────────────────────────────────────
-- NULL = superadmin (no company); otherwise references companies.id
ALTER TABLE `users` ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;


-- ── users.role — add superadmin ──────────────────────────────────────────────
ALTER TABLE `users` MODIFY COLUMN `role` ENUM('superadmin','admin','manager','user') NOT NULL DEFAULT 'user';


-- ── company_id on all tenant-scoped tables ───────────────────────────────────
-- products is intentionally excluded — it is shared across all companies.
ALTER TABLE `stock`           ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `retail_stock`    ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `purchases`       ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `purchase_levels` ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_bulk`      ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_retail`    ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_external`  ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `loans`           ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `loan_clients`    ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `loan_payments`   ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `expenses`        ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `suppliers`       ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `product_owners`  ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `refunds`         ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `boaster`         ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `consumption`     ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `stock_movements` ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;
ALTER TABLE `weekly_revenue`  ADD COLUMN IF NOT EXISTS `company_id` INT DEFAULT NULL AFTER `id`;


-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi4', 'Super Admin', 'superadmin', 'active');
