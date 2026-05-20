-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: May 03, 2026 at 04:51 PM
-- Server version: 10.4.28-MariaDB
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
-- Table structure for table `boaster`
--

CREATE TABLE `boaster` (
  `id` int(11) NOT NULL,
  `giver` varchar(255) NOT NULL,
  `amount` decimal(12,0) NOT NULL,
  `date` date NOT NULL,
  `description` text DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `consumption`
--

CREATE TABLE `consumption` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) DEFAULT 0.00,
  `paid_amount` decimal(10,2) DEFAULT 0.00,
  `done_by` varchar(100) DEFAULT NULL,
  `consumption_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
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
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(255) DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 1,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `client` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `loan_date` date NOT NULL,
  `given_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `retail_id` int(11) DEFAULT NULL,
  `bulk_id` int(11) DEFAULT NULL,
  `external_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `loan_payments`
--

CREATE TABLE `loan_payments` (
  `id` int(11) NOT NULL,
  `loan_id` int(11) NOT NULL,
  `amount_paid` decimal(10,2) NOT NULL DEFAULT 0.00,
  `payment_date` date NOT NULL,
  `received_by` int(11) DEFAULT NULL,
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
  `reorder_level` int(11) DEFAULT 10,
  `unit_measure` varchar(20) DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `deleted` int(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `category`, `reorder_level`, `unit_measure`, `unit_price`, `created_at`, `deleted`) VALUES
(1, 'KA7', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(2, 'KB7', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(3, 'KB8', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(4, 'KC8', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(5, 'KD7', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(6, 'X657', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(7, 'X688', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(8, 'X689', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(9, 'BG6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(10, 'BG6M', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(11, 'BD3', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(12, 'X612', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(13, 'X6816', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(14, 'CH6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(15, 'BE8', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(16, 'CG6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(17, 'KI7', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(18, 'X6835', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(19, 'BV', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(20, 'X6511', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(21, 'CK6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(22, 'KL4', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(23, 'CL6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(24, 'CM5', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(25, 'BF6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(26, 'CC9 COPY', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(27, 'CE9', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(28, 'BC2', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(29, 'X6725', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(30, 'KD6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(31, 'X680', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(32, 'X663', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(33, 'ITEL A50', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(34, 'ITE A50C', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(35, 'ITEL A58', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(36, 'KJ6', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(37, 'X626', 'TECNO & INFINIX', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(38, '6S', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(39, '7G', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(40, '7PUS', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(41, '8G', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(42, '8PUS', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(43, 'X JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(44, 'XGX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(45, 'X CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(46, 'XS JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(47, 'XS GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(48, 'XS CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(49, 'XS MAX JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(50, 'XS MAX GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(51, 'XS MAX CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(52, 'XR JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(53, 'XR GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(54, 'XR CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(55, 'X11 JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(56, 'X11 GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(57, 'X11 CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(58, 'X12 JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(59, 'X12 GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(60, 'X12 CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(61, '12 PRO MAX JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(62, '12 PRO MAX GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(63, '12 PRO MAX CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(64, '13 JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(65, '13 GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(66, '13 CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(67, '13 PRO JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(68, '13 PRO GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(69, '13 PRO CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(70, '13 PRO DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(71, '13 PRO MAX JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(72, '13 PRO MAX GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(73, '13 PROMAX CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(74, '13 PRO MAX DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(75, '14 JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(76, '14 PRO JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(77, '14 PRO GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(78, '14 PRO DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(79, '14 PRO CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(80, '14 PRO MAX JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(81, '14 PRO MAX GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(82, '14 PRO MAX CIMINO', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(83, '14 PRO MAX DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(84, 'X15 JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(85, '15 PRO GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(86, '15 PRO DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(87, '15 PRO JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(88, '16 PRO JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(89, '16 PRO DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(90, '16 PRO MAX JH', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(91, '16 PRO MAX GX', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(92, '17 PRO MAX DD', 'IPHONES', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(93, 'A03', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(94, 'A04', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(95, 'A04S', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(96, 'A12', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(97, 'A13', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(98, 'A14 4G', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(99, 'A14 5G', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(100, 'A15 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(101, 'A15 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(102, 'A16 4G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(103, 'A16 OLED 4G COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(104, 'A16  5G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(105, 'A16 5G COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(106, 'A05', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(107, 'A05S', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(108, 'A06', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(109, 'A530 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(110, 'A530 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(111, 'A20 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(112, 'A20 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(113, 'A30 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(114, 'A30 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(115, 'A31 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(116, 'A31 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(117, 'A30S OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(118, 'A30S COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(119, 'M30 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(120, 'M30 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(121, 'M32 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(122, 'A520 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(123, 'A520 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(124, 'A03S 1 FLEX', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(125, 'A11', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:21', 0),
(126, 'A10S', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(127, 'A20S', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(128, 'A21S', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(129, 'A23', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(130, 'A24 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(131, 'A24 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(132, 'A10', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(133, 'J530 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(134, 'J530 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(135, 'J730  OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(136, 'J730 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(137, 'J330', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(138, 'A03 CORE', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(139, 'A01 CORE', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(140, 'A260', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(141, 'J260', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(142, 'A720 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(143, 'A730 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(144, 'A90 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(145, 'A720 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(146, 'J610', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(147, 'J810 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(148, 'J810 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(149, 'G570', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(150, 'G610', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(151, 'A42 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(152, 'A42 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(153, 'A32 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(154, 'A32 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(155, 'A40 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(156, 'A52 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(157, 'A52 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(158, 'A53 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(159, 'A20E', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(160, 'A51 5G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(161, 'A51 5G COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(162, 'A71 4G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(163, 'A71 4G COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(164, 'S8 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(165, 'S8 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(166, 'S9 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(167, 'S9 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(168, 'S8 PLUS OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(169, 'S8 PLUS COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(170, 'S10 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(171, 'S10 PLUS COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(172, 'S20 FE OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(173, 'S20 FE COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(174, 'S20 4G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(175, 'S20 5G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(176, 'S21 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(177, 'S2O PLUS OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(178, 'S20 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(179, 'S21 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(180, 'S22 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(181, 'S22 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(182, 'S23 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(183, 'S24 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(184, 'NOT 10 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(185, 'NOTE 10 PLUS OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(186, 'NOT 20 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(187, 'NOT 20 ULTRA OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(188, 'A22 4G OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(189, 'A22 4G COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(190, 'A22 5G', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(191, 'A70 OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(192, 'A70 COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(193, 'S9 PLUS OLED', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(194, 'S9 PLUS COPY', 'SAMSUNG', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(195, '3A OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(196, '3A COPY', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(197, '3A XL OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(198, '3A XL COPY', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(199, 'PIXEL 4', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(200, '4A OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(201, '4A COPY', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(202, '4A 5G OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(203, '4A 5G COPY', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(204, 'PIXEL 5 OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(205, 'PIXEL 5 COPY', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(206, 'PIXEL 6  OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(207, '6A  OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(208, '6PRO OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(209, '7PRO OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(210, 'PIXEL 8 OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(211, '8PRO OLED', 'PIXELS', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(212, 'Y9 PRIME 2019', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(213, 'Y9 2019', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(214, 'Y6 2019', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(215, 'OPPO A53', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(216, 'OPPO A17', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(217, 'OPPO A57', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(218, 'OPPO A31', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(219, 'PSMART 2019', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(220, 'OPPO F11', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(221, 'OPPO A83', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(222, 'HUAWEI P40 LITE', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(223, 'REDMI 13 C', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(224, 'REDMI14 C', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(225, 'NOTE 10 PRO', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(226, 'OPPO A3S', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(227, 'OPPO A54 4G', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(228, 'OPPO A57 NEW', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(229, 'NOVA 3I', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(230, 'XZ1', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(231, 'EXPERIA 10 II', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(232, 'EXPERIA 10 III', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(233, 'EXPERIA 10 IV', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(234, 'EXPERIA 10 MARK 5 II', 'HUAWEI & OPPO', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(235, 'REALME 7', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(236, 'C12', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(237, 'REDMI NOTE 12', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(238, 'REDMI NOTE 12 PRO', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(239, 'REDMI NOTE 9', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(240, 'REDMI NOT 8', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(241, 'NOKIA G10', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(242, 'NOKIA C10', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(243, 'NOKIA 1.4', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(244, 'NOKIA 3.4', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(245, 'NOKIA 2.3', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(246, 'REDMI  NOTE 10', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(247, 'REDMI 7', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(248, 'REDMI 10', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(249, 'REALME C55', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(250, 'REALME C30S', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0),
(251, 'REALME C30', 'REALME & NOKIA', 3, '', 0.00, '2026-04-28 10:30:22', 0);

-- --------------------------------------------------------

--
-- Table structure for table `product_owners`
--

CREATE TABLE `product_owners` (
  `id` int(11) NOT NULL,
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
-- Table structure for table `retail_stock`
--

CREATE TABLE `retail_stock` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `pieces_quantity` int(11) NOT NULL DEFAULT 0,
  `retail_price` decimal(10,2) DEFAULT NULL,
  `last_updated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_bulk`
--

CREATE TABLE `sales_bulk` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
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
  `amount` decimal(12,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_external`
--

CREATE TABLE `sales_external` (
  `id` int(11) NOT NULL,
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
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sales_retail`
--

CREATE TABLE `sales_retail` (
  `id` int(11) NOT NULL,
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
  `amount` decimal(12,2) DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `stock`
--

CREATE TABLE `stock` (
  `id` int(11) NOT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
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
  `product_id` int(11) DEFAULT NULL,
  `pieces_moved` int(11) NOT NULL,
  `moved_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
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
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `role` enum('admin','manager','user') DEFAULT 'user',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive','suspended','') NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `email` varchar(60) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `full_name`, `role`, `created_at`, `status`, `last_login`, `email`) VALUES
(1, 'admin', '$2y$10$O.gB7jIXhrV2ukyPqdM5wO55h2knORR9RHUQE0lC4r5N14r9s2Lei', 'Seth', 'admin', '2026-02-11 15:05:51', 'active', '2026-05-03 16:14:19', 'seth@gmail.com');

-- --------------------------------------------------------

--
-- Table structure for table `weekly_revenue`
--

CREATE TABLE `weekly_revenue` (
  `id` int(11) NOT NULL,
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

--
-- Dumping data for table `weekly_revenue`
--

INSERT INTO `weekly_revenue` (`id`, `week_start_date`, `week_end_date`, `bulk_sales_total`, `retail_sales_total`, `total_revenue`, `total_cost`, `total_profit`, `profit_margin`, `created_at`) VALUES
(1, '2026-04-27', '2026-05-03', 0.00, 0.00, 0.00, 0.00, 0.00, 0.00, '2026-04-28 14:30:57');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `boaster`
--
ALTER TABLE `boaster`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `consumption`
--
ALTER TABLE `consumption`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_consumption_date` (`consumption_date`);

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
  ADD KEY `idx_client` (`client`);

--
-- Indexes for table `loan_payments`
--
ALTER TABLE `loan_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `loan_id` (`loan_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_deleted` (`deleted`),
  ADD KEY `idx_name` (`name`);

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
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sale_date` (`sale_date`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `sales_external`
--
ALTER TABLE `sales_external`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_retail`
--
ALTER TABLE `sales_retail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `idx_sale_date` (`sale_date`),
  ADD KEY `idx_created_at` (`created_at`);

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
-- Indexes for table `weekly_revenue`
--
ALTER TABLE `weekly_revenue`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `boaster`
--
ALTER TABLE `boaster`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `consumption`
--
ALTER TABLE `consumption`
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
-- AUTO_INCREMENT for table `loan_payments`
--
ALTER TABLE `loan_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=252;

--
-- AUTO_INCREMENT for table `product_owners`
--
ALTER TABLE `product_owners`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

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
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `weekly_revenue`
--
ALTER TABLE `weekly_revenue`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- Constraints for dumped tables
--

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
