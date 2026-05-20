-- ============================================================
-- Schema updates (apply on top of screen_db.sql)
-- ============================================================

-- loans: link each loan back to its originating sale; store product name for external sales
ALTER TABLE `loans`
  MODIFY COLUMN `product_id` INT DEFAULT NULL,
  ADD COLUMN `product_name` VARCHAR(255) DEFAULT NULL AFTER `product_id`,
  ADD COLUMN `retail_id`   INT DEFAULT NULL,
  ADD COLUMN `bulk_id`     INT DEFAULT NULL,
  ADD COLUMN `external_id` INT DEFAULT NULL;

-- sales_bulk: flag sales that carry a loan, store loan amount
ALTER TABLE `sales_bulk`
  ADD COLUMN `has_loan` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `amount`   DECIMAL(12,2) DEFAULT 0.00;

-- sales_retail: flag sales that carry a loan, store loan amount
ALTER TABLE `sales_retail`
  ADD COLUMN `has_loan` TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN `amount`   DECIMAL(12,2) DEFAULT 0.00;

-- loan_clients: persistent registry of all loan takers
CREATE TABLE IF NOT EXISTS `loan_clients` (
  `id`            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `name`          VARCHAR(255) NOT NULL,
  `phone`         VARCHAR(30) DEFAULT NULL,
  `total_loans`   INT NOT NULL DEFAULT 0,
  `paid_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `unpaid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uq_client_phone` (`name`, `phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- If loan_clients already exists, add the aggregate columns
ALTER TABLE `loan_clients`
  ADD COLUMN IF NOT EXISTS `total_loans`   INT NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS `paid_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `unpaid_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;


-- Step 1: Register any clients from loans that aren't in loan_clients yet
INSERT IGNORE INTO loan_clients (name, phone)
SELECT DISTINCT client, NULLIF(phone, '') FROM loans;

-- Step 2: Recompute all three aggregate columns for every client
UPDATE loan_clients lc
JOIN (
    SELECT l.client,
           COALESCE(l.phone,'')           AS phone,
           COUNT(DISTINCT l.id)           AS cnt,
           COALESCE(SUM(l.amount), 0)     AS loaned,
           COALESCE(SUM(lp_s.paid), 0)    AS paid_sum
    FROM loans l
    LEFT JOIN (
        SELECT loan_id, SUM(amount_paid) AS paid
        FROM loan_payments GROUP BY loan_id
    ) lp_s ON lp_s.loan_id = l.id
    GROUP BY l.client, COALESCE(l.phone,'')
) agg ON lc.name = agg.client
      AND COALESCE(lc.phone,'') = agg.phone
SET lc.total_loans   = agg.cnt,
    lc.paid_amount   = agg.paid_sum,
    lc.unpaid_amount = agg.loaned - agg.paid_sum;


-- Backfill aggregate columns from existing loans/payments data
UPDATE loan_clients lc
JOIN (
    SELECT l.client, COALESCE(l.phone,'') AS phone,
           COUNT(DISTINCT l.id)              AS cnt,
           COALESCE(SUM(l.amount), 0)        AS loaned,
           COALESCE(SUM(lp_s.paid), 0)       AS paid_sum
    FROM loans l
    LEFT JOIN (SELECT loan_id, SUM(amount_paid) AS paid FROM loan_payments GROUP BY loan_id) lp_s
           ON lp_s.loan_id = l.id
    GROUP BY l.client, COALESCE(l.phone,'')
) agg ON lc.name = agg.client AND COALESCE(lc.phone,'') = agg.phone
SET lc.total_loans   = agg.cnt,
    lc.paid_amount   = agg.paid_sum,
    lc.unpaid_amount = agg.loaned - agg.paid_sum;



   CREATE TABLE `refunds` (
   `id`            INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `sale_type` enum('bulk','retail','external') NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loss_amount` decimal(12,2) DEFAULT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `back_to_stock` tinyint(1) NOT NULL DEFAULT 0,
  `refund_date` date NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add loss_amount to existing refunds table
ALTER TABLE `refunds` ADD COLUMN IF NOT EXISTS `loss_amount` DECIMAL(12,2) DEFAULT NULL AFTER `refund_amount`;

-- Mark refunded sales instead of deleting them
ALTER TABLE `sales_bulk`     ADD COLUMN IF NOT EXISTS `refunded` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `sales_retail`   ADD COLUMN IF NOT EXISTS `refunded` TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE `sales_external` ADD COLUMN IF NOT EXISTS `refunded` TINYINT(1) NOT NULL DEFAULT 0;
 
