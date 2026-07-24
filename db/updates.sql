

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


-- ── Seed: initial company ─────────────────────────────────────────────────────
-- Safe to re-run; INSERT IGNORE skips if id=1 already exists.
INSERT IGNORE INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`)
VALUES (1, 'My Company', NULL, NULL, NULL, 'active', NOW());


-- ── Seed: superadmin user ─────────────────────────────────────────────────────
-- Password is 'admin123' (bcrypt). Change immediately after first login.
-- company_id = NULL marks this account as superadmin (no tenant scope).
INSERT IGNORE INTO `users` (`company_id`, `username`, `password`, `full_name`, `role`, `status`)
VALUES (NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', 'active');




