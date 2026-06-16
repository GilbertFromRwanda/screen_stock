



-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');


-- ── stock_value_cache ────────────────────────────────────────────────────────
-- Stores pre-computed FIFO purchase cost and selling value per product per company.
-- Refreshed automatically on every purchase, sale, and stock change.
-- Use ajax_recalc_stock.php to force a full rebuild.
CREATE TABLE IF NOT EXISTS `stock_value_cache` (
    `id`         INT           NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT           DEFAULT NULL,
    `product_id` INT           NOT NULL,
    `cost_wh`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `cost_rt`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `sell_wh`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `sell_rt`    DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `updated_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_svc_company` (`company_id`),
    INDEX `idx_svc_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Notes: personal notes per user with pin support
CREATE TABLE IF NOT EXISTS `notes` (
    `id`         INT AUTO_INCREMENT PRIMARY KEY,
    `company_id` INT DEFAULT NULL,
    `user_id`    INT NOT NULL,
    `title`      VARCHAR(255) NOT NULL,
    `note`       TEXT NOT NULL,
    `is_pinned`  TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_notes_company` (`company_id`),
    INDEX `idx_notes_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Wishlist: products clients want that are not yet in stock
CREATE TABLE IF NOT EXISTS wishlist (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    product_name VARCHAR(255) NOT NULL,
    client_count INT          NOT NULL DEFAULT 1,
    status       ENUM('pending','purchased') NOT NULL DEFAULT 'pending',
    created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
    purchased_at DATETIME NULL
);

