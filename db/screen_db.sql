-- Database Backup: screen_db
-- Generated: 2026-06-28 10:31:07

SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `audit_log`;
CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_company` (`company_id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_table` (`table_name`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `boaster`;
CREATE TABLE `boaster` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `giver` varchar(255) NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `client_payments`;
CREATE TABLE `client_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_cp_client` (`client_id`),
  KEY `idx_cp_company` (`company_id`),
  KEY `idx_cp_date` (`payment_date`),
  CONSTRAINT `client_payments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `loan_clients` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `companies`;
CREATE TABLE `companies` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`) VALUES
('1', 'Test ltd', 'askforgilbert@gmail.com', '+250789047173', 'Remera', 'active', '2026-06-01 12:33:33');

DROP TABLE IF EXISTS `consumption`;
CREATE TABLE `consumption` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `source` varchar(10) NOT NULL DEFAULT 'retail',
  `amount` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `done_by` varchar(100) DEFAULT NULL,
  `consumption_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_consumption_date` (`consumption_date`),
  CONSTRAINT `consumption_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `currency_rates`;
CREATE TABLE `currency_rates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `usd_rate` decimal(10,4) NOT NULL DEFAULT 1300.0000,
  `foreign_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `foreign_name` varchar(10) NOT NULL DEFAULT 'USD',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_cr_company` (`company_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `currency_rates` (`id`, `company_id`, `usd_rate`, `foreign_rate`, `foreign_name`, `updated_at`) VALUES
('1', '1', '1475.0000', '3.6600', 'UAE', '2026-06-24 10:07:40');

DROP TABLE IF EXISTS `expenses`;
CREATE TABLE `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_expense_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `loan_clients`;
CREATE TABLE `loan_clients` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_loans` int(11) NOT NULL DEFAULT 0,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unpaid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_name_phone` (`name`,`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `loan_payments`;
CREATE TABLE `loan_payments` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `loan_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `loan_id` (`loan_id`),
  CONSTRAINT `loan_payments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `loans`;
CREATE TABLE `loans` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `client` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `loan_date` date NOT NULL,
  `given_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `retail_id` int(11) DEFAULT NULL,
  `bulk_id` int(11) DEFAULT NULL,
  `external_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_loan_date` (`loan_date`),
  KEY `idx_client` (`client`),
  KEY `idx_loans_client_id` (`client_id`),
  CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `notes`;
CREATE TABLE `notes` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `note` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notes_company` (`company_id`),
  KEY `idx_notes_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `order_items`;
CREATE TABLE `order_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `stock_source` enum('wh','rt') NOT NULL DEFAULT 'wh',
  `status` enum('pending','fulfilled','out_of_stock') NOT NULL DEFAULT 'pending',
  `quantity` decimal(10,3) NOT NULL,
  `level_divisor` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_oi_order` (`order_id`),
  KEY `idx_oi_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `order_owners`;
CREATE TABLE `order_owners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL DEFAULT '',
  `location` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_owner_name` (`name`),
  KEY `idx_order_owners_company` (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `orders`;
CREATE TABLE `orders` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
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
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `status` enum('pending','approved','cancelled') NOT NULL DEFAULT 'pending',
  `cancel_reason` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_orders_company` (`company_id`),
  KEY `idx_orders_status` (`status`),
  KEY `idx_orders_created_at` (`created_at`),
  KEY `idx_orders_status_date` (`status`,`created_at`),
  KEY `idx_orders_order_owner_id` (`order_owner_id`),
  KEY `idx_orders_created_by` (`created_by`),
  KEY `idx_orders_approved_by` (`approved_by`),
  KEY `idx_orders_order_number` (`order_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `product_owners`;
CREATE TABLE `product_owners` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(90) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_owner_name_phone` (`name`,`phone`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `products`;
CREATE TABLE `products` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `reorder_level` int(11) DEFAULT 10,
  `unit_measure` varchar(20) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted` int(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_deleted` (`deleted`),
  KEY `idx_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=252 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `products` (`id`, `name`, `category`, `reorder_level`, `unit_measure`, `unit_price`, `created_at`, `deleted`) VALUES
('1', 'KA7', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('2', 'KB7', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('3', 'KB8', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('4', 'KC8', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('5', 'KD7', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('6', 'X657', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('7', 'X688', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('8', 'X689', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('9', 'BG6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('10', 'BG6M', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('11', 'BD3', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('12', 'X612', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('13', 'X6816', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('14', 'CH6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('15', 'BE8', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('16', 'CG6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('17', 'KI7', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('18', 'X6835', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('19', 'BV', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('20', 'X6511', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('21', 'CK6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('22', 'KL4', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('23', 'CL6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('24', 'CM5', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('25', 'BF6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('26', 'CC9 COPY', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('27', 'CE9', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('28', 'BC2', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('29', 'X6725', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('30', 'KD6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('31', 'X680', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('32', 'X663', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('33', 'ITEL A50', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('34', 'ITE A50C', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('35', 'ITEL A58', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('36', 'KJ6', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('37', 'X626', 'TECNO & INFINIX', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('38', '6S', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('39', '7G', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('40', '7PUS', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('41', '8G', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('42', '8PUS', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('43', 'X JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('44', 'XGX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('45', 'X CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('46', 'XS JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('47', 'XS GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('48', 'XS CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('49', 'XS MAX JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('50', 'XS MAX GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('51', 'XS MAX CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('52', 'XR JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('53', 'XR GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('54', 'XR CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('55', 'X11 JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('56', 'X11 GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('57', 'X11 CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('58', 'X12 JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('59', 'X12 GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('60', 'X12 CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('61', '12 PRO MAX JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('62', '12 PRO MAX GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('63', '12 PRO MAX CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('64', '13 JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('65', '13 GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('66', '13 CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('67', '13 PRO JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('68', '13 PRO GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('69', '13 PRO CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('70', '13 PRO DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('71', '13 PRO MAX JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('72', '13 PRO MAX GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('73', '13 PROMAX CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('74', '13 PRO MAX DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('75', '14 JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('76', '14 PRO JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('77', '14 PRO GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('78', '14 PRO DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('79', '14 PRO CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('80', '14 PRO MAX JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('81', '14 PRO MAX GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('82', '14 PRO MAX CIMINO', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('83', '14 PRO MAX DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('84', 'X15 JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('85', '15 PRO GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('86', '15 PRO DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('87', '15 PRO JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('88', '16 PRO JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('89', '16 PRO DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('90', '16 PRO MAX JH', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('91', '16 PRO MAX GX', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('92', '17 PRO MAX DD', 'IPHONES', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('93', 'A03', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('94', 'A04', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('95', 'A04S', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('96', 'A12', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('97', 'A13', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('98', 'A14 4G', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('99', 'A14 5G', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('100', 'A15 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('101', 'A15 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('102', 'A16 4G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('103', 'A16 OLED 4G COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('104', 'A16  5G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('105', 'A16 5G COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('106', 'A05', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('107', 'A05S', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('108', 'A06', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('109', 'A530 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('110', 'A530 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('111', 'A20 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('112', 'A20 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('113', 'A30 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('114', 'A30 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('115', 'A31 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('116', 'A31 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('117', 'A30S OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('118', 'A30S COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('119', 'M30 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('120', 'M30 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('121', 'M32 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('122', 'A520 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('123', 'A520 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('124', 'A03S 1 FLEX', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('125', 'A11', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:21', '0'),
('126', 'A10S', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('127', 'A20S', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('128', 'A21S', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('129', 'A23', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('130', 'A24 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('131', 'A24 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('132', 'A10', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('133', 'J530 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('134', 'J530 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('135', 'J730  OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('136', 'J730 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('137', 'J330', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('138', 'A03 CORE', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('139', 'A01 CORE', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('140', 'A260', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('141', 'J260', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('142', 'A720 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('143', 'A730 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('144', 'A90 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('145', 'A720 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('146', 'J610', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('147', 'J810 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('148', 'J810 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('149', 'G570', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('150', 'G610', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('151', 'A42 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('152', 'A42 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('153', 'A32 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('154', 'A32 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('155', 'A40 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('156', 'A52 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('157', 'A52 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('158', 'A53 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('159', 'A20E', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('160', 'A51 5G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('161', 'A51 5G COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('162', 'A71 4G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('163', 'A71 4G COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('164', 'S8 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('165', 'S8 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('166', 'S9 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('167', 'S9 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('168', 'S8 PLUS OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('169', 'S8 PLUS COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('170', 'S10 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('171', 'S10 PLUS COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('172', 'S20 FE OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('173', 'S20 FE COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('174', 'S20 4G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('175', 'S20 5G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('176', 'S21 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('177', 'S2O PLUS OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('178', 'S20 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('179', 'S21 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('180', 'S22 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('181', 'S22 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('182', 'S23 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('183', 'S24 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('184', 'NOT 10 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('185', 'NOTE 10 PLUS OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('186', 'NOT 20 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('187', 'NOT 20 ULTRA OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('188', 'A22 4G OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('189', 'A22 4G COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('190', 'A22 5G', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('191', 'A70 OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('192', 'A70 COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('193', 'S9 PLUS OLED', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('194', 'S9 PLUS COPY', 'SAMSUNG', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('195', '3A OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('196', '3A COPY', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('197', '3A XL OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('198', '3A XL COPY', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('199', 'PIXEL 4', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('200', '4A OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('201', '4A COPY', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('202', '4A 5G OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('203', '4A 5G COPY', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('204', 'PIXEL 5 OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('205', 'PIXEL 5 COPY', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('206', 'PIXEL 6  OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('207', '6A  OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('208', '6PRO OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('209', '7PRO OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('210', 'PIXEL 8 OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('211', '8PRO OLED', 'PIXELS', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('212', 'Y9 PRIME 2019', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('213', 'Y9 2019', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('214', 'Y6 2019', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('215', 'OPPO A53', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('216', 'OPPO A17', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('217', 'OPPO A57', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('218', 'OPPO A31', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('219', 'PSMART 2019', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('220', 'OPPO F11', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('221', 'OPPO A83', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('222', 'HUAWEI P40 LITE', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('223', 'REDMI 13 C', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('224', 'REDMI14 C', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('225', 'NOTE 10 PRO', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('226', 'OPPO A3S', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('227', 'OPPO A54 4G', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('228', 'OPPO A57 NEW', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('229', 'NOVA 3I', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('230', 'XZ1', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('231', 'EXPERIA 10 II', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('232', 'EXPERIA 10 III', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('233', 'EXPERIA 10 IV', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('234', 'EXPERIA 10 MARK 5 II', 'HUAWEI & OPPO', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('235', 'REALME 7', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('236', 'C12', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('237', 'REDMI NOTE 12', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('238', 'REDMI NOTE 12 PRO', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('239', 'REDMI NOTE 9', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('240', 'REDMI NOT 8', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('241', 'NOKIA G10', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('242', 'NOKIA C10', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('243', 'NOKIA 1.4', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('244', 'NOKIA 3.4', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('245', 'NOKIA 2.3', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('246', 'REDMI  NOTE 10', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('247', 'REDMI 7', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('248', 'REDMI 10', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('249', 'REALME C55', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('250', 'REALME C30S', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0'),
('251', 'REALME C30', 'REALME & NOKIA', '3', '', '0.00', '2026-04-28 12:30:22', '0');

DROP TABLE IF EXISTS `purchase_levels`;
CREATE TABLE `purchase_levels` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `purchase_id` int(11) NOT NULL,
  `level_order` tinyint(4) NOT NULL,
  `level_name` varchar(100) NOT NULL,
  `qty_per_parent` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `purchase_id` (`purchase_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `purchases`;
CREATE TABLE `purchases` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `pieces_per_qty` int(11) DEFAULT 1,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `package_price` decimal(10,2) DEFAULT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_purchase_date` (`purchase_date`),
  CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`),
  CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `refunds`;
CREATE TABLE `refunds` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `sale_type` enum('bulk','retail','external') NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `back_to_stock` tinyint(1) NOT NULL DEFAULT 0,
  `refund_date` date NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `loss_amount` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `retail_stock`;
CREATE TABLE `retail_stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_quantity` int(11) NOT NULL DEFAULT 0,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `retail_stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sales_bulk`;
CREATE TABLE `sales_bulk` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `level_divisor` int(11) NOT NULL DEFAULT 1,
  `package_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `sold_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT 'Cash',
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `momo_amount` decimal(12,2) DEFAULT 0.00,
  `loan_amount` decimal(12,2) DEFAULT 0.00,
  `has_loan` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(12,2) DEFAULT 0.00,
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `sales_bulk_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sales_external`;
CREATE TABLE `sales_external` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) NOT NULL,
  `owner_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `unit_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cash_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `momo_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `customer_name` varchar(255) DEFAULT NULL,
  `sold_by` int(11) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `sale_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  `my_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `sales_retail`;
CREATE TABLE `sales_retail` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_sold` int(11) NOT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `sold_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT 'Cash',
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `momo_amount` decimal(12,2) DEFAULT 0.00,
  `loan_amount` decimal(12,2) DEFAULT 0.00,
  `has_loan` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(12,2) DEFAULT 0.00,
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `sales_retail_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `stock`;
CREATE TABLE `stock` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `pieces_per_package` int(11) DEFAULT 1,
  `package_price` decimal(10,2) DEFAULT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  KEY `idx_product_id` (`product_id`),
  CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `stock_movements`;
CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_moved` int(11) NOT NULL,
  `moved_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `product_id` (`product_id`),
  CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `stock_value_cache`;
CREATE TABLE `stock_value_cache` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `cost_wh` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cost_rt` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sell_wh` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sell_rt` decimal(15,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_svc_company` (`company_id`),
  KEY `idx_svc_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `suppliers`;
CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `user_permissions`;
CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_module` (`user_id`,`module`),
  KEY `idx_up_company` (`company_id`),
  CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=27 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `user_permissions` (`id`, `user_id`, `company_id`, `module`, `can_view`, `can_create`, `can_edit`, `can_delete`) VALUES
('1', '4', '1', 'inventory', '1', '1', '1', '0'),
('2', '4', '1', 'stock_adjust', '0', '0', '0', '0'),
('3', '4', '1', 'purchases', '1', '1', '1', '0'),
('4', '4', '1', 'sales', '1', '1', '1', '0'),
('5', '4', '1', 'expenses', '1', '1', '1', '0'),
('6', '4', '1', 'loans', '1', '1', '1', '0'),
('7', '4', '1', 'orders', '1', '1', '1', '0'),
('8', '4', '1', 'reports', '0', '0', '0', '0'),
('9', '4', '1', 'losses', '1', '1', '1', '0'),
('10', '4', '1', 'consumption', '0', '0', '1', '0'),
('11', '4', '1', 'notes', '1', '1', '1', '0'),
('12', '4', '1', 'audit_log', '0', '0', '0', '0'),
('13', '4', '1', 'financials', '0', '0', '0', '0');

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('superadmin','admin','manager','user') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','suspended','') NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `email` varchar(60) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=23 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `users` (`id`, `company_id`, `username`, `password`, `full_name`, `role`, `created_at`, `status`, `last_login`, `email`) VALUES
('1', '1', 'admin', '$2y$10$O.gB7jIXhrV2ukyPqdM5wO55h2knORR9RHUQE0lC4r5N14r9s2Lei', 'Admin', 'admin', '2026-02-11 17:05:51', 'active', '2026-06-28 10:13:58', 'admin@gmail.com'),
('3', NULL, 'askfor', '$2y$10$mDu5iII5oN2hVkGA.CX4NehgqxaLVEMJUBnEtHEATcjLpQa30vdcG', 'Gilbert niyonsaba', 'superadmin', '2026-06-01 12:31:50', 'active', '2026-06-02 14:31:37', 'askforgilbert@gmail.com'),
('4', '1', 'user', '$2y$10$8Oq7PnndUp6X0lAh1xy18.oijc4Z0NEZ8sPkOoaLnw4fgBkhmCPoO', 'muhire kevine', 'user', '2026-06-01 12:35:17', 'active', '2026-06-28 09:55:06', 'user@gmail.com'),
('5', NULL, 'superadmin', '$2y$10$.jJafyBL/kRUv1eQAomQQ.w5sLK2y.GZ4gsPDHfH2GqzAFPC.KsSW', 'Super Admin', 'superadmin', '2026-06-03 13:56:16', 'active', '2026-06-28 10:18:19', NULL);

DROP TABLE IF EXISTS `weekly_revenue`;
CREATE TABLE `weekly_revenue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `company_id` int(11) DEFAULT NULL,
  `week_start_date` date DEFAULT NULL,
  `week_end_date` date DEFAULT NULL,
  `bulk_sales_total` decimal(10,2) DEFAULT 0.00,
  `retail_sales_total` decimal(10,2) DEFAULT 0.00,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `total_cost` decimal(10,2) DEFAULT 0.00,
  `total_profit` decimal(10,2) DEFAULT 0.00,
  `profit_margin` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

DROP TABLE IF EXISTS `wishlist`;
CREATE TABLE `wishlist` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `product_name` varchar(70) NOT NULL,
  `client_count` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','purchased') NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `purchased_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `product_name` (`product_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
