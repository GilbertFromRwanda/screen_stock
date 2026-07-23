



-- ── consumption.source column ─────────────────────────────────────────────────
ALTER TABLE consumption ADD COLUMN IF NOT EXISTS source VARCHAR(10) NOT NULL DEFAULT 'retail' AFTER qty;


-- ── cart_json columns (sales_bulk / sales_retail / sales_external) ─────────────
-- Stores the full cart (all items) as JSON on the sale's first row, so the whole
-- checkout can be reconstructed/reprinted from a single row.
ALTER TABLE sales_bulk     ADD COLUMN IF NOT EXISTS cart_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(cart_json)) AFTER refunded;
ALTER TABLE sales_retail   ADD COLUMN IF NOT EXISTS cart_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(cart_json)) AFTER refunded;
ALTER TABLE sales_external ADD COLUMN IF NOT EXISTS cart_json LONGTEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(cart_json)) AFTER my_revenue;


-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');




