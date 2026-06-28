



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
