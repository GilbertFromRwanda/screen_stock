



-- ── consumption.source column ─────────────────────────────────────────────────
ALTER TABLE consumption ADD COLUMN IF NOT EXISTS source VARCHAR(10) NOT NULL DEFAULT 'retail' AFTER qty;


-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');





-- Currency rates: per-company exchange rates for foreign → USD → RWF conversion on purchases
CREATE TABLE IF NOT EXISTS `currency_rates` (
    `id`           INT           AUTO_INCREMENT PRIMARY KEY,
    `company_id`   INT           DEFAULT NULL,
    `usd_rate`     DECIMAL(10,4) NOT NULL DEFAULT 1300.0000,
    `foreign_rate` DECIMAL(10,4) NOT NULL DEFAULT 1.0000,
    `foreign_name` VARCHAR(10)   NOT NULL DEFAULT 'USD',
    `updated_at`   DATETIME      DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_cr_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Orders: customer orders that convert to bulk sales on approval
CREATE TABLE IF NOT EXISTS `orders` (
   `id` INT AUTO_INCREMENT PRIMARY KEY,
  `order_owner_id` int(11) DEFAULT NULL,
  `order_number` varchar(20) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,3) NOT NULL,
  `level_divisor` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `order_owner` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL DEFAULT '',
  `prepaid_cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prepaid_momo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prepaid_loan` decimal(12,2) NOT NULL DEFAULT 0.00,
  `prepaid_bank` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_prepaid` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','cancelled') NOT NULL DEFAULT 'pending',
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Order owners: reusable customer/buyer profiles linked to orders
CREATE TABLE IF NOT EXISTS `order_owners` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(255) NOT NULL,
    `phone`      VARCHAR(50)  NOT NULL DEFAULT '',
    `location`   VARCHAR(255) NOT NULL DEFAULT '',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_owner_name` (`name`)
);

-- Link orders to order_owners
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_owner_id` INT NULL AFTER `id`;

-- Order number (ORD-00001 style)
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `order_number` VARCHAR(20) NULL AFTER `order_owner_id`;

-- Allow product_id to be NULL for multi-item orders
ALTER TABLE `orders` MODIFY COLUMN `product_id` INT NULL;

-- company_id scoping for orders and order_owners
ALTER TABLE `orders`       ADD COLUMN IF NOT EXISTS `company_id` INT NULL AFTER `id`;
ALTER TABLE `order_owners` ADD COLUMN IF NOT EXISTS `company_id` INT NULL AFTER `id`;

-- Indexes on orders for filter/sort/join performance
CREATE INDEX IF NOT EXISTS `idx_orders_company`        ON `orders` (`company_id`);
CREATE INDEX IF NOT EXISTS `idx_order_owners_company`  ON `order_owners` (`company_id`);
CREATE INDEX IF NOT EXISTS `idx_orders_status`         ON `orders` (`status`);
CREATE INDEX IF NOT EXISTS `idx_orders_created_at`     ON `orders` (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_status_date`    ON `orders` (`status`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_orders_order_owner_id` ON `orders` (`order_owner_id`);
CREATE INDEX IF NOT EXISTS `idx_orders_created_by`     ON `orders` (`created_by`);
CREATE INDEX IF NOT EXISTS `idx_orders_approved_by`    ON `orders` (`approved_by`);
CREATE INDEX IF NOT EXISTS `idx_orders_order_number`   ON `orders` (`order_number`);

-- Order items: one row per product per order
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`            INT AUTO_INCREMENT PRIMARY KEY,
    `order_id`      INT NOT NULL,
    `product_id`    INT NOT NULL,
    `stock_source`  ENUM('wh','rt') NOT NULL DEFAULT 'wh',
    `quantity`      DECIMAL(10,3) NOT NULL,
    `level_divisor` INT NOT NULL DEFAULT 1,
    `selling_price` DECIMAL(12,2) NOT NULL DEFAULT 0,
    `item_total`    DECIMAL(12,2) NOT NULL DEFAULT 0,
    INDEX `idx_oi_order`   (`order_id`),
    INDEX `idx_oi_product` (`product_id`)
);

-- stock_source on existing order_items rows
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `stock_source` ENUM('wh','rt') NOT NULL DEFAULT 'wh' AFTER `product_id`;

-- Fulfillment status per order item (pending → fulfilled or out_of_stock at approval)
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `status` ENUM('pending','fulfilled','out_of_stock') NOT NULL DEFAULT 'pending' AFTER `stock_source`;

-- Refund owed for out-of-stock items at approval time
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `refund_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_prepaid`;

-- Reason entered when cancelling an order
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancel_reason` TEXT NULL AFTER `status`;

-- Who cancelled the order
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `cancelled_by` INT NULL AFTER `approved_by`;


-- ── audit_log table ───────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id`          INT AUTO_INCREMENT PRIMARY KEY,
    `company_id`  INT           DEFAULT NULL,
    `user_id`     INT           NOT NULL,
    `action`      VARCHAR(255)  NOT NULL,
    `description` TEXT          DEFAULT NULL,
    `table_name`  VARCHAR(255)  DEFAULT NULL,
    `record_id`   INT           DEFAULT NULL,
    `old_values`  JSON          DEFAULT NULL,
    `new_values`  JSON          DEFAULT NULL,
    `ip_address`  VARCHAR(45)   DEFAULT NULL,
    `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_al_company` (`company_id`),
    INDEX `idx_al_user`    (`user_id`),
    INDEX `idx_al_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- ── user_permissions table ────────────────────────────────────────────────────
-- Stores per-module access flags for manager/user roles.
-- admin and superadmin bypass this table entirely.
CREATE TABLE IF NOT EXISTS `user_permissions` (
    `id`         INT          AUTO_INCREMENT PRIMARY KEY,
    `user_id`    INT          NOT NULL,
    `company_id` INT          DEFAULT NULL,
    `module`     VARCHAR(50)  NOT NULL,
    `can_view`   TINYINT(1)   NOT NULL DEFAULT 0,
    `can_edit`   TINYINT(1)   NOT NULL DEFAULT 0,
    `can_delete` TINYINT(1)   NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_user_module` (`user_id`, `module`),
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_up_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── add can_create to user_permissions ───────────────────────────────────────
ALTER TABLE `user_permissions` ADD COLUMN IF NOT EXISTS `can_create` TINYINT(1) NOT NULL DEFAULT 0 AFTER `can_view`;


-- ── client_payments: direct cash receipts from loan clients ───────────────────
CREATE TABLE IF NOT EXISTS `client_payments` (
    `id`            INT           AUTO_INCREMENT PRIMARY KEY,
    `company_id`    INT           DEFAULT NULL,
    `client_id`     INT           NOT NULL,
    `amount`        DECIMAL(12,2) NOT NULL,
    `payment_date`  DATE          NOT NULL,
    `recorded_by`   INT           NOT NULL,
    `note`          VARCHAR(255)  DEFAULT NULL,
    `created_at`    TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`client_id`) REFERENCES `loan_clients`(`id`) ON DELETE CASCADE,
    INDEX `idx_cp_client`  (`client_id`),
    INDEX `idx_cp_company` (`company_id`),
    INDEX `idx_cp_date`    (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


ALTER TABLE stock_value_cache
  ADD COLUMN IF NOT EXISTS company_id INT NOT NULL DEFAULT 0 AFTER product_id,
  ADD UNIQUE KEY uq_product (product_id, company_id);


-- ── order_payments: individual payment transactions against an order ───────────
CREATE TABLE IF NOT EXISTS `order_payments` (
    `id`          INT           AUTO_INCREMENT PRIMARY KEY,
    `company_id`  INT           DEFAULT NULL,
    `order_id`    INT           NOT NULL,
    `cash`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `momo`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `bank`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `loan`        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `total`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `recorded_by` INT           NOT NULL,
    `note`        VARCHAR(255)  DEFAULT NULL,
    `created_at`  TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_op_order`   (`order_id`),
    INDEX `idx_op_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 'closed' status for orders
ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('pending','approved','cancelled','closed') NOT NULL DEFAULT 'pending';


-- ── products.search_text: generated column + FULLTEXT index for fast product search ──
-- CONCAT of name + category, used by the AJAX "search_products" endpoints via
-- MATCH(search_text) AGAINST (... IN BOOLEAN MODE) instead of "name LIKE OR category LIKE".
ALTER TABLE `products`
  ADD COLUMN IF NOT EXISTS `search_text` VARCHAR(160)
    GENERATED ALWAYS AS (CONCAT(`name`, ' ', COALESCE(`category`, ''))) STORED
    AFTER `category`;

CREATE FULLTEXT INDEX IF NOT EXISTS `ftx_products_search_text` ON `products` (`search_text`);


-- ── Customer self-order links: new/open pre-pending states, 5-digit code + expiry ──
ALTER TABLE `orders` MODIFY COLUMN `status` ENUM('new','open','pending','approved','cancelled','closed') NOT NULL DEFAULT 'pending';
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `show_prices` TINYINT(1) NOT NULL DEFAULT 1 AFTER `status`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `link_code` CHAR(5) NULL AFTER `show_prices`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `link_expires_at` DATETIME NULL AFTER `link_code`;
CREATE INDEX IF NOT EXISTS `idx_orders_link_code` ON `orders` (`link_code`);

-- Allow free-text (non-catalog) items typed by the customer on the order link
ALTER TABLE `order_items` MODIFY COLUMN `product_id` INT NULL;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `custom_name` VARCHAR(150) NULL AFTER `product_id`;
ALTER TABLE `order_items` MODIFY COLUMN `stock_source` ENUM('wh','rt','custom') NOT NULL DEFAULT 'wh';

-- Reusable customer links: the link's own order row stays 'open' and spawns a fresh
-- child order on every submission, instead of being consumed after one order.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `is_reusable` TINYINT(1) NOT NULL DEFAULT 0 AFTER `link_expires_at`;

-- Traces each child order spawned by a reusable link back to that link's own order row.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `source_order_id` INT NULL AFTER `is_reusable`;
CREATE INDEX IF NOT EXISTS `idx_orders_source_order` ON `orders` (`source_order_id`);

-- Per-item origin: was this line added by staff, or by the customer through their order link?
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `source` ENUM('staff','customer') NOT NULL DEFAULT 'staff' AFTER `item_total`;
ALTER TABLE `order_items` ADD COLUMN IF NOT EXISTS `added_by` INT NULL AFTER `source`;

-- Who's responsible for handling the order (defaults to whoever created it / the link), and the
-- physical fulfillment pipeline separate from the approval status.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `in_charge_id` INT NULL AFTER `created_by`;
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `delivery_status` ENUM('placed','packed','ready','delivered') NOT NULL DEFAULT 'placed' AFTER `status`;
CREATE INDEX IF NOT EXISTS `idx_orders_in_charge` ON `orders` (`in_charge_id`);


-- ── loans.cart: full list of every product sold, for loans that span more than one
-- product (bulk/retail/external sales now let one client take several products in a
-- single sale, but a loans row still has only one product_id/qty/amount). When a loan
-- covers more than one product, product_id is left NULL and this column carries the
-- itemized list (product_id, name, qty, price, item_total) so the UI can show exactly
-- what was taken instead of just the first item.
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS `cart` JSON NULL AFTER `product_name`;


-- ── cache_meta: change-tracking for the client-side IndexedDB cache ───────────
-- touchCacheStore() (config.php) bumps updated_at whenever products/stock/
-- retail_stock or loan_clients change. js/data-cache.js polls the cheap
-- data_api.php?action=meta endpoint and only refetches a store's full data
-- when the server's updated_at is newer than what it last cached, instead of
-- blindly expiring every few minutes.
CREATE TABLE IF NOT EXISTS `cache_meta` (
    `store_name`  VARCHAR(50) NOT NULL,
    `company_id`  INT         NOT NULL DEFAULT 0,
    `updated_at`  TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`store_name`, `company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ── categories table: normalizes products.category from a free-text input into a
-- managed list (picked from a dropdown in products.php instead of typed, so it can't
-- drift into near-duplicate values like "Screen"/"screens"/"Screen "). products.category
-- itself is kept as a denormalized text column so the existing FULLTEXT search_text
-- column and every other page's `category` reads/filters keep working unchanged;
-- products.php now writes category_id + category together on every insert/update.
CREATE TABLE IF NOT EXISTS `categories` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(50) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `category_id` INT NULL AFTER `category`;

-- Backfill: one categories row per distinct existing category value, then point products at it.
INSERT IGNORE INTO `categories` (`name`)
SELECT DISTINCT `category` FROM `products` WHERE `category` IS NOT NULL AND `category` != '';

UPDATE `products` p
JOIN `categories` c ON c.name = p.category
SET p.category_id = c.id
WHERE p.category_id IS NULL;

CREATE INDEX IF NOT EXISTS `idx_products_category_id` ON `products` (`category_id`);


-- ── Order review workflow: pending → processing/rejected, processing → completed/closed ──
-- 'approved' is kept in the enum for historical rows (nothing new writes it after this
-- migration); 'processing'/'completed'/'rejected' are the new review-pipeline states.
ALTER TABLE `orders` MODIFY COLUMN `status`
  ENUM('new','open','pending','processing','completed','rejected','approved','cancelled','closed')
  NOT NULL DEFAULT 'pending';

-- Existing approved orders already have their sale/stock recorded — they're "completed" now.
UPDATE `orders` SET `status` = 'completed' WHERE `status` = 'approved';

-- Delivery pipeline gains a final "customer confirmed receipt" stage.
ALTER TABLE `orders` MODIFY COLUMN `delivery_status`
  ENUM('placed','packed','ready','delivered','received') NOT NULL DEFAULT 'placed';

-- Remembers the status an order had right before it was closed, so reopen (admin/superadmin
-- only) can restore it exactly instead of guessing.
ALTER TABLE `orders` ADD COLUMN IF NOT EXISTS `status_before_close` VARCHAR(20) NULL AFTER `status`;


-- ── notifications: one row per (order, recipient), deleted the moment that recipient's
-- long-poll (notifications_poll.php) delivers it — keeps the table small automatically
-- instead of needing a read/unread flag or a cleanup job.
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`           INT           AUTO_INCREMENT PRIMARY KEY,
    `user_id`      INT           NOT NULL,
    `company_id`   INT           DEFAULT NULL,
    `order_id`     INT           NOT NULL,
    `order_number` VARCHAR(20)   DEFAULT NULL,
    `message`      VARCHAR(255)  NOT NULL,
    `created_at`   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    `delivered_at` DATETIME      DEFAULT NULL,
    INDEX `idx_notif_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- A row is only handed to the long-poll once (delivered_at gets stamped) and
-- is deleted only when the user explicitly marks it read — not immediately
-- on delivery, so a missed/backgrounded-tab notification isn't lost.
ALTER TABLE `notifications` ADD COLUMN IF NOT EXISTS `delivered_at` DATETIME NULL AFTER `created_at`;


-- ── sales_bulk.cost_total / sales_retail.cost_total: precomputed COGS ─────────
-- Previously every report (summary-revenue, revenue, ajax_dashboard) recomputed
-- cost-of-goods-sold on the fly via a correlated subquery that looked up "the
-- most recent purchase before this sale's date" each time it ran. That's slow
-- and, worse, not actually historical: it read a product's *current* stock
-- packaging ratio, so a sale's reported cost could silently change later if
-- the product got repackaged. cost_total is now computed once at sale time
-- (sales.php, orders.php) and frozen on the row, same idea as storing
-- level_divisor/pieces_sold themselves.
ALTER TABLE `sales_bulk`   ADD COLUMN IF NOT EXISTS `cost_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `cost_total` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `total_amount`;

-- Backfill existing rows using the same "last purchase before sale_date" lookup
-- the reports used to do inline, so historical figures don't shift under the swap.
UPDATE `sales_bulk` sb
SET sb.cost_total = (
    COALESCE(
        (SELECT pu.cost_price FROM `purchases` pu
         WHERE pu.product_id = sb.product_id AND pu.company_id <=> sb.company_id
           AND pu.purchase_date <= sb.sale_date
         ORDER BY pu.purchase_date DESC LIMIT 1), 0
    ) * sb.quantity / COALESCE(NULLIF(sb.level_divisor, 0), 1)
)
WHERE sb.cost_total = 0;

UPDATE `sales_retail` sr
SET sr.cost_total = (
    SELECT pu.cost_price / NULLIF(pu.pieces_per_qty, 0) * sr.pieces_sold
    FROM `purchases` pu
    WHERE pu.product_id = sr.product_id AND pu.company_id <=> sr.company_id
      AND pu.purchase_date <= sr.sale_date
    ORDER BY pu.purchase_date DESC LIMIT 1
)
WHERE sr.cost_total = 0;
UPDATE `sales_retail` SET cost_total = 0 WHERE cost_total IS NULL;


-- ── sales_bulk.purchase_id / sales_retail.purchase_id: which purchase a sale's
-- cost_total was snapshotted from. Lets purchases.php's "Edit Purchase" find and
-- re-sync exactly the sales that were costed against a purchase when its
-- cost_price/pieces_per_qty gets corrected, instead of leaving them stale forever.
ALTER TABLE `sales_bulk`   ADD COLUMN IF NOT EXISTS `purchase_id` INT NULL AFTER `cost_total`;
ALTER TABLE `sales_retail` ADD COLUMN IF NOT EXISTS `purchase_id` INT NULL AFTER `cost_total`;
CREATE INDEX IF NOT EXISTS `idx_sb_purchase_id` ON `sales_bulk`   (`purchase_id`);
CREATE INDEX IF NOT EXISTS `idx_sr_purchase_id` ON `sales_retail` (`purchase_id`);

-- Backfill: same "last purchase before sale_date" lookup used for the cost_total backfill.
UPDATE `sales_bulk` sb
SET sb.purchase_id = (
    SELECT pu.id FROM `purchases` pu
    WHERE pu.product_id = sb.product_id AND pu.company_id <=> sb.company_id
      AND pu.purchase_date <= sb.sale_date
    ORDER BY pu.purchase_date DESC LIMIT 1
)
WHERE sb.purchase_id IS NULL;

UPDATE `sales_retail` sr
SET sr.purchase_id = (
    SELECT pu.id FROM `purchases` pu
    WHERE pu.product_id = sr.product_id AND pu.company_id <=> sr.company_id
      AND pu.purchase_date <= sr.sale_date
    ORDER BY pu.purchase_date DESC LIMIT 1
)
WHERE sr.purchase_id IS NULL;
