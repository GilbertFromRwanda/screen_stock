
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
