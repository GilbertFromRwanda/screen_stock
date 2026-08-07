

-- `categories` — normalized product categories. products.category stays as
-- a denormalized text column for search/filters; category_id (below) is
-- the join key, backfilled from the distinct products.category values
-- already in the table (see backfill block below).
CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;



-- products: category_id join key + generated full-text search column
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `category_id` int(11) DEFAULT NULL AFTER `category`;
ALTER TABLE `products` ADD COLUMN IF NOT EXISTS `search_text` varchar(160)
  GENERATED ALWAYS AS (concat(`name`, ' ', coalesce(`category`, ''))) STORED AFTER `category_id`;
ALTER TABLE `products` ADD INDEX IF NOT EXISTS `idx_products_category_id` (`category_id`);
ALTER TABLE `products` ADD FULLTEXT INDEX IF NOT EXISTS `ftx_products_search_text` (`search_text`);

-- Backfill: create a `categories` row for every distinct products.category
-- value, then point category_id at it. `categories`.name is unique under
-- utf8mb4_general_ci (case-insensitive), so values differing only by case
-- collapse onto whichever spelling was inserted first — use categories.php
-- (rename/merge) afterward to clean up any such duplicates.
-- Idempotent: INSERT IGNORE skips names that already exist, and the UPDATE
-- only touches rows still missing a category_id, so re-running is a no-op.
INSERT IGNORE INTO `categories` (`name`)
SELECT DISTINCT TRIM(`category`)
FROM `products`
WHERE `category` IS NOT NULL AND TRIM(`category`) <> '';

UPDATE `products` p
JOIN `categories` c ON c.name = TRIM(p.category)
SET p.category_id = c.id
WHERE p.category_id IS NULL;



