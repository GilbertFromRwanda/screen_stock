-- ============================================================================
-- Migration: bring `stock_db` (olive2_db, single-tenant, HAS LIVE DATA) up to
-- the schema currently in screen_db.sql (multi-company, cart drafts,
-- categories, permissions, refined sales/loan tracking, etc).
--
-- ⚠ BACK UP stock_db BEFORE RUNNING THIS. Some statements are structural
-- (rename/widen/narrow columns) and are not trivially reversible.
--
-- Written against MariaDB 10.4 — uses `IF NOT EXISTS` / `IF EXISTS` clauses
-- on ADD/CHANGE COLUMN, ADD INDEX and CREATE TABLE so the script is safe to
-- re-run if it's interrupted partway through. Statements that MariaDB does
-- NOT support an IF-guard for (MODIFY COLUMN, DROP FOREIGN KEY, backfill
-- UPDATEs) are called out — re-running those after a full success is a
-- harmless no-op or will visibly error, never silently corrupt data.
--
-- Run top to bottom; the ordering matters (new tables → new columns →
-- backfill → constraints/indexes).
-- ============================================================================


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 1: New tables that don't depend on company_id backfill
-- ────────────────────────────────────────────────────────────────────────────

-- `companies` — root of the multi-company model every company_id column
-- below points at (no FK enforced, same as in screen_db).
CREATE TABLE IF NOT EXISTS `companies` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Seed the single existing tenant so every row backfilled to company_id = 1
-- (Section 3) actually points at something.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- `categories` — normalized product categories. products.category stays as
-- a denormalized text column for search/filters; category_id (Section 4) is
-- the join key. Not backfilled automatically — do it later via categories.php
-- once real category names are decided, to avoid mis-matching on typos/case.
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `cache_meta` (
  `store_name` varchar(50) NOT NULL,
  `company_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`store_name`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


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


CREATE TABLE IF NOT EXISTS `currency_rates` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `usd_rate` decimal(10,4) NOT NULL DEFAULT 1300.0000,
  `foreign_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `foreign_name` varchar(10) NOT NULL DEFAULT 'USD',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_cr_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `loan_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `payment_period_days` int(11) NOT NULL DEFAULT 7,
  `growth_rate_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `zero_payment_floor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  UNIQUE KEY `uk_loan_settings_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `notifications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `order_number` varchar(20) DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL,
  KEY `idx_notif_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `order_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `momo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `recorded_by` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_op_order` (`order_id`),
  KEY `idx_op_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `user_company_access` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uq_uca_user_company` (`user_id`, `company_id`),
  KEY `idx_uca_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


CREATE TABLE IF NOT EXISTS `user_permissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  UNIQUE KEY `uq_user_module` (`user_id`, `module`),
  KEY `idx_up_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- `client_payments` depends on `loan_clients`, which already exists in
-- stock_db — safe to create now.
CREATE TABLE IF NOT EXISTS `client_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `company_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  KEY `idx_cp_client` (`client_id`),
  KEY `idx_cp_company` (`company_id`),
  KEY `idx_cp_date` (`payment_date`),
  CONSTRAINT `client_payments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `loan_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 2: Add `company_id` to every existing table that needs it
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE `audit_log`        ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `boaster`          ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `consumption`      ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `expenses`         ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `loans`            ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `loan_clients`     ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `loan_payments`    ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `orders`           ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `order_owners`     ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `product_owners`   ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `purchases`        ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `purchase_levels`  ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `refunds`          ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `retail_stock`     ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_bulk`       ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_external`   ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `sales_retail`     ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `stock`            ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `stock_movements`  ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `stock_value_cache` ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `suppliers`        ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `users`            ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;
ALTER TABLE `weekly_revenue`   ADD COLUMN IF NOT EXISTS `company_id` int(11) DEFAULT NULL AFTER `id`;


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 3: Backfill company_id = 1 for all pre-existing rows
-- (per your choice — makes cidSql()/cidAnd() company-scoped queries work
-- immediately instead of every row being an orphan NULL)
-- NOT idempotency-guarded (plain UPDATE) — re-running is harmless, the
-- WHERE clause just matches nothing the second time.
-- ────────────────────────────────────────────────────────────────────────────

UPDATE `audit_log`         SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `boaster`           SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `consumption`       SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `expenses`          SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `loans`             SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `loan_clients`      SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `loan_payments`     SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `orders`            SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `order_owners`      SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `product_owners`    SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `purchases`         SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `purchase_levels`   SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `refunds`           SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `retail_stock`      SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `sales_bulk`        SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `sales_external`    SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `sales_retail`      SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `stock`             SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `stock_movements`   SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `stock_value_cache` SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `suppliers`         SET `company_id` = 1 WHERE `company_id` IS NULL;
UPDATE `weekly_revenue`    SET `company_id` = 1 WHERE `company_id` IS NULL;

-- Users are NOT auto-backfilled to company 1: in the multi-company model
-- company_id = NULL marks superadmin (no tenant scope). Decide per-user:
--   UPDATE `users` SET `company_id` = 1 WHERE `role` <> 'superadmin' AND `company_id` IS NULL;
-- left commented out deliberately — run it once you've reviewed who should
-- actually be scoped vs. promoted to superadmin.


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 4: audit_log rework (rename + keep extra legacy columns)
-- ────────────────────────────────────────────────────────────────────────────
-- screen_db's audit_log uses table_name/old_values/new_values and a
-- timestamp created_at. stock_db's username/description columns are kept
-- (unused by new code, but nothing is deleted).

ALTER TABLE `audit_log` CHANGE COLUMN IF EXISTS `module` `table_name` varchar(100) DEFAULT NULL;
ALTER TABLE `audit_log` CHANGE COLUMN IF EXISTS `old_value` `old_values` text DEFAULT NULL;
ALTER TABLE `audit_log` CHANGE COLUMN IF EXISTS `new_value` `new_values` text DEFAULT NULL;
ALTER TABLE `audit_log` MODIFY COLUMN `created_at` timestamp NOT NULL DEFAULT current_timestamp();
ALTER TABLE `audit_log` ADD INDEX IF NOT EXISTS `idx_audit_company` (`company_id`);
ALTER TABLE `audit_log` ADD INDEX IF NOT EXISTS `idx_audit_user` (`user_id`);
-- idx_module (existing) and idx_created (existing) already cover table_name
-- and created_at respectively — the CHANGE COLUMN rename carries them along,
-- it just kept the old index names. Not re-added under new names to avoid
-- a redundant duplicate index on the same columns.


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 5: Per-table column additions/widenings
-- ────────────────────────────────────────────────────────────────────────────

-- consumption: track bulk vs retail source
ALTER TABLE `consumption` ADD COLUMN IF NOT EXISTS `source` varchar(10) NOT NULL DEFAULT 'retail' AFTER `qty`;

-- loans: cart snapshot + frozen due_date. sale_type/unit_price are dropped
-- from screen_db's loans table but are left in place here (unused, no data
-- lost) rather than deleted.
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `cart` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart`)) AFTER `product_name`;
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `due_date` date DEFAULT NULL AFTER `loan_date`;
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_loan_date` (`loan_date`);
ALTER TABLE `loans` ADD INDEX IF NOT EXISTS `idx_client` (`client`);

-- loan_clients: per-borrower overrides for loan_settings
ALTER TABLE `loan_clients`
  ADD COLUMN IF NOT EXISTS `payment_period_days` int(11) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `growth_rate_percent` decimal(5,2) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `zero_payment_floor` decimal(10,2) DEFAULT NULL;

-- orders: richer lifecycle/delivery/link-sharing fields + wider status enum
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `total_prepaid`,
  ADD COLUMN IF NOT EXISTS `status_before_close` varchar(20) DEFAULT NULL AFTER `status`,
  ADD COLUMN IF NOT EXISTS `delivery_status` enum('placed','packed','ready','delivered','received') NOT NULL DEFAULT 'placed' AFTER `status_before_close`,
  ADD COLUMN IF NOT EXISTS `show_prices` tinyint(1) NOT NULL DEFAULT 1 AFTER `delivery_status`,
  ADD COLUMN IF NOT EXISTS `link_code` char(5) DEFAULT NULL AFTER `show_prices`,
  ADD COLUMN IF NOT EXISTS `link_expires_at` datetime DEFAULT NULL AFTER `link_code`,
  ADD COLUMN IF NOT EXISTS `is_reusable` tinyint(1) NOT NULL DEFAULT 0 AFTER `link_expires_at`,
  ADD COLUMN IF NOT EXISTS `source_order_id` int(11) DEFAULT NULL AFTER `is_reusable`,
  ADD COLUMN IF NOT EXISTS `cancel_reason` text DEFAULT NULL AFTER `source_order_id`,
  ADD COLUMN IF NOT EXISTS `in_charge_id` int(11) DEFAULT NULL AFTER `created_by`,
  ADD COLUMN IF NOT EXISTS `cancelled_by` int(11) DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `orders` MODIFY COLUMN `status`
  enum('new','open','pending','processing','completed','rejected','approved','cancelled','closed')
  NOT NULL DEFAULT 'pending';

ALTER TABLE `orders`
  ADD INDEX IF NOT EXISTS `idx_orders_company` (`company_id`),
  ADD INDEX IF NOT EXISTS `idx_orders_status_date` (`status`, `created_at`),
  ADD INDEX IF NOT EXISTS `idx_orders_order_owner_id` (`order_owner_id`),
  ADD INDEX IF NOT EXISTS `idx_orders_created_by` (`created_by`),
  ADD INDEX IF NOT EXISTS `idx_orders_approved_by` (`approved_by`),
  ADD INDEX IF NOT EXISTS `idx_orders_order_number` (`order_number`),
  ADD INDEX IF NOT EXISTS `idx_orders_link_code` (`link_code`),
  ADD INDEX IF NOT EXISTS `idx_orders_source_order` (`source_order_id`),
  ADD INDEX IF NOT EXISTS `idx_orders_in_charge` (`in_charge_id`);

-- order_items: staff/customer-added custom lines + fulfillment status;
-- product_id becomes optional (custom lines have no product row)
ALTER TABLE `order_items`
  ADD COLUMN IF NOT EXISTS `custom_name` varchar(150) DEFAULT NULL AFTER `product_id`,
  ADD COLUMN IF NOT EXISTS `stock_source` enum('wh','rt','custom') NOT NULL DEFAULT 'wh' AFTER `custom_name`,
  ADD COLUMN IF NOT EXISTS `status` enum('pending','fulfilled','out_of_stock') NOT NULL DEFAULT 'pending' AFTER `stock_source`,
  ADD COLUMN IF NOT EXISTS `source` enum('staff','customer') NOT NULL DEFAULT 'staff' AFTER `item_total`,
  ADD COLUMN IF NOT EXISTS `added_by` int(11) DEFAULT NULL AFTER `source`;
ALTER TABLE `order_items` MODIFY COLUMN `product_id` int(11) DEFAULT NULL;

-- products: category_id join key + generated full-text search column
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `category_id` int(11) DEFAULT NULL AFTER `category`;
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `search_text` varchar(160)
  GENERATED ALWAYS AS (concat(`name`, ' ', coalesce(`category`, ''))) STORED AFTER `category_id`;
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_category_id` (`category_id`);
ALTER TABLE `products` ADD FULLTEXT INDEX IF NOT EXISTS `ftx_products_search_text` (`search_text`);
-- Not backfilled: category_id is left NULL for existing rows. Use
-- categories.php (rename/merge) to assign real categories once decided —
-- text-matching products.category to categories.name here risked silent
-- mismatches from typos/casing.

-- refunds: widen amounts + product_name to match screen_db
ALTER TABLE `refunds` MODIFY COLUMN `product_name` varchar(255) DEFAULT NULL;
ALTER TABLE `refunds` MODIFY COLUMN `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `refunds` MODIFY COLUMN `loss_amount` decimal(12,2) DEFAULT NULL;

-- sales_bulk / sales_retail: cost tracking, purchase link, client ref (used
-- for idempotent resubmission), payment method label, and cart snapshot
ALTER TABLE `sales_bulk`
  ADD COLUMN IF NOT EXISTS `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN IF NOT EXISTS `purchase_id` int(11) DEFAULT NULL AFTER `cost_total`,
  ADD COLUMN IF NOT EXISTS `client_ref` varchar(64) DEFAULT NULL AFTER `sold_by`,
  ADD COLUMN IF NOT EXISTS `payment_method` varchar(20) DEFAULT 'Cash' AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`));
ALTER TABLE `sales_bulk` ADD INDEX IF NOT EXISTS `idx_sb_purchase_id` (`purchase_id`);
ALTER TABLE `sales_bulk` ADD UNIQUE KEY IF NOT EXISTS `uq_sb_client_ref` (`company_id`, `client_ref`);

ALTER TABLE `sales_retail`
  ADD COLUMN IF NOT EXISTS `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`,
  ADD COLUMN IF NOT EXISTS `purchase_id` int(11) DEFAULT NULL AFTER `cost_total`,
  ADD COLUMN IF NOT EXISTS `client_ref` varchar(64) DEFAULT NULL AFTER `sold_by`,
  ADD COLUMN IF NOT EXISTS `payment_method` varchar(20) DEFAULT 'Cash' AFTER `created_at`,
  ADD COLUMN IF NOT EXISTS `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`));
ALTER TABLE `sales_retail` ADD INDEX IF NOT EXISTS `idx_sr_purchase_id` (`purchase_id`);
ALTER TABLE `sales_retail` ADD UNIQUE KEY IF NOT EXISTS `uq_sr_client_ref` (`company_id`, `client_ref`);

-- sales_external: client ref, cart snapshot, widened amounts/name
ALTER TABLE `sales_external`
  ADD COLUMN IF NOT EXISTS `client_ref` varchar(64) DEFAULT NULL AFTER `sold_by`,
  ADD COLUMN IF NOT EXISTS `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`));
ALTER TABLE `sales_external` MODIFY COLUMN `product_name` varchar(255) NOT NULL;
ALTER TABLE `sales_external` MODIFY COLUMN `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `sales_external` MODIFY COLUMN `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `sales_external` ADD UNIQUE KEY IF NOT EXISTS `uq_se_client_ref` (`company_id`, `client_ref`);

-- stock_value_cache: wider precision + composite unique key
ALTER TABLE `stock_value_cache` MODIFY COLUMN `cost_wh` decimal(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `stock_value_cache` MODIFY COLUMN `cost_rt` decimal(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `stock_value_cache` MODIFY COLUMN `sell_wh` decimal(15,2) NOT NULL DEFAULT 0.00;
ALTER TABLE `stock_value_cache` MODIFY COLUMN `sell_rt` decimal(15,2) NOT NULL DEFAULT 0.00;
-- old unique key was (product_id) alone; screen_db's is (product_id, company_id).
-- Safe now that company_id is backfilled to 1 for every row (Section 3 ran first).
ALTER TABLE `stock_value_cache` DROP KEY IF EXISTS `uq_product`;
ALTER TABLE `stock_value_cache` ADD UNIQUE KEY IF NOT EXISTS `uq_product` (`product_id`, `company_id`);
ALTER TABLE `stock_value_cache` ADD INDEX IF NOT EXISTS `idx_svc_company` (`company_id`);
ALTER TABLE `stock_value_cache` ADD INDEX IF NOT EXISTS `idx_svc_product` (`product_id`);

-- users: add 'superadmin' role (default stays 'user', unchanged)
ALTER TABLE `users` MODIFY COLUMN `role`
  enum('superadmin','admin','manager','user') NOT NULL DEFAULT 'user';
ALTER TABLE `users` ADD UNIQUE KEY IF NOT EXISTS `username` (`username`);


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 6: Narrowing columns — PRE-CHECK before running the MODIFY
-- ────────────────────────────────────────────────────────────────────────────
-- Run each SELECT and confirm the max length is within the new limit before
-- executing the MODIFY directly below it. If the max exceeds the limit,
-- MariaDB will silently truncate on MODIFY (non-strict mode) or error
-- (strict mode) — fix the offending rows first either way.

-- product_owners.name: 100 → 90
SELECT MAX(LENGTH(`name`)) AS max_len_name FROM `product_owners`;
-- If max_len_name <= 90, then:
ALTER TABLE `product_owners` MODIFY COLUMN `name` varchar(90) NOT NULL;

-- wishlist.product_name: 255 → 70
SELECT MAX(LENGTH(`product_name`)) AS max_len_product_name FROM `wishlist`;
-- If max_len_product_name <= 70, then:
ALTER TABLE `wishlist` MODIFY COLUMN `product_name` varchar(70) NOT NULL;


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 7: Remaining company_id-driven indexes on other tables
-- ────────────────────────────────────────────────────────────────────────────

ALTER TABLE `order_owners`     ADD INDEX IF NOT EXISTS `idx_order_owners_company` (`company_id`);
ALTER TABLE `purchases`        ADD INDEX IF NOT EXISTS `idx_purchase_date` (`purchase_date`);
ALTER TABLE `purchases`        ADD INDEX IF NOT EXISTS `idx_purchases_company_date` (`company_id`, `purchase_date`);
ALTER TABLE `stock_movements`  ADD INDEX IF NOT EXISTS `idx_stock_movements_company_date` (`company_id`, `moved_date`);
ALTER TABLE `loans`            ADD INDEX IF NOT EXISTS `idx_loans_company_date` (`company_id`, `loan_date`);
-- idx_client_id (existing) already covers client_id — screen_db's
-- idx_loans_client_id would just be a duplicate index, skipped.
-- name_phone (existing) already covers (name, phone) — screen_db's
-- uq_name_phone would just be a duplicate unique key, skipped.
ALTER TABLE `expenses`         ADD INDEX IF NOT EXISTS `idx_expense_date` (`expense_date`);
ALTER TABLE `product_owners`   ADD UNIQUE KEY IF NOT EXISTS `uq_owner_name_phone` (`name`, `phone`);


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 8: Drop foreign keys screen_db no longer enforces
-- ────────────────────────────────────────────────────────────────────────────
-- These don't delete any data — they just relax referential enforcement to
-- match screen_db (retail_id/bulk_id/given_by/received_by columns stay put).
-- Not IF-EXISTS-guarded (MariaDB doesn't support that for DROP FOREIGN KEY):
-- if you re-run this section after it already succeeded once, these four
-- lines will error "check that constraint exists" — that's expected, just
-- skip them.

ALTER TABLE `loans` DROP FOREIGN KEY `loans_ibfk_bulk`;
ALTER TABLE `loans` DROP FOREIGN KEY `loans_ibfk_given_by`;
ALTER TABLE `loans` DROP FOREIGN KEY `loans_ibfk_retail`;
ALTER TABLE `loan_payments` DROP FOREIGN KEY `lp_ibfk_received_by`;


-- ────────────────────────────────────────────────────────────────────────────
-- SECTION 9: Seed superadmin (optional — only if you want the
-- multi-company admin flow usable immediately)
-- ────────────────────────────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). CHANGE IMMEDIATELY after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).

INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');


-- ============================================================================
-- Not migrated on purpose:
--   • `client_loans` and `subscription` tables (exist only in stock_db) are
--     left untouched — screen_db dropped/never had them, but nothing here
--     asked to delete stock_db data.
--   • `loans.sale_type` / `loans.unit_price` columns kept (see Section 4 note
--     equivalent for loans) rather than dropped.
--   • `audit_log.username` / `audit_log.description` kept per your choice.
-- ============================================================================
