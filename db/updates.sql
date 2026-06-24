



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



