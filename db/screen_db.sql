-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Aug 10, 2026 at 12:40 PM
-- Server version: 10.4.28-MariaDB-log
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `screen_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `audit_log`
--

CREATE TABLE `audit_log` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_values` text DEFAULT NULL,
  `new_values` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `boaster`
--

CREATE TABLE `boaster` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `giver` varchar(255) NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cache_meta`
--

CREATE TABLE `cache_meta` (
  `store_name` varchar(50) NOT NULL,
  `company_id` int(11) NOT NULL DEFAULT 0,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `cart_drafts`
--

CREATE TABLE `cart_drafts` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `draft_ref` varchar(64) NOT NULL,
  `sale_type` varchar(10) NOT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `items_count` int(11) NOT NULL DEFAULT 0,
  `total_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `draft_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`draft_json`)),
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `created_at`) VALUES
(1, 'BATTERY', '2026-07-04 21:40:57'),
(2, 'Pixel Clone', '2026-07-04 21:40:57'),
(3, 'Generic Android Clone', '2026-07-04 21:40:57'),
(4, 'Cimino Clone', '2026-07-04 21:40:57'),
(5, 'Samsung Galaxy Clone', '2026-07-04 21:40:57'),
(6, 'iPhone Clone', '2026-07-04 21:40:57'),
(7, 'Itel Clone', '2026-07-04 21:40:57'),
(8, 'OPPO Clone', '2026-07-04 21:40:57'),
(9, 'Realme Clone', '2026-07-04 21:40:57'),
(10, 'Huawei Clone', '2026-07-04 21:40:57'),
(11, 'Nokia Clone', '2026-07-04 21:40:57'),
(12, 'iPhone Speaker Flex', '2026-07-04 21:40:57'),
(13, 'Pixel OLED', '2026-07-04 21:40:57'),
(14, 'Samsung OLED', '2026-07-04 21:40:57'),
(15, 'Samsung Clone', '2026-07-04 21:40:57'),
(16, 'Blackview Clone', '2026-07-04 21:40:57'),
(18, 'Xiaomi Clone', '2026-07-04 21:40:57'),
(19, 'iPhone Door', '2026-07-04 21:40:57'),
(20, 'Samsung Door', '2026-07-04 21:40:57'),
(21, 'Generic Door', '2026-07-04 21:40:57'),
(22, 'Vivo Clone', '2026-07-04 21:40:57'),
(23, 'iPhone Charger Flex', '2026-07-04 21:40:57'),
(24, 'Home Button', '2026-07-04 21:40:57'),
(44, 'techon & infinix', '2026-07-04 22:14:39'),
(45, 'sonny pro', '2026-07-04 22:16:04'),
(53, 'Amasaka', '2026-08-05 09:54:45');

-- --------------------------------------------------------

--
-- Table structure for table `client_payments`
--

CREATE TABLE `client_payments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `client_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `payment_date` date NOT NULL,
  `recorded_by` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `companies`
--

CREATE TABLE `companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `companies`
--

INSERT INTO `companies` (`id`, `name`, `email`, `phone`, `address`, `status`, `created_at`) VALUES
(1, 'Test ltd', 'askforgilbert@gmail.com', '+250789047173', 'Remera', 'active', '2026-06-01 12:33:33'),
(2, 'Kwezera shop', 'kw@gmail.com', '0789475632', '', 'active', '2026-07-21 15:16:09'),
(3, 'UA&GN boutique', 'uagmail@gmail.com', '078346343', '', 'active', '2026-07-21 15:36:19'),
(4, 'TestOwnerCo', '', '', '', 'active', '2026-07-21 15:43:41');

-- --------------------------------------------------------

--
-- Table structure for table `company_settings`
--

CREATE TABLE `company_settings` (
  `company_id` int(11) NOT NULL,
  `enable_orders` tinyint(1) NOT NULL DEFAULT 1,
  `enable_exchange_rate` tinyint(1) NOT NULL DEFAULT 1,
  `enable_notifications` tinyint(1) NOT NULL DEFAULT 1,
  `enable_external_sale` tinyint(1) NOT NULL DEFAULT 1,
  `enable_ip` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumption`
--

CREATE TABLE `consumption` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `source` varchar(10) NOT NULL DEFAULT 'retail',
  `amount` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `done_by` varchar(100) DEFAULT NULL,
  `consumption_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `currency_rates`
--

CREATE TABLE `currency_rates` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `usd_rate` decimal(10,4) NOT NULL DEFAULT 1300.0000,
  `foreign_rate` decimal(10,4) NOT NULL DEFAULT 1.0000,
  `foreign_name` varchar(10) NOT NULL DEFAULT 'USD',
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `description` varchar(255) NOT NULL,
  `category` varchar(100) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `expense_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loans`
--

CREATE TABLE `loans` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `cart` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart`)),
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `client` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `loan_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `given_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `retail_id` int(11) DEFAULT NULL,
  `bulk_id` int(11) DEFAULT NULL,
  `external_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_clients`
--

CREATE TABLE `loan_clients` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_loans` int(11) NOT NULL DEFAULT 0,
  `paid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unpaid_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `payment_period_days` int(11) DEFAULT NULL,
  `growth_rate_percent` decimal(5,2) DEFAULT NULL,
  `zero_payment_floor` decimal(10,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payments`
--

CREATE TABLE `loan_payments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `loan_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_settings`
--

CREATE TABLE `loan_settings` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `payment_period_days` int(11) NOT NULL DEFAULT 7,
  `growth_rate_percent` decimal(5,2) NOT NULL DEFAULT 20.00,
  `zero_payment_floor` decimal(10,2) NOT NULL DEFAULT 0.00,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notes`
--

CREATE TABLE `notes` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `note` text NOT NULL,
  `is_pinned` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `order_number` varchar(20) DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `delivered_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `orders`
--

CREATE TABLE `orders` (
  `id` int(11) NOT NULL,
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
  `status` enum('new','open','pending','processing','completed','rejected','approved','cancelled','closed') NOT NULL DEFAULT 'pending',
  `status_before_close` varchar(20) DEFAULT NULL,
  `delivery_status` enum('placed','packed','ready','delivered','received') NOT NULL DEFAULT 'placed',
  `show_prices` tinyint(1) NOT NULL DEFAULT 1,
  `link_code` char(5) DEFAULT NULL,
  `link_expires_at` datetime DEFAULT NULL,
  `is_reusable` tinyint(1) NOT NULL DEFAULT 0,
  `source_order_id` int(11) DEFAULT NULL,
  `cancel_reason` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `in_charge_id` int(11) DEFAULT NULL,
  `approved_by` int(11) DEFAULT NULL,
  `cancelled_by` int(11) DEFAULT NULL,
  `sale_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_items`
--

CREATE TABLE `order_items` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `custom_name` varchar(150) DEFAULT NULL,
  `stock_source` enum('wh','rt','custom') NOT NULL DEFAULT 'wh',
  `status` enum('pending','fulfilled','out_of_stock') NOT NULL DEFAULT 'pending',
  `quantity` decimal(10,3) NOT NULL,
  `level_divisor` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `item_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `source` enum('staff','customer') NOT NULL DEFAULT 'staff',
  `added_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_owners`
--

CREATE TABLE `order_owners` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(50) NOT NULL DEFAULT '',
  `location` varchar(255) NOT NULL DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_payments`
--

CREATE TABLE `order_payments` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `order_id` int(11) NOT NULL,
  `cash` decimal(12,2) NOT NULL DEFAULT 0.00,
  `momo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `bank` decimal(12,2) NOT NULL DEFAULT 0.00,
  `loan` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `recorded_by` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `search_text` varchar(160) GENERATED ALWAYS AS (concat(`name`,' ',coalesce(`category`,''))) STORED,
  `reorder_level` int(11) DEFAULT 10,
  `unit_measure` varchar(20) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted` int(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category`, `category_id`, `reorder_level`, `unit_measure`, `unit_price`, `created_at`, `deleted`) VALUES
(1, '6G', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(2, '6S', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(3, '6P', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(4, '6SP', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(5, '7G', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(6, '7P', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(7, '8G', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(8, '8P', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(9, 'X', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(10, 'XS', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(11, 'XR', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(12, 'XSMAX', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(13, 'X11', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(14, 'X11 PRO', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(15, 'X11 PRO MAX', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(16, 'X12 PRO', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(17, 'X12 PRO MAX', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(18, 'X13', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(19, 'X13 PRO', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(20, 'X13 PRO max', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(21, 'X14 PRO max', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(22, 'X14 PRO', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(23, 'X15 PRO', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(24, 'X15 PRO MAX', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(25, 'X16', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(26, 'NOTE 8', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(27, 'S8', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(28, 'S9', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(29, 'S9+', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(30, 'S8+', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(31, 'A520', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(32, 'A03S', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(33, 'S22', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(34, 'S20', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(35, 'S21', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(36, 'PIXEL 3', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(37, 'PIXEL 4XL', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(38, 'PIXEL 3A', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(39, 'PIXEL 4', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(40, 'PIXEL 6', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(41, 'PIXEL 6A', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(42, 'PIXEL 4A', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(43, 'PIXEL 7a', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(44, 'PIXEL 7pro', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(45, 'PIXEL 6pro', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(46, 'PIXEL 5', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(47, 'XZ1', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(48, 'NOTE 20 ULT', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(49, 'NOTE 20', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(50, 'NOTE 10', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(51, 'A10', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(52, 'A10S', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(53, 'A50', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(54, 'A12', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(55, 'A13', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(56, 'A20', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(57, 'A02S', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(58, 'KD6', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(59, 'BG6', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(60, 'KC8', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(61, 'KA7', 'BATTERY', 1, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(62, 'PIXEL 3A COPY', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(63, 'PIXEL 3AXL COPY', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(64, 'X6516', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(65, 'X653', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(66, 'KC8', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(67, 'X657', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(68, 'BG6 M', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(69, 'BG6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(70, 'KD7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(71, 'X6511', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(72, 'BD4', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(73, 'BD3', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(74, 'X688', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(75, 'KD6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(76, 'BE8', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(77, 'X CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(78, 'XS CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(79, 'XR CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(80, 'X11 CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(81, 'XSMAX CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(82, 'X11PRO MAX CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(83, 'X12PRO CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(84, 'X12PRO MAX CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(85, 'X13 CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(86, 'X13 pro', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(87, 'X13 pro max CIMINO', 'Cimino Clone', 4, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(88, 'X663', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(89, 'KC6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(90, 'X689', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(91, 'CE7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(92, 'A23', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(93, 'A12', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(94, 'SMS A05', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(95, 'A10E', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(96, 'IPHONE 8p b', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(97, 'IPHONE 8G B*W', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(98, 'IPHONE 7P B*W', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(99, '6G B', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(100, '7G B', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(101, '6S B', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(102, '6SP B', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(103, '6P B', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(104, 'A20S', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(105, 'SMS S10 COPY', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(106, 'SMS S10 + COPY', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(107, 'SMS S10E COPY', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(108, 'KL4', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(109, 'X6725', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(110, 'A11', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(111, 'A01CORE', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(112, 'A21S', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(113, 'A03CORE', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(114, 'CH6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(115, 'CH9', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(116, 'AITEL A04', 'Itel Clone', 7, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(117, 'BE6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(118, 'BE7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(119, 'BC3', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(120, 'CI6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(121, 'A06 UK', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(122, 'BA2', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(123, 'LA7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(124, 'CK7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(125, 'KA7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(126, 'X680', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(127, 'X690', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(128, 'CE9', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(129, 'KG7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(130, 'X693', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(131, 'OPPO A17', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(132, 'KI7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(133, 'KB7', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(134, 'X626', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(135, 'X606', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(136, 'LC6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(137, 'OPPO A5S', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(138, 'OPPO A3S', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(139, 'SMS A04', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(140, 'SMS J530 COPY', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(141, 'KG5 K', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(142, 'A05', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(143, 'B1C', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(144, 'CD8', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(145, 'Aitel A50C', 'Itel Clone', 7, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(146, 'KB8', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(147, 'BG6M', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(148, 'BF6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(149, 'A20 COPY FLEM', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(150, 'A50 COPY FLEM', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(151, 'A16 5G FLEM', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(152, 'A16 4G FLEM', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(153, 'A31 COPY', 'Samsung Galaxy Clone', 5, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(154, 'realme c11 2021', 'Realme Clone', 9, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(155, 'psmart 2021', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(156, 'psmart 2019', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(157, 'Nokia C1', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(158, 'x', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(159, 'x11', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(160, 'xr', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(161, 'x12', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(162, 'x12 PM', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(163, 'x11 PM', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(164, 'x11 Pro', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(165, 'x13', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(166, 'x13 Pr0', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(167, 'x13 PM', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(168, 'x14', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(169, 'x14 Pro', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(170, 'x14 PM', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(171, 'x15', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(172, 'x15 Pro', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(173, 'x15 PM', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(174, '7P', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(175, '7G', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(176, '8P', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(177, '8G', 'iPhone Speaker Flex', 12, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(178, 'pixel 3a oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(179, 'pixel PIXEL 4 oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(180, 'pixel PIXEL 4 copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(181, 'pixel 4A 5G oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(182, 'pixel 4A 5G COPY', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(183, 'PIXEL 4A 4G copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(184, 'PIXEL 6A OLED', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(185, 'PIXEL 6PRO COPY', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(186, 'PIXEL 6A copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(187, 'PIXEL 6 OLED', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(188, 'PIXEL 6 copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(189, 'PIXEL 8 oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(190, 'PIXEL 7 COPY', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(191, '3axl oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(192, 'X(JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(193, 'X11 JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(194, 'X11 GX', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(195, 'XR(JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(196, 'XR(GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(197, 'XS MAX(JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(198, 'X11 PRO(JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(199, 'X11 PRO(GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(200, 'X11 PRO MAX (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(201, 'X11 PRO MAX (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(202, 'X12 PRO (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(203, 'X12 PRO (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(204, 'X12 MIN (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(205, 'X12 PRO MAX JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(206, 'X12 PRO MAX GX', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(207, 'X12 PRO MAX DD', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(208, 'X13 (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(209, 'X13MIN (GH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(210, 'X13 DD', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(211, 'X13 PRO (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(212, 'X13 PRO (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(213, 'X13 PRO MAX (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(214, 'X13 PRO MAX (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(215, 'X13 PRO MAX (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(216, 'X14 PLUS (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(217, 'X14PRO MAX (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(218, 'X14PRO MAX (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(219, 'X14PRO MAX (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(220, 'X14PRO (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(221, 'X14PRO (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(222, 'X15 PRO (JH)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(223, 'X15 PRO (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(224, 'X15 PRO (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(225, 'X15 PRO MAX (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(226, 'X16 PRO MAX (GX)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(227, 'X17 PRO MAX (DD)', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(228, 'S23 ULT OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(229, 'S22 ULT OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(230, 'S21 ULT', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(231, 'NOTE 10 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(232, 'NOTE 20 ULT OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(233, 'S9+ OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(234, 'S9 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(235, 'S9+ COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(236, 'S8+ COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(237, 'S8 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(238, 'S20 PLUS OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(239, 'S20 ULT OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(240, 'A50 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(241, 'A50 OLED FLEM', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(242, 'A31 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(243, 'A51 OLED FLEM', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(244, 'A51 NON FLEM OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(245, 'A51 5G OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(246, 'A51 5G COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(247, 'A51 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(248, 'A31 OLED FLEM', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(249, 'A32 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(250, 'A22 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(251, 'A20 COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(252, 'A20 OLED FLEM', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(253, 'A32 Oled FLEM', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(254, 'A146P 5G UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(255, 'A146B 5G UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(256, 'A146U 5G UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(257, 'A22 5G UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(258, 'C11 2020', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(259, 'A037U SINGLE FLEX UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(260, 'A03S UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(261, 'A05 UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(262, 'A06 UK', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(263, 'A52 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(264, 'A33 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(265, 'A33 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(266, 'A73 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(267, 'A72 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(268, 'A15 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(269, 'A16 4G FLEM OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(270, 'A16 4G NOT FLEM OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(271, 'A15 FLEM OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(272, 'A42 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(273, 'A42 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(274, 'J810 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(275, 'J615', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(276, 'A22 4G COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(277, 'A70 oled', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(278, 'XZ1', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(279, 'A30S COPY FLEM', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(280, 'A750 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(281, 'X665', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(282, 'BLACK VIEW 4900', 'Blackview Clone', 16, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(283, 'A530 OLED', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(284, 'BD2', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(285, 'SY XPERIA 10 II COPY', 'sonny pro', 45, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(286, 'SY XPERIA 10 IV COPY', 'sonny pro', 45, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(287, 'SY EXPERIA 10 III COPY', 'sonny pro', 45, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(288, 'pixel 5 copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(289, 'pixel 4a copy', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(290, 'pixel 3a OG', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(291, 'PIXEL 6', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(292, 'PIXEL 6PRO', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(293, 'X JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(294, 'X13 PM JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(295, 'X14 PM JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(296, 'X14 PM GX', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(297, 'XR GX', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(298, 'pixel 7 oled', 'Pixel OLED', 13, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(299, 'BC2', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(300, 'AO4', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(301, 'A04S', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(302, 'A02S', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(303, 'A06', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(304, 'J530 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(305, 'J730 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(306, 'J600 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(307, 'J600 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(308, 'REALME C53', 'Realme Clone', 9, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(309, 'REDME 12 5G', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(310, 'REDME 11 2020', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:24', 0),
(311, 'NK 1.4', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(312, 'NK G10', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(313, 'NK C1', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(314, 'OPPO A1K', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(315, 'Y9 PRIME', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(316, 'P20 LITE', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(317, 'P30 pro', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(318, 'P40 LITE', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(319, 'CG8', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(320, 'CG6', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(321, 'C30S', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(322, 'OPPO A32 2020', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(323, 'RDM NOTE 7', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(324, 'M30 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(325, 'M30 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(326, 'NK C20', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(327, '0PP0 A55S', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(328, 'RDM NOTE 11', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(329, 'RDM NOTE10 PRO', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(330, 'XIAOMI12', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(331, 'P30', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(332, 'A720 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(333, 'A720 COPY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(334, 'A25 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(335, 'A25 C0PY', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(336, 'X13 PM GX', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(337, 'X12 PM JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(338, 'X12 PRO JH', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(339, 'A50 ORD', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(340, 'X11 PRO', 'iPhone Clone', 6, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(341, 'A03S', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(342, 'PIXEL 3AXL', 'Pixel Clone', 2, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(343, 'X Golde', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(344, 'X Black', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(345, 'X white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(346, 'X Gold', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(347, 'XR BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(348, 'XR white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(349, 'XR sky blue', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(350, 'XR red', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(351, 'XR CORAL', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(352, 'xs max Golde', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(353, 'xs max black', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(354, 'xs max white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(355, 'Note 9 Black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(356, 'Note 9 blue', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(357, 'Note 9 silver', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(358, 'Note 8 Black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(359, 'Note 8 blue', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(360, 'Note 20 black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(361, 'Note 20 blue', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(362, 'Note 20 Golde', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(363, 'S20 4G Black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(364, 'S20 4G WHITE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(365, 'S9 Black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(366, 'S9 violet', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(367, 'S9 Blue', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(368, 'S8 black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(369, 'S8 golde', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(370, 'S9 PLUS BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(371, 'S9 PLUS Blue sky', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(372, 'S9 PLUS Violete', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(373, 'S8 Plus Black', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(374, 'S8 Plus Golde', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(375, '8G Black', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(376, '8G white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(377, '8P Black', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(378, '8P white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(379, '8P RED', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(380, 'XR yellow', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(381, 'x11 white', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(382, 'x11 black', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(383, 'x11 green', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(384, 'x11 PULPLE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(385, 'X11 PRO GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(386, 'X11 PRO BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(387, 'X11 PRO WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(388, 'X11 PRO Green', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(389, 'X11 PM GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(390, 'X11 PM BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(391, 'X11 PM GREEN', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(392, 'X12 BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(393, 'X12 BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(394, 'X12 WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(395, 'X12 GREEN', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(396, 'X12 RED', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(397, 'X12 PRO BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(398, 'X12 PRO WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(399, 'X12 PRO BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(400, 'X12 PRO GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(401, 'X12 PM GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(402, 'X12 PM BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(403, 'X12 PM BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(404, 'X13 BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(405, 'X13 BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(406, 'X13 WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(407, 'X13 red', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(408, 'X13 PRO WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(409, 'X13 PRO BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(410, 'X13 PRO GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(411, 'X13 PRO BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(412, 'X13 PM BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(413, 'X13 PM GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(414, 'X13 PM WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(415, 'X14 PM PUPLE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(416, 'X14 PM BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(417, 'X14 PM GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(418, 'X14 PRO BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(419, 'X14 PRO WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(420, 'X14 PRO GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(421, 'X14 PRO BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(422, 'S21 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(423, 'S21 ULT SILVA', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(424, 'S21 ULT GREY', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(425, 'NOTE 20 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(426, 'NOTE 20 ULT GOLD', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(427, 'NOTE 20 ULT BLUE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(428, 'S8 GOLD', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(429, 'S9 GOLD', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(430, 'A530 BLACK', 'Generic Door', 21, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(431, 'A530 GOLD', 'Generic Door', 21, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(432, 'NOTE 10 + BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(433, 'NOTE 10 + BLUE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(434, 'NOTE 10 BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(435, 'NOTE 10 BLUE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(436, 'NOTE 8 GOLD', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(437, 'S22 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(438, 'S22 ULT GREY', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(439, 'S22 ULT WHITE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(440, 'S23 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(441, 'S23 ULT WHITE', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(442, 'S24 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(443, 'S20 ULT BLACK', 'Samsung Door', 20, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(444, 'X15 PRO BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(445, 'X15 PRO WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(446, 'X15 PRO BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(447, 'X15 PRO GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(448, 'X15 PRO MAX BLACK', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(449, 'X15 PRO MAX WHITE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(450, 'X15 PRO MAX BLUE', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(451, 'X15 PRO MAX GOLD', 'iPhone Door', 19, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(452, 'AITEL A50', 'Itel Clone', 7, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(453, 'aitel S23', 'Samsung Clone', 15, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(454, 'OPPOA73', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(455, 'SMS A90 OLED', 'Samsung OLED', 14, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(456, 'REDME A3s', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(457, 'Y9 PRIME 2019', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(458, 'REDME 10 4G', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(459, 'c11 2021', 'Realme Clone', 9, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(460, 'CH9 COPY', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(461, 'OPP A1+', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(462, 'KJ5', 'Generic Android Clone', 3, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(463, 'RDM NOTE 8', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(464, 'RDM A2+', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(465, 'OPPO A56 5G', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(466, 'OPPO A54', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(467, 'RDM 14C', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(468, 'MT 20 LITE', 'Huawei Clone', 10, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(469, 'C53 2023', 'Realme Clone', 9, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(470, 'RDM NOT 10 5G', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(471, 'VIVO 35', 'Vivo Clone', 22, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(472, 'OPPO A96', 'OPPO Clone', 8, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(473, 'VIVO Y71', 'Vivo Clone', 22, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(474, 'RDM 9', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(475, 'RDM 9T', 'Xiaomi Clone', 18, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(476, 'NK 2.4', 'Nokia Clone', 11, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(477, 'x', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(478, 'xs', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(479, 'xr', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(480, 'x11', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(481, 'x11 Pro', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(482, 'x12 PM', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(483, 'x13 PM', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(484, '7P', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(485, '7G', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(486, '8G', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(487, '8P', 'iPhone Charger Flex', 23, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(488, '8P', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(489, '7P', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(490, '7G', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(491, '8G', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(492, '6S', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(493, '6G', 'Home Button', 24, 3, 'Box', 1.00, '2026-06-29 09:30:25', 0),
(494, 'PM 5', 'techon & infinix', 44, 10, 'box', 0.00, '2026-06-30 07:00:24', 0),
(495, 'PM 1', 'techon & infinix', 44, 10, 'box', 0.00, '2026-06-30 07:00:39', 0),
(499, 'Umutuku', 'Amasaka', 53, 10, 'box', 0.00, '2026-08-05 09:54:45', 0);

-- --------------------------------------------------------

--
-- Table structure for table `product_owners`
--

CREATE TABLE `product_owners` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(90) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `pieces_per_qty` int(11) DEFAULT 1,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `package_price` decimal(10,2) DEFAULT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `purchase_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_levels`
--

CREATE TABLE `purchase_levels` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `purchase_id` int(11) NOT NULL,
  `level_order` tinyint(4) NOT NULL,
  `level_name` varchar(100) NOT NULL,
  `qty_per_parent` int(11) NOT NULL DEFAULT 1,
  `selling_price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `refunds`
--

CREATE TABLE `refunds` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `sale_type` enum('bulk','retail','external') NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `quantity` decimal(10,1) NOT NULL DEFAULT 0.0,
  `refund_amount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `reason` varchar(255) DEFAULT NULL,
  `back_to_stock` tinyint(1) NOT NULL DEFAULT 0,
  `refund_date` date NOT NULL,
  `processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `loss_amount` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `retail_stock`
--

CREATE TABLE `retail_stock` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_quantity` decimal(10,1) NOT NULL DEFAULT 0.0,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_bulk`
--

CREATE TABLE `sales_bulk` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,1) NOT NULL,
  `level_divisor` int(11) NOT NULL DEFAULT 1,
  `package_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_id` int(11) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `sold_by` int(11) DEFAULT NULL,
  `client_ref` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT 'Cash',
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `momo_amount` decimal(12,2) DEFAULT 0.00,
  `loan_amount` decimal(12,2) DEFAULT 0.00,
  `has_loan` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(12,2) DEFAULT 0.00,
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_external`
--

CREATE TABLE `sales_external` (
  `id` int(11) NOT NULL,
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
  `client_ref` varchar(64) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `sale_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  `my_revenue` decimal(12,2) NOT NULL DEFAULT 0.00,
  `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_retail`
--

CREATE TABLE `sales_retail` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_sold` decimal(10,1) NOT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `cost_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `purchase_id` int(11) DEFAULT NULL,
  `sale_date` date DEFAULT NULL,
  `customer_name` varchar(100) DEFAULT NULL,
  `sold_by` int(11) DEFAULT NULL,
  `client_ref` varchar(64) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(20) DEFAULT 'Cash',
  `cash_amount` decimal(12,2) DEFAULT 0.00,
  `momo_amount` decimal(12,2) DEFAULT 0.00,
  `loan_amount` decimal(12,2) DEFAULT 0.00,
  `has_loan` tinyint(1) NOT NULL DEFAULT 0,
  `amount` decimal(12,2) DEFAULT 0.00,
  `refunded` tinyint(1) NOT NULL DEFAULT 0,
  `cart_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`cart_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` decimal(10,1) NOT NULL,
  `pieces_per_package` int(11) DEFAULT 1,
  `package_price` decimal(10,2) DEFAULT NULL,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_movements`
--

CREATE TABLE `stock_movements` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_moved` decimal(10,1) NOT NULL,
  `moved_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock_value_cache`
--

CREATE TABLE `stock_value_cache` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `product_id` int(11) NOT NULL,
  `cost_wh` decimal(15,2) NOT NULL DEFAULT 0.00,
  `cost_rt` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sell_wh` decimal(15,2) NOT NULL DEFAULT 0.00,
  `sell_rt` decimal(15,2) NOT NULL DEFAULT 0.00,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('superadmin','admin','manager','user') NOT NULL DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','suspended','') NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `email` varchar(60) DEFAULT NULL,
  `language` enum('en','rw','fr') NOT NULL DEFAULT 'en'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `company_id`, `username`, `password`, `full_name`, `role`, `created_at`, `status`, `last_login`, `email`) VALUES
(1, NULL, 'superadmin', '$2y$10$yVFwqR1YQqVbPn6VEZQLXOJQSQUMKFz0t22oGOH9yT/FyFaqiZ66e', 'Super Admin', 'superadmin', '2026-06-29 07:42:11', 'active', '2026-08-10 12:25:11', NULL),
(2, 1, 'company1', '$2y$10$CCqcFhluWY3I7rDhpPF/uOArigViQ7JgnK62JjOE09czO/YegJITO', 'Company Admin', 'admin', '2026-06-29 10:29:31', 'active', '2026-08-10 12:35:31', 'admin@gmail.com'),
(7, 1, 'muhozi', '$2y$10$yVFwqR1YQqVbPn6VEZQLXOJQSQUMKFz0t22oGOH9yT/FyFaqiZ66e', 'muhozi beatrice', 'user', '2026-07-02 10:49:13', 'active', NULL, 'muhozi@gmail.com'),
(17, 3, 'company2', '$2y$10$j5c5mGZbXC51Alhgll7rr.SIv31VQzqXDQnPdOgi.xf6pBjGASQbO', 'olive', 'admin', '2026-07-21 13:36:19', 'active', '2026-07-23 14:00:29', 'olive@gmail.com'),
(18, 4, 'company3', '$2y$10$q6qzWHLu.JwAo/LXqYBk4.wO2mPb1KpRQSzwIq9WVhaJUJE8rS9XW', 'Test Owner', 'admin', '2026-07-21 13:43:41', 'active', '2026-08-07 08:57:06', 'testowner1@example.com'),
(19, 3, 'manzi', '$2y$10$MPnJOFHSki7DNH5dz/UvT.B14i/PXYjHqFg.YKwI12sxnhDvTJHj.', 'manzi doe', 'user', '2026-07-21 13:48:46', 'active', '2026-07-21 16:14:11', 'manzi@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `user_company_access`
--

CREATE TABLE `user_company_access` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `granted_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_company_access`
--

INSERT INTO `user_company_access` (`id`, `user_id`, `company_id`, `granted_by`, `created_at`) VALUES
(2, 17, 2, 1, '2026-07-21 13:48:02'),
(3, 2, 1, NULL, '2026-07-21 14:22:45'),
(4, 7, 1, NULL, '2026-07-21 14:22:45'),
(5, 17, 3, NULL, '2026-07-21 14:22:45'),
(6, 18, 4, NULL, '2026-07-21 14:22:45'),
(7, 19, 3, NULL, '2026-07-21 14:22:45');

-- --------------------------------------------------------

--
-- Table structure for table `user_permissions`
--

CREATE TABLE `user_permissions` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `module` varchar(50) NOT NULL,
  `can_view` tinyint(1) NOT NULL DEFAULT 0,
  `can_create` tinyint(1) NOT NULL DEFAULT 0,
  `can_edit` tinyint(1) NOT NULL DEFAULT 0,
  `can_delete` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_permissions`
--

INSERT INTO `user_permissions` (`id`, `user_id`, `company_id`, `module`, `can_view`, `can_create`, `can_edit`, `can_delete`) VALUES
(1, 7, 1, 'inventory', 1, 1, 1, 0),
(2, 7, 1, 'stock_adjust', 1, 1, 1, 0),
(3, 7, 1, 'purchases', 1, 1, 1, 0),
(4, 7, 1, 'sales', 1, 1, 1, 0),
(5, 7, 1, 'expenses', 1, 1, 1, 0),
(6, 7, 1, 'loans', 1, 1, 1, 0),
(7, 7, 1, 'orders', 1, 1, 1, 0),
(8, 7, 1, 'reports', 1, 0, 0, 0),
(9, 7, 1, 'losses', 1, 1, 1, 0),
(10, 7, 1, 'consumption', 1, 1, 1, 0),
(11, 7, 1, 'notes', 1, 1, 1, 0),
(12, 7, 1, 'audit_log', 1, 0, 0, 0),
(13, 7, 1, 'financials', 1, 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `weekly_revenue`
--

CREATE TABLE `weekly_revenue` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `week_start_date` date DEFAULT NULL,
  `week_end_date` date DEFAULT NULL,
  `bulk_sales_total` decimal(10,2) DEFAULT 0.00,
  `retail_sales_total` decimal(10,2) DEFAULT 0.00,
  `total_revenue` decimal(10,2) DEFAULT 0.00,
  `total_cost` decimal(10,2) DEFAULT 0.00,
  `total_profit` decimal(10,2) DEFAULT 0.00,
  `profit_margin` decimal(5,2) DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `wishlist`
--

CREATE TABLE `wishlist` (
  `id` int(11) NOT NULL,
  `product_name` varchar(70) NOT NULL,
  `client_count` int(11) NOT NULL DEFAULT 1,
  `status` enum('pending','purchased') NOT NULL DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp(),
  `purchased_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `audit_log`
--
ALTER TABLE `audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_company` (`company_id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_table` (`table_name`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `boaster`
--
ALTER TABLE `boaster`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cache_meta`
--
ALTER TABLE `cache_meta`
  ADD PRIMARY KEY (`store_name`,`company_id`);

--
-- Indexes for table `cart_drafts`
--
ALTER TABLE `cart_drafts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_cart_drafts_ref` (`draft_ref`),
  ADD KEY `idx_cart_drafts_company_type` (`company_id`,`sale_type`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_categories_name` (`name`);

--
-- Indexes for table `client_payments`
--
ALTER TABLE `client_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_cp_client` (`client_id`),
  ADD KEY `idx_cp_company` (`company_id`),
  ADD KEY `idx_cp_date` (`payment_date`);

--
-- Indexes for table `companies`
--
ALTER TABLE `companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `company_settings`
--
ALTER TABLE `company_settings`
  ADD PRIMARY KEY (`company_id`);

--
-- Indexes for table `consumption`
--
ALTER TABLE `consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_consumption_date` (`consumption_date`);

--
-- Indexes for table `currency_rates`
--
ALTER TABLE `currency_rates`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_cr_company` (`company_id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_expense_date` (`expense_date`);

--
-- Indexes for table `loans`
--
ALTER TABLE `loans`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_loan_date` (`loan_date`),
  ADD KEY `idx_client` (`client`),
  ADD KEY `idx_loans_client_id` (`client_id`);

--
-- Indexes for table `loan_clients`
--
ALTER TABLE `loan_clients`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_name_phone` (`name`,`phone`);

--
-- Indexes for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_id` (`loan_id`);

--
-- Indexes for table `loan_settings`
--
ALTER TABLE `loan_settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_loan_settings_company` (`company_id`);

--
-- Indexes for table `notes`
--
ALTER TABLE `notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notes_company` (`company_id`),
  ADD KEY `idx_notes_user` (`user_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notif_user` (`user_id`);

--
-- Indexes for table `orders`
--
ALTER TABLE `orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_orders_company` (`company_id`),
  ADD KEY `idx_orders_status` (`status`),
  ADD KEY `idx_orders_created_at` (`created_at`),
  ADD KEY `idx_orders_status_date` (`status`,`created_at`),
  ADD KEY `idx_orders_order_owner_id` (`order_owner_id`),
  ADD KEY `idx_orders_created_by` (`created_by`),
  ADD KEY `idx_orders_approved_by` (`approved_by`),
  ADD KEY `idx_orders_order_number` (`order_number`),
  ADD KEY `idx_orders_link_code` (`link_code`),
  ADD KEY `idx_orders_source_order` (`source_order_id`),
  ADD KEY `idx_orders_in_charge` (`in_charge_id`);

--
-- Indexes for table `order_items`
--
ALTER TABLE `order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_oi_order` (`order_id`),
  ADD KEY `idx_oi_product` (`product_id`);

--
-- Indexes for table `order_owners`
--
ALTER TABLE `order_owners`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_owner_name` (`name`),
  ADD KEY `idx_order_owners_company` (`company_id`);

--
-- Indexes for table `order_payments`
--
ALTER TABLE `order_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_op_order` (`order_id`),
  ADD KEY `idx_op_company` (`company_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted` (`deleted`),
  ADD KEY `idx_name` (`name`),
  ADD KEY `idx_products_category_id` (`category_id`);
ALTER TABLE `products` ADD FULLTEXT KEY `ftx_products_search_text` (`search_text`);

--
-- Indexes for table `product_owners`
--
ALTER TABLE `product_owners`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_owner_name_phone` (`name`,`phone`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_purchase_date` (`purchase_date`);

--
-- Indexes for table `purchase_levels`
--
ALTER TABLE `purchase_levels`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`);

--
-- Indexes for table `refunds`
--
ALTER TABLE `refunds`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `retail_stock`
--
ALTER TABLE `retail_stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `sales_bulk`
--
ALTER TABLE `sales_bulk`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sb_client_ref` (`company_id`,`client_ref`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sale_date` (`sale_date`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_sb_purchase_id` (`purchase_id`);

--
-- Indexes for table `sales_external`
--
ALTER TABLE `sales_external`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_se_client_ref` (`company_id`,`client_ref`);

--
-- Indexes for table `sales_retail`
--
ALTER TABLE `sales_retail`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_sr_client_ref` (`company_id`,`client_ref`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sale_date` (`sale_date`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_sr_purchase_id` (`purchase_id`);

--
-- Indexes for table `stock`
--
ALTER TABLE `stock`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `stock_value_cache`
--
ALTER TABLE `stock_value_cache`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_product` (`product_id`,`company_id`),
  ADD KEY `idx_svc_company` (`company_id`),
  ADD KEY `idx_svc_product` (`product_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `user_company_access`
--
ALTER TABLE `user_company_access`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_uca_user_company` (`user_id`,`company_id`),
  ADD KEY `idx_uca_company` (`company_id`);

--
-- Indexes for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_module` (`user_id`,`module`),
  ADD KEY `idx_up_company` (`company_id`);

--
-- Indexes for table `weekly_revenue`
--
ALTER TABLE `weekly_revenue`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `wishlist`
--
ALTER TABLE `wishlist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_name` (`product_name`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `audit_log`
--
ALTER TABLE `audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `boaster`
--
ALTER TABLE `boaster`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `cart_drafts`
--
ALTER TABLE `cart_drafts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=54;

--
-- AUTO_INCREMENT for table `client_payments`
--
ALTER TABLE `client_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `companies`
--
ALTER TABLE `companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `consumption`
--
ALTER TABLE `consumption`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `currency_rates`
--
ALTER TABLE `currency_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loans`
--
ALTER TABLE `loans`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_clients`
--
ALTER TABLE `loan_clients`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_payments`
--
ALTER TABLE `loan_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `loan_settings`
--
ALTER TABLE `loan_settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notes`
--
ALTER TABLE `notes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `orders`
--
ALTER TABLE `orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_items`
--
ALTER TABLE `order_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_owners`
--
ALTER TABLE `order_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_payments`
--
ALTER TABLE `order_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=500;

--
-- AUTO_INCREMENT for table `product_owners`
--
ALTER TABLE `product_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_levels`
--
ALTER TABLE `purchase_levels`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `refunds`
--
ALTER TABLE `refunds`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `retail_stock`
--
ALTER TABLE `retail_stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_bulk`
--
ALTER TABLE `sales_bulk`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_external`
--
ALTER TABLE `sales_external`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_retail`
--
ALTER TABLE `sales_retail`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock`
--
ALTER TABLE `stock`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_movements`
--
ALTER TABLE `stock_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_value_cache`
--
ALTER TABLE `stock_value_cache`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `user_company_access`
--
ALTER TABLE `user_company_access`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `user_permissions`
--
ALTER TABLE `user_permissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `weekly_revenue`
--
ALTER TABLE `weekly_revenue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `wishlist`
--
ALTER TABLE `wishlist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `client_payments`
--
ALTER TABLE `client_payments`
  ADD CONSTRAINT `client_payments_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `loan_clients` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `consumption`
--
ALTER TABLE `consumption`
  ADD CONSTRAINT `consumption_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `loans`
--
ALTER TABLE `loans`
  ADD CONSTRAINT `loans_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD CONSTRAINT `loan_payments_ibfk_1` FOREIGN KEY (`loan_id`) REFERENCES `loans` (`id`);

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `retail_stock`
--
ALTER TABLE `retail_stock`
  ADD CONSTRAINT `retail_stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `sales_bulk`
--
ALTER TABLE `sales_bulk`
  ADD CONSTRAINT `sales_bulk_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `sales_retail`
--
ALTER TABLE `sales_retail`
  ADD CONSTRAINT `sales_retail_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `stock`
--
ALTER TABLE `stock`
  ADD CONSTRAINT `stock_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `stock_movements`
--
ALTER TABLE `stock_movements`
  ADD CONSTRAINT `stock_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`);

--
-- Constraints for table `user_company_access`
--
ALTER TABLE `user_company_access`
  ADD CONSTRAINT `user_company_access_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_permissions`
--
ALTER TABLE `user_permissions`
  ADD CONSTRAINT `user_permissions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
