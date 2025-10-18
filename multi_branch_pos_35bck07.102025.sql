-- phpMyAdmin SQL Dump
-- version 4.9.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Oct 07, 2025 at 12:15 PM
-- Server version: 8.0.17
-- PHP Version: 7.3.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `multi_branch_pos_35`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_logs`
--

CREATE TABLE `activity_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `description` text,
  `branch_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `activity_logs`
--

INSERT INTO `activity_logs` (`id`, `user_id`, `action`, `description`, `branch_id`, `created_at`) VALUES
(1, 2, 'Sale', 'Created sale #1', 1, '2025-09-17 13:18:36'),
(2, 2, 'Sale Return', 'Processed return for sale #1 amount 250.00', 1, '2025-09-17 13:25:25'),
(3, 2, 'Sale', 'Created sale #2 merged with return #1', 1, '2025-09-17 13:25:47'),
(4, 2, 'Sale', 'Created sale #3', 1, '2025-09-18 09:33:43'),
(5, 2, 'Sale', 'Created sale #4', 1, '2025-09-18 09:35:05'),
(6, 2, 'Sale', 'Created sale #5', 1, '2025-09-18 09:51:27'),
(7, 2, 'Sale Return', 'Processed return for sale #5 amount 250.00', 1, '2025-09-18 09:52:15'),
(8, 2, 'Sale', 'Created sale #6 merged with return #2', 1, '2025-09-18 09:52:32'),
(9, 2, 'Sale Return', 'Processed return for sale #3 amount 330.00', 1, '2025-09-18 10:08:59'),
(10, 2, 'Sale', 'Created sale #7 merged with return #3', 1, '2025-09-18 10:09:23'),
(11, 2, 'Sale Return', 'Processed return for sale #4 amount 250.00', 1, '2025-09-18 10:11:02'),
(12, 2, 'Sale', 'Created sale #8', 1, '2025-09-18 11:15:12'),
(13, 2, 'Sale', 'Created sale #9', 1, '2025-09-18 11:16:12'),
(14, 2, 'Sale', 'Created sale #10', 1, '2025-09-18 11:24:50'),
(15, 2, 'Sale', 'Created sale #11', 1, '2025-09-18 11:36:09'),
(16, 2, 'Sale', 'Created sale #12', 1, '2025-09-18 12:03:35'),
(17, 2, 'Sale Return', 'Processed return for sale #10 amount 375.00', 1, '2025-09-18 12:07:10'),
(18, 2, 'Sale', 'Created sale #13', 1, '2025-09-18 12:08:50'),
(19, 2, 'Sale', 'Created sale #14', 1, '2025-09-18 12:09:36'),
(20, 2, 'Sale Return', 'Processed return for sale #13 amount 200.00', 1, '2025-09-18 12:11:03'),
(21, 2, 'Sale', 'Created sale #15 merged with return #6', 1, '2025-09-18 12:11:28'),
(22, 2, 'Sale', 'Created sale #16 merged with return #5', 1, '2025-09-18 17:11:33'),
(23, 2, 'Sale', 'Created sale #17', 1, '2025-09-18 17:33:48'),
(24, 2, 'Sale', 'Created sale #18', 1, '2025-09-18 17:37:10'),
(25, 2, 'Sale', 'Created sale #19', 1, '2025-09-19 09:04:53'),
(26, 2, 'Sale', 'Created sale #20', 1, '2025-09-19 09:10:45'),
(27, 2, 'Sale Return', 'Processed return for sale #20 amount 250.00', 1, '2025-09-19 09:11:16'),
(28, 2, 'Sale', 'Created sale #21 merged with return #7', 1, '2025-09-19 09:11:38'),
(29, 2, 'Sale', 'Created sale #22', 1, '2025-09-19 09:16:39'),
(30, 2, 'Purchase', 'Created purchase #1', 1, '2025-09-19 10:05:55'),
(31, 2, 'Sale', 'Created sale #23', 1, '2025-09-19 10:29:46'),
(32, 2, 'Sale', 'Created sale #24', 1, '2025-09-19 11:08:10'),
(33, 2, 'Sale', 'Created sale #25', 1, '2025-09-19 13:55:48'),
(34, 13, 'Purchase', 'Created purchase #2', 7, '2025-09-20 01:06:39'),
(35, 13, 'Sale', 'Created sale #26', 7, '2025-09-20 01:09:08'),
(36, 13, 'Sale', 'Created sale #27', 7, '2025-09-20 01:10:24'),
(37, 13, 'Sale', 'Created sale #28', 7, '2025-09-20 01:10:50'),
(38, 13, 'Sale', 'Created sale #29', 7, '2025-09-20 01:11:59'),
(39, 3, 'Sale', 'Created sale #30', 2, '2025-09-20 09:10:00'),
(40, 3, 'Transfer', 'Transferred 1 batches from branch 2 to branch 1 (multi-item transfer)', 2, '2025-09-20 11:32:46'),
(41, 3, 'Transfer', 'Transferred 1 batches from branch 2 to branch 1 (multi-item transfer)', 2, '2025-09-20 12:07:49'),
(42, 3, 'Sale Return', 'Processed return for sale #30 amount 720.00', 2, '2025-09-20 12:51:34'),
(43, 3, 'Sale', 'Created sale #31 merged with return #8', 2, '2025-09-20 12:52:07'),
(44, 3, 'Sale', 'Created sale #32', 2, '2025-09-20 12:53:43'),
(45, 3, 'Sale', 'Created sale #33', 2, '2025-09-20 12:57:29'),
(46, 2, 'Transfer', 'Transferred 1 batches from branch 1 to branch 2 (multi-item transfer)', 1, '2025-09-20 20:06:15'),
(47, 2, 'Purchase', 'Created purchase #3', 1, '2025-09-22 00:36:50'),
(48, 2, 'Purchase', 'Created purchase #4', 1, '2025-09-22 00:38:58'),
(49, 13, 'Purchase', 'Created purchase #5', 7, '2025-09-23 10:11:15'),
(50, 13, 'Sale', 'Created sale #34', 7, '2025-09-23 10:12:11'),
(51, 13, 'Transfer', 'Transferred 1 batches from branch 7 to branch 6 (multi-item transfer)', 7, '2025-09-23 10:19:02'),
(52, 3, 'Transfer', 'Transferred 1 batches from branch 2 to branch 1 (multi-item transfer)', 2, '2025-09-23 11:41:13'),
(53, 2, 'Transfer', 'Transferred 1 batches from branch 2 to branch 1 (multi-item transfer)', 2, '2025-09-23 11:42:36'),
(54, 2, 'Sale', 'Created sale #35', 1, '2025-09-23 13:25:17'),
(55, 2, 'WriteOff', 'Write off 2 units of product ID 7 (batch 02)', 1, '2025-09-24 23:33:06'),
(56, 2, 'WriteOff', 'Write off 10 units of product ID 1 (batch 01)', 1, '2025-09-24 23:45:48'),
(57, 2, 'WriteOff', 'Write off 5 units of product ID 2 (batch 01)', 1, '2025-09-24 23:51:36'),
(58, 2, 'Sale', 'Created sale #36', 1, '2025-09-25 13:49:21'),
(59, 2, 'Sale', 'Created sale #37', 1, '2025-09-25 13:57:54'),
(60, 2, 'Sale', 'Created sale #38', 1, '2025-09-25 14:11:53'),
(61, 2, 'Transfer', 'Transferred 1 batches from branch 1 to branch 2 (multi-item transfer)', 1, '2025-09-25 17:51:06'),
(62, 2, 'Sale', 'Created sale #39', 1, '2025-09-26 09:37:48'),
(63, 2, 'Sale', 'Created sale #40', 1, '2025-09-26 09:40:38'),
(64, 2, 'Sale', 'Created sale #41', 1, '2025-09-26 11:34:35'),
(65, 2, 'Sale', 'Created sale #42', 1, '2025-09-26 11:53:23'),
(66, 2, 'Sale', 'Created sale #43', 1, '2025-09-26 11:59:41'),
(67, 2, 'Sale', 'Created sale #44', 1, '2025-09-26 12:06:21'),
(68, 11, 'OpeningStock', 'Recorded opening stock (purchase page)', 5, '2025-09-26 13:06:12'),
(69, 11, 'Sale', 'Created sale #45', 5, '2025-09-26 13:18:20'),
(70, 11, 'Sale', 'Created sale #46', 5, '2025-09-26 23:33:27'),
(71, 11, 'Sale', 'Created sale #47', 5, '2025-09-26 23:49:58'),
(72, 11, 'Purchase', 'Created purchase #6', 5, '2025-09-27 00:16:24'),
(73, 11, 'Purchase', 'Created purchase #7', 5, '2025-09-27 00:38:09'),
(74, 13, 'Purchase', 'Created purchase #8', 7, '2025-09-27 00:49:06'),
(75, 13, 'Purchase', 'Created purchase #9', 7, '2025-09-27 01:04:30'),
(76, 2, 'Transfer', 'Transferred 1 batches from branch 1 to branch 5 (multi-item transfer)', 1, '2025-09-27 08:38:33'),
(77, 11, 'Transfer', 'Transferred 1 batches from branch 5 to branch 2 (multi-item transfer)', 5, '2025-09-27 08:44:04'),
(78, 11, 'Transfer', 'Transferred 1 batches from branch 5 to branch 1 (multi-item transfer)', 5, '2025-09-27 08:45:02'),
(79, 2, 'Transfer', 'Transferred 1 batches from branch 1 to branch 2 (multi-item transfer)', 1, '2025-09-27 08:47:07'),
(80, 2, 'Sale', 'Created sale #48', 1, '2025-09-28 08:13:39'),
(81, 2, 'Sale', 'Created sale #49', 1, '2025-09-28 08:29:51'),
(82, 2, 'Sale', 'Created sale #50', 1, '2025-09-28 08:31:51'),
(83, 3, 'Purchase', 'Created purchase #10', 2, '2025-09-29 11:37:18'),
(84, 2, 'Transfer', 'Transferred 1 batches from branch 1 to branch 2 (multi-item transfer)', 1, '2025-09-29 11:57:52'),
(85, 11, 'Transfer', 'Transferred 1 batches from branch 5 to branch 1 (multi-item transfer)', 5, '2025-09-29 11:59:38'),
(86, 11, 'Transfer', 'Transferred 1 batches from branch 5 to branch 2 (multi-item transfer)', 5, '2025-09-29 12:00:13'),
(87, 2, 'Sale', 'Created sale #51', 1, '2025-09-30 09:00:37'),
(88, 14, 'Sale', 'Created sale #52', 8, '2025-09-30 19:02:13'),
(89, 14, 'Sale', 'Created sale #53', 8, '2025-09-30 19:37:49'),
(90, 14, 'Sale', 'Created sale #54', 8, '2025-10-01 08:00:17'),
(91, 14, 'Transfer', 'Transferred 1 batches from branch 8 to branch 7 (multi-item transfer)', 8, '2025-10-01 08:18:29'),
(92, 14, 'Transfer', 'Transferred 1 batches from branch 8 to branch 7 (multi-item transfer)', 8, '2025-10-01 08:45:30'),
(93, 14, 'Transfer', 'Transferred 1 batches from branch 8 to branch 7 (multi-item transfer)', 8, '2025-10-01 08:47:48'),
(94, 11, 'Transfer', 'Transferred 1 batches from branch 5 to branch 8 (multi-item transfer)', 5, '2025-10-01 09:19:47'),
(95, 13, 'Transfer', 'Transferred 1 batches from branch 7 to branch 8 (multi-item transfer)', 7, '2025-10-01 09:36:05'),
(96, 14, 'CashDraw', 'Owner withdrawal CASH Rs. 100.00 on 2025-10-01', 8, '2025-10-01 09:50:55'),
(97, 14, 'CashDraw', 'Owner withdrawal BANK Rs. 150.00 (bank #4) on 2025-10-01', 8, '2025-10-01 10:13:29'),
(98, 14, 'Sale Return', 'Processed return for sale #54 amount 250.00', 8, '2025-10-01 15:02:20'),
(99, 14, 'Sale', 'Created sale #55 merged with return #4', 8, '2025-10-01 15:04:11'),
(100, 14, 'Sale', 'Created sale #56', 8, '2025-10-01 15:22:49'),
(101, 14, 'Sale Return', 'Processed return for sale #56 amount 250.00', 8, '2025-10-01 15:23:41'),
(102, 11, 'Sale', 'Created sale #57', 5, '2025-10-02 08:31:59'),
(103, 11, 'Sale Return', 'Processed return for sale #57 amount 600.00', 5, '2025-10-02 08:33:37'),
(104, 11, 'Sale', 'Created sale #58 merged with return #11', 5, '2025-10-02 08:34:14'),
(105, 11, 'Sale', 'Created sale #59', 5, '2025-10-02 09:22:47'),
(106, 11, 'Sale Return', 'Processed return for sale #59 amount 600.00', 5, '2025-10-02 09:25:16'),
(107, 11, 'Sale', 'Created sale #60', 5, '2025-10-02 09:54:28'),
(108, 11, 'Sale', 'Created sale #61', 5, '2025-10-02 09:59:00'),
(109, 11, 'Sale', 'Created sale #62', 5, '2025-10-02 10:08:09'),
(110, 11, 'Sale', 'Created sale #63 merged with return #12', 5, '2025-10-02 10:23:15'),
(111, 11, 'Sale', 'Created sale #64', 5, '2025-10-02 10:24:34'),
(112, 11, 'Sale', 'Created sale #65', 5, '2025-10-02 10:58:27'),
(113, 11, 'Sale', 'Created sale #66', 5, '2025-10-02 10:59:57'),
(114, 11, 'Sale Return', 'Processed return for sale #66 amount 600.00', 5, '2025-10-02 11:00:21'),
(115, 11, 'Sale', 'Created sale #70 merged with return #13', 5, '2025-10-02 11:06:32'),
(116, 2, 'Sale', 'Created sale #71', 1, '2025-10-02 18:33:57'),
(117, 2, 'Sale', 'Created sale #72', 1, '2025-10-02 18:36:24'),
(118, 2, 'Sale', 'Created sale #73', 1, '2025-10-02 18:36:58'),
(119, 2, 'Sale', 'Created sale #74', 1, '2025-10-02 18:37:48'),
(120, 2, 'Sale', 'Created sale #75', 1, '2025-10-02 18:39:45'),
(121, 2, 'Sale', 'Created sale #76', 1, '2025-10-02 19:01:29'),
(122, 15, 'Sale', 'Created sale #77', 9, '2025-10-03 08:35:45'),
(123, 15, 'Sale', 'Created sale #78', 9, '2025-10-03 08:36:41'),
(124, 2, 'Sale', 'Created sale #79', 1, '2025-10-03 22:11:21'),
(125, 2, 'Sale', 'Created sale #80', 1, '2025-10-03 22:13:51'),
(126, 2, 'Sale', 'Created sale #81', 1, '2025-10-03 22:29:53'),
(127, 2, 'Sale', 'Created sale #82', 1, '2025-10-03 22:30:29'),
(128, 2, 'Sale', 'Created sale #83', 1, '2025-10-03 22:31:06'),
(129, 2, 'Sale', 'Created sale #84', 1, '2025-10-03 22:44:57'),
(130, 2, 'Sale', 'Created sale #85', 1, '2025-10-03 22:45:45'),
(131, 2, 'Sale', 'Created sale #86', 1, '2025-10-03 23:24:11'),
(132, 2, 'Sale', 'Created sale #87', 1, '2025-10-03 23:28:55'),
(133, 2, 'Sale', 'Created sale #88', 1, '2025-10-03 23:37:12'),
(134, 2, 'Sale', 'Created sale #89', 1, '2025-10-03 23:38:55'),
(135, 2, 'Sale Return', 'Processed return for sale #89 amount 1,800.00', 1, '2025-10-03 23:41:09'),
(136, 2, 'Sale', 'Created sale #90 merged with return #14', 1, '2025-10-03 23:42:30'),
(137, 2, 'Sale', 'Created sale #91', 1, '2025-10-04 07:13:50'),
(138, 2, 'Sale', 'Created sale #92', 1, '2025-10-04 07:31:55'),
(139, 2, 'Sale', 'Created sale #93', 1, '2025-10-04 07:39:39'),
(140, 2, 'Sale', 'Created sale #94', 1, '2025-10-04 07:41:48'),
(141, 2, 'Sale Return', 'Processed return for sale #94 amount 460.00', 1, '2025-10-04 07:42:43'),
(142, 2, 'Sale', 'Created sale #95 merged with return #15', 1, '2025-10-04 07:44:12'),
(143, 2, 'Sale', 'Created sale #96', 1, '2025-10-04 07:55:51'),
(144, 2, 'Sale Return', 'Processed return for sale #96 amount 164.00', 1, '2025-10-04 07:56:59'),
(145, 2, 'Sale', 'Created sale #97 merged with return #16', 1, '2025-10-04 08:34:09'),
(146, 2, 'Sale', 'Created sale #98', 1, '2025-10-04 08:52:09'),
(147, 2, 'Sale', 'Created sale #99', 1, '2025-10-04 14:38:19'),
(148, 2, 'Sale Return', 'Processed return for sale #99 amount 395.00', 1, '2025-10-04 14:41:33'),
(149, 2, 'Sale', 'Created sale #100 merged with return #17', 1, '2025-10-04 14:45:06'),
(150, 2, 'Sale', 'Created sale #101', 1, '2025-10-04 14:47:03'),
(151, 2, 'Sale Return', 'Processed return for sale #101 amount 300.00', 1, '2025-10-04 14:47:39'),
(152, 11, 'Stock', 'Adjustment #1 (5)', 5, '2025-10-04 17:39:35'),
(153, 11, 'Sale', 'Created sale #102', 5, '2025-10-05 08:57:00'),
(154, 11, 'Sale', 'Created sale #103', 5, '2025-10-05 09:00:07'),
(155, 11, 'Sale', 'Created sale #104', 5, '2025-10-05 09:29:55'),
(156, 11, 'Sale', 'Created sale #105', 5, '2025-10-05 09:33:24'),
(157, 11, 'Sale', 'Created sale #106', 5, '2025-10-05 09:39:36'),
(158, 11, 'Sale', 'Created sale #107', 5, '2025-10-05 09:42:03'),
(159, 11, 'Stock', 'Adjustment #2 (5)', 5, '2025-10-05 09:50:56'),
(160, 11, 'Stock', 'Adjustment #3 (5)', 5, '2025-10-05 09:51:33'),
(161, 11, 'Sale', 'Created sale #108', 5, '2025-10-06 09:37:20'),
(162, 11, 'Sale', 'Created sale #109', 5, '2025-10-06 09:38:53'),
(163, 2, 'Sale', 'Created sale #110', 1, '2025-10-06 12:38:35'),
(164, 3, 'Sale', 'Created sale #111', 2, '2025-10-06 13:13:55'),
(165, 3, 'Stock', 'Adjustment #4 (2)', 2, '2025-10-06 17:55:27'),
(166, 3, 'Sale', 'Created sale #112', 2, '2025-10-06 18:14:47'),
(167, 3, 'Sale', 'Created sale #113', 2, '2025-10-06 18:39:29');

-- --------------------------------------------------------

--
-- Table structure for table `bank_accounts`
--

CREATE TABLE `bank_accounts` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `account_no` varchar(100) NOT NULL,
  `branch_id` int(11) DEFAULT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `bank_accounts`
--

INSERT INTO `bank_accounts` (`id`, `name`, `account_no`, `branch_id`, `opening_balance`, `created_by`, `created_at`) VALUES
(1, 'BOC', '2514635', 1, '140.00', 1, '2025-09-19 09:08:49'),
(2, 'POP', '7554556655', 2, '3400.00', 1, '2025-09-20 11:43:43'),
(3, 'PEOPLE\"S', '255455665525', 5, '1000.00', 1, '2025-09-26 13:16:54'),
(4, 'NSB', '7745625', 8, '1000.00', 1, '2025-09-30 19:09:08'),
(5, 'BOC', '77897764', 9, '2000.00', 1, '2025-10-03 08:27:09');

-- --------------------------------------------------------

--
-- Table structure for table `branches`
--

CREATE TABLE `branches` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `branches`
--

INSERT INTO `branches` (`id`, `name`, `address`) VALUES
(1, 'JAFF', '123 Main Street'),
(2, 'JKKS', '456 Second Street'),
(3, 'KLN', ''),
(4, 'CMO', ''),
(5, 'MULL', ''),
(6, 'LEO', ''),
(7, 'PUK', ''),
(8, 'calis', ''),
(9, 'ABC', '');

-- --------------------------------------------------------

--
-- Table structure for table `branch_counters`
--

CREATE TABLE `branch_counters` (
  `branch_id` int(11) NOT NULL,
  `next_invoice` int(11) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `branch_counters`
--

INSERT INTO `branch_counters` (`branch_id`, `next_invoice`) VALUES
(1, 55),
(2, 8),
(5, 22),
(7, 6),
(8, 6);

-- --------------------------------------------------------

--
-- Table structure for table `brands`
--

CREATE TABLE `brands` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `brands`
--

INSERT INTO `brands` (`id`, `name`, `is_active`) VALUES
(1, 'CleanCo', 1),
(2, 'HairCare', 1),
(3, 'HairCare', 1),
(4, 'SoftFeel', 1),
(5, 'SkinGlow', 1),
(6, 'HomeClean', 1),
(7, 'FreshAir', 1),
(8, 'GrainCo', 1),
(9, 'GrainCo', 1),
(10, 'BakeHouse', 1),
(11, 'OilCo', 1),
(12, 'BrewIt', 1),
(13, 'BrewIt', 1),
(14, 'SeaDelight', 1),
(15, 'SeaDelight', 1),
(16, 'CleanCo', 1),
(17, 'HairCare', 1),
(18, 'HairCare', 1),
(19, 'SoftFeel', 1),
(20, 'SkinGlow', 1),
(21, 'HomeClean', 1),
(22, 'FreshAir', 1),
(23, 'GrainCo', 1),
(24, 'GrainCo', 1),
(25, 'BakeHouse', 1),
(26, 'OilCo', 1),
(27, 'BrewIt', 1),
(28, 'BrewIt', 1),
(29, 'SeaDelight', 1),
(30, 'SeaDelight', 1),
(31, 'CleanCo', 1),
(32, 'HairCare', 1),
(33, 'HairCare', 1),
(34, 'SoftFeel', 1),
(35, 'SkinGlow', 1),
(36, 'HomeClean', 1),
(37, 'FreshAir', 1),
(38, 'GrainCo', 1),
(39, 'GrainCo', 1),
(40, 'BakeHouse', 1),
(41, 'OilCo', 1),
(42, 'BrewIt', 1),
(43, 'BrewIt', 1),
(44, 'SeaDelight', 1),
(45, 'SeaDelight', 1);

-- --------------------------------------------------------

--
-- Table structure for table `card_commissions`
--

CREATE TABLE `card_commissions` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `gross_amount` decimal(12,2) NOT NULL,
  `commission_rate` decimal(5,2) NOT NULL DEFAULT '2.50',
  `commission_amount` decimal(12,2) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `card_commissions`
--

INSERT INTO `card_commissions` (`id`, `sale_id`, `branch_id`, `gross_amount`, `commission_rate`, `commission_amount`, `created_at`) VALUES
(1, 42, 1, '330.00', '2.50', '8.25', '2025-09-26 11:53:23'),
(2, 43, 1, '300.00', '2.50', '7.50', '2025-09-26 11:59:41'),
(3, 44, 1, '100.00', '2.50', '2.50', '2025-09-26 12:06:21'),
(4, 45, 5, '300.00', '2.50', '7.50', '2025-09-26 13:18:20'),
(5, 46, 5, '300.00', '2.50', '7.50', '2025-09-26 23:33:27'),
(6, 47, 5, '300.00', '2.50', '7.50', '2025-09-26 23:49:58'),
(7, 62, 5, '300.00', '2.50', '7.50', '2025-10-02 10:08:09'),
(8, 71, 1, '300.00', '2.50', '7.50', '2025-10-02 18:33:57');

-- --------------------------------------------------------

--
-- Table structure for table `cargo_payments`
--

CREATE TABLE `cargo_payments` (
  `id` int(11) NOT NULL,
  `cargo_provider_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `method` enum('cash','bank','card','cheque') NOT NULL,
  `bank_account_id` int(11) DEFAULT NULL,
  `cheque_no` varchar(100) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_by` int(11) NOT NULL,
  `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cargo_payments`
--

INSERT INTO `cargo_payments` (`id`, `cargo_provider_id`, `branch_id`, `method`, `bank_account_id`, `cheque_no`, `amount`, `paid_by`, `paid_at`, `notes`) VALUES
(1, 1, 1, 'cash', NULL, '', '1000.00', 2, '2025-09-22 00:33:28', ''),
(2, 1, 1, 'cash', NULL, '', '1000.00', 2, '2025-09-22 00:40:08', ''),
(3, 1, 1, 'cash', NULL, NULL, '300.00', 2, '2025-09-23 08:14:22', NULL),
(4, 1, 1, 'cash', NULL, NULL, '300.00', 2, '2025-09-23 08:15:30', NULL),
(5, 1, 1, 'cash', NULL, NULL, '500.00', 2, '2025-09-23 08:27:29', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `cargo_providers`
--

CREATE TABLE `cargo_providers` (
  `id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT '1',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cargo_providers`
--

INSERT INTO `cargo_providers` (`id`, `name`, `phone`, `address`, `is_active`, `created_by`, `created_at`) VALUES
(1, 'Default Cargo', NULL, NULL, 1, 1, '2025-09-22 00:09:49'),
(2, 'INDICO', '91772563456', 'India', 1, 2, '2025-09-23 17:04:54');

-- --------------------------------------------------------

--
-- Table structure for table `cargo_services`
--

CREATE TABLE `cargo_services` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `purchase_id` int(11) DEFAULT NULL,
  `cargo_provider_id` int(11) NOT NULL,
  `transfer_date` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `reference_no` varchar(50) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cargo_services`
--

INSERT INTO `cargo_services` (`id`, `branch_id`, `supplier_id`, `purchase_id`, `cargo_provider_id`, `transfer_date`, `reference_no`, `description`, `amount`, `created_by`, `created_at`) VALUES
(1, 1, 1, NULL, 1, '2025-09-22 00:32:32', '2554655hgffyu', 'hvfutfytf', '1000.00', 2, '2025-09-22 00:32:32'),
(2, 1, 1, NULL, 1, '2025-09-22 01:32:21', '', '', '2500.00', 2, '2025-09-22 01:32:21'),
(3, 1, 1, NULL, 1, '2025-09-22 15:59:46', '', '', '800.00', 0, '2025-09-22 15:59:46'),
(4, 1, 1, NULL, 1, '2025-09-23 12:48:33', '', '', '800.00', 0, '2025-09-23 12:48:33'),
(5, 1, 1, NULL, 1, '2025-09-23 13:04:19', '', '', '500.00', 0, '2025-09-23 13:04:19'),
(6, 1, 1, NULL, 1, '2025-09-23 17:09:34', '', '', '200.00', 0, '2025-09-23 17:09:34'),
(7, 1, 1, NULL, 1, '2025-09-23 17:15:58', '', '', '1000.00', 0, '2025-09-23 17:15:58'),
(8, 1, 1, NULL, 2, '2025-09-24 18:21:31', '', '', '100.00', 2, '2025-09-24 18:21:31'),
(9, 1, 1, NULL, 2, '2025-09-24 18:22:04', '', '', '100.00', 2, '2025-09-24 18:22:04'),
(10, 1, 1, 1, 1, '2025-09-24 13:08:41', '', '', '500.00', 2, '2025-09-24 18:38:41'),
(11, 2, 1, 10, 2, '2025-09-29 06:08:24', '', '', '100.00', 3, '2025-09-29 11:38:24'),
(12, 8, 1, 12, 1, '2025-10-01 02:49:59', '', '', '200.00', 14, '2025-10-01 08:19:59');

-- --------------------------------------------------------

--
-- Table structure for table `cash_drawer_daily`
--

CREATE TABLE `cash_drawer_daily` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `business_date` date NOT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_cash_sales` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_cash_returns` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cash_from_credit_customers` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_cash_expenses` decimal(12,2) NOT NULL DEFAULT '0.00',
  `supplier_payment_cash` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cargo_payment_cash` decimal(12,2) NOT NULL DEFAULT '0.00',
  `other_cash_out` decimal(12,2) NOT NULL DEFAULT '0.00',
  `calculated_closing_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `actual_closing_balance` decimal(12,2) DEFAULT NULL,
  `difference` decimal(12,2) GENERATED ALWAYS AS ((coalesce(`actual_closing_balance`,`calculated_closing_balance`) - `calculated_closing_balance`)) STORED,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cash_drawer_daily`
--

INSERT INTO `cash_drawer_daily` (`id`, `branch_id`, `business_date`, `opening_balance`, `total_cash_sales`, `total_cash_returns`, `cash_from_credit_customers`, `total_cash_expenses`, `supplier_payment_cash`, `cargo_payment_cash`, `other_cash_out`, `calculated_closing_balance`, `actual_closing_balance`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 2, '2025-09-20', '1000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', NULL, 3, '2025-10-01 07:56:40', NULL),
(2, 1, '2025-09-24', '4015.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', NULL, 2, '2025-10-01 07:56:40', NULL),
(3, 5, '2025-09-26', '10000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', NULL, 11, '2025-10-01 07:56:40', NULL),
(4, 1, '2025-10-01', '5.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', NULL, 2, '2025-10-01 07:56:40', NULL),
(5, 8, '2025-09-30', '1000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', NULL, 14, '2025-10-01 07:56:40', NULL),
(6, 8, '2025-10-01', '1000.00', '1050.00', '0.00', '0.00', '0.00', '0.00', '0.00', '600.00', '1450.00', NULL, 14, '2025-10-01 07:56:40', '2025-10-01 15:23:13'),
(8, 5, '2025-10-02', '10000.00', '2460.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '12460.00', NULL, 11, '2025-10-02 08:30:28', '2025-10-02 11:06:54'),
(9, 1, '2025-10-02', '0.00', '100.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '100.00', NULL, 2, '2025-10-02 19:01:23', '2025-10-02 19:01:35'),
(10, 1, '2025-10-03', '100.00', '5340.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '5440.00', NULL, 2, '2025-10-03 07:51:21', '2025-10-03 22:47:36'),
(11, 9, '2025-10-03', '1000.00', '800.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1800.00', NULL, 15, '2025-10-03 08:16:40', '2025-10-03 15:26:05'),
(12, 1, '2025-10-04', '5440.00', '8141.00', '300.00', '0.00', '0.00', '0.00', '0.00', '0.00', '13281.00', NULL, 2, '2025-10-04 07:12:16', '2025-10-04 14:47:41'),
(13, 5, '2025-10-06', '10000.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '10000.00', NULL, 11, '2025-10-06 09:13:41', '2025-10-06 09:14:06'),
(14, 2, '2025-10-06', '1000.00', '720.00', '0.00', '0.00', '0.00', '0.00', '0.00', '0.00', '1720.00', NULL, 3, '2025-10-06 18:14:30', '2025-10-06 18:39:35');

-- --------------------------------------------------------

--
-- Table structure for table `cash_draws`
--

CREATE TABLE `cash_draws` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `source_type` enum('cash','bank','wallet') NOT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `amount` decimal(12,2) NOT NULL,
  `draw_date` date NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cash_draws`
--

INSERT INTO `cash_draws` (`id`, `branch_id`, `source_type`, `bank_id`, `amount`, `draw_date`, `remarks`, `created_by`, `created_at`) VALUES
(1, 8, 'cash', NULL, '100.00', '2025-10-01', '', 14, '2025-10-01 09:50:55'),
(2, 8, 'bank', 4, '150.00', '2025-10-01', '', 14, '2025-10-01 10:13:29');

-- --------------------------------------------------------

--
-- Table structure for table `cash_openings`
--

CREATE TABLE `cash_openings` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `opening_date` date NOT NULL,
  `opening_amount` decimal(12,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `cash_openings`
--

INSERT INTO `cash_openings` (`id`, `branch_id`, `opening_date`, `opening_amount`, `created_by`, `created_at`) VALUES
(1, 2, '2025-09-20', '1000.00', 3, '2025-09-20 11:41:22'),
(2, 1, '2025-09-24', '4015.00', 2, '2025-09-23 13:25:40'),
(3, 5, '2025-09-26', '10000.00', 11, '2025-09-26 23:47:15'),
(4, 1, '2025-10-01', '5.00', 2, '2025-09-30 18:14:34'),
(6, 8, '2025-09-30', '1000.00', 14, '2025-09-30 19:05:58'),
(7, 8, '2025-10-01', '1000.00', 14, '2025-09-30 19:10:40'),
(8, 9, '2025-10-03', '1500.00', 15, '2025-10-03 08:16:36');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `is_active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `is_active`) VALUES
(1, 'Glass', 1),
(2, 'Personal Care', 1),
(3, 'Personal Care', 1),
(4, 'Personal Care', 1),
(5, 'Personal Care', 1),
(6, 'Personal Care', 1),
(7, 'Household', 1),
(8, 'Household', 1),
(9, 'Food', 1),
(10, 'Food', 1),
(11, 'Food', 1),
(12, 'Food', 1),
(13, 'Beverages', 1),
(14, 'Beverages', 1),
(15, 'Food', 1),
(16, 'Food', 1),
(17, 'Personal Care', 1),
(18, 'Personal Care', 1),
(19, 'Personal Care', 1),
(20, 'Personal Care', 1),
(21, 'Personal Care', 1),
(22, 'Household', 1),
(23, 'Household', 1),
(24, 'Food', 1),
(25, 'Food', 1),
(26, 'Food', 1),
(27, 'Food', 1),
(28, 'Beverages', 1),
(29, 'Beverages', 1),
(30, 'Food', 1),
(31, 'Food', 1),
(32, 'Personal Care', 1),
(33, 'Personal Care', 1),
(34, 'Personal Care', 1),
(35, 'Personal Care', 1),
(36, 'Personal Care', 1),
(37, 'Household', 1),
(38, 'Household', 1),
(39, 'Food', 1),
(40, 'Food', 1),
(41, 'Food', 1),
(42, 'Food', 1),
(43, 'Beverages', 1),
(44, 'Beverages', 1),
(45, 'Food', 1),
(46, 'Food', 1);

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `route` varchar(100) DEFAULT NULL,
  `due_limit` decimal(12,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `address`, `city`, `state`, `route`, `due_limit`) VALUES
(1, 'Keerthika Food City', '772514562', '12,puthukudegeruppu', 'Jaffna', 'NP', 'KKS', '1500.00'),
(2, 'Arthi', '', '', '', '', 'PLLI', '10000.00'),
(3, 'Anath', '53645656', 'sagsdgdsg', 'ddsgs', 'dsgsdgsd', 'MULL', '10000.00');

-- --------------------------------------------------------

--
-- Table structure for table `expenses`
--

CREATE TABLE `expenses` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `category` varchar(50) NOT NULL,
  `subcategory` varchar(50) NOT NULL,
  `payment_method` varchar(20) NOT NULL,
  `payee_name` varchar(100) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `expenses`
--

INSERT INTO `expenses` (`id`, `branch_id`, `expense_date`, `description`, `category`, `subcategory`, `payment_method`, `payee_name`, `amount`, `created_by`, `created_at`) VALUES
(1, 1, '2025-09-26', 'Card commission for Sale #42', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '8.25', 2, '2025-09-26 11:53:23'),
(2, 1, '2025-09-26', 'Card commission for Sale #43', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 2, '2025-09-26 11:59:41'),
(3, 1, '2025-09-26', 'Card commission for Sale #44', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '2.50', 2, '2025-09-26 12:06:21'),
(4, 5, '2025-09-26', 'Card commission for Sale #45', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 11, '2025-09-26 13:18:20'),
(5, 5, '2025-09-26', 'Card commission for Sale #46', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 11, '2025-09-26 23:33:27'),
(6, 5, '2025-09-26', 'Card commission for Sale #47', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 11, '2025-09-26 23:49:58'),
(7, 5, '2025-10-02', 'Card commission for Sale #62', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 11, '2025-10-02 10:08:09'),
(8, 1, '2025-10-02', 'Card commission for Sale #71', 'Bank Charges', 'Card Commission', 'bank', 'Card Processor', '7.50', 2, '2025-10-02 18:33:57');

-- --------------------------------------------------------

--
-- Table structure for table `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `entry_date` date NOT NULL,
  `account_type` enum('stock','cash','supplier','customer','other','bank','wallet','cargo') NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `branch_id`, `entry_date`, `account_type`, `amount`, `description`, `created_by`, `created_at`) VALUES
(0, 1, '2025-09-20', 'bank', '-100.00', 'Inter-branch settlement #2', 2, '2025-09-20 12:24:14'),
(0, 2, '2025-09-20', 'bank', '100.00', 'Inter-branch settlement #2', 2, '2025-09-20 12:24:14'),
(0, 1, '2025-09-20', 'bank', '-50.00', 'Inter-branch settlement #2', 2, '2025-09-20 12:32:52'),
(0, 2, '2025-09-20', 'bank', '50.00', 'Inter-branch settlement #2', 2, '2025-09-20 12:32:52'),
(0, 2, '2025-09-20', 'cash', '-100.00', 'Inter-branch settlement #3', 3, '2025-09-20 20:06:47'),
(0, 1, '2025-09-20', 'cash', '100.00', 'Inter-branch settlement #3', 3, '2025-09-20 20:06:47'),
(0, 1, '2025-09-22', 'supplier', '-1000.00', 'Cargo transfer ref 2554655hgffyu', 2, '2025-09-22 00:32:32'),
(0, 1, '2025-09-22', 'cargo', '1000.00', 'Cargo transfer ref 2554655hgffyu', 2, '2025-09-22 00:32:32'),
(0, 1, '2025-09-22', 'cash', '-1000.00', 'Cargo provider payment (cash)', 2, '2025-09-22 00:33:28'),
(0, 1, '2025-09-22', 'cargo', '-1000.00', 'Cargo provider payment', 2, '2025-09-22 00:33:28'),
(0, 1, '2025-09-22', 'cash', '-1000.00', 'Cargo provider payment (cash)', 2, '2025-09-22 00:40:08'),
(0, 1, '2025-09-22', 'cargo', '-1000.00', 'Cargo provider payment', 2, '2025-09-22 00:40:08'),
(0, 1, '2025-09-22', 'supplier', '-2500.00', 'Cargo transfer ref ', 2, '2025-09-22 01:32:21'),
(0, 1, '2025-09-22', 'cargo', '2500.00', 'Cargo transfer ref ', 2, '2025-09-22 01:32:21'),
(0, 2, '2025-09-22', 'cash', '-100.00', 'Inter-branch settlement #3', 3, '2025-09-22 16:31:24'),
(0, 1, '2025-09-22', 'cash', '100.00', 'Inter-branch settlement #3', 3, '2025-09-22 16:31:24'),
(0, 1, '2025-09-23', 'bank', '-500.00', 'Inter-branch settlement #5', 2, '2025-09-23 11:56:48'),
(0, 2, '2025-09-23', 'bank', '500.00', 'Inter-branch settlement #5', 2, '2025-09-23 11:56:48'),
(0, 1, '2025-09-23', 'cash', '-400.00', 'End of day transfer', 2, '2025-09-23 13:25:39'),
(0, 1, '2025-09-23', 'bank', '400.00', 'End of day transfer', 2, '2025-09-23 13:25:40'),
(0, 1, '2025-09-25', 'bank', '-500.00', 'Inter-branch settlement #5', 2, '2025-09-25 14:26:41'),
(0, 2, '2025-09-25', 'bank', '500.00', 'Inter-branch settlement #5', 2, '2025-09-25 14:26:41'),
(0, 1, '2025-09-25', 'cash', '-200.00', 'Inter-branch settlement #5', 2, '2025-09-25 17:49:47'),
(0, 2, '2025-09-25', 'cash', '200.00', 'Inter-branch settlement #5', 2, '2025-09-25 17:49:47'),
(0, 1, '2025-09-26', 'bank', '-8.25', 'Card commission fee for Sale #42', 2, '2025-09-26 11:53:23'),
(0, 1, '2025-09-26', 'other', '8.25', 'Card commission fee for Sale #42', 2, '2025-09-26 11:53:23'),
(0, 1, '2025-09-26', 'bank', '-7.50', 'Card commission fee for Sale #43', 2, '2025-09-26 11:59:41'),
(0, 1, '2025-09-26', 'other', '7.50', 'Card commission fee for Sale #43', 2, '2025-09-26 11:59:41'),
(0, 1, '2025-09-26', 'bank', '-2.50', 'Card commission fee for Sale #44', 2, '2025-09-26 12:06:21'),
(0, 1, '2025-09-26', 'other', '2.50', 'Card commission fee for Sale #44', 2, '2025-09-26 12:06:21'),
(0, 5, '2025-09-26', 'stock', '15000.00', 'Opening stock recorded from purchase page', 11, '2025-09-26 13:06:12'),
(0, 5, '2025-09-26', 'bank', '-7.50', 'Card commission fee for Sale #45', 11, '2025-09-26 13:18:20'),
(0, 5, '2025-09-26', 'other', '7.50', 'Card commission fee for Sale #45', 11, '2025-09-26 13:18:20'),
(0, 5, '2025-09-26', 'bank', '-7.50', 'Card commission fee for Sale #46', 11, '2025-09-26 23:33:27'),
(0, 5, '2025-09-26', 'other', '7.50', 'Card commission fee for Sale #46', 11, '2025-09-26 23:33:27'),
(0, 5, '2025-09-26', 'bank', '-7.50', 'Card commission fee for Sale #47', 11, '2025-09-26 23:49:58'),
(0, 5, '2025-09-26', 'other', '7.50', 'Card commission fee for Sale #47', 11, '2025-09-26 23:49:58'),
(0, 5, '2025-09-27', 'cash', '-500.00', 'Inter-branch settlement #8', 11, '2025-09-27 08:41:27'),
(0, 1, '2025-09-27', 'cash', '500.00', 'Inter-branch settlement #8', 11, '2025-09-27 08:41:27'),
(0, 1, '2025-09-30', 'cash', '-100.00', 'trhtr', 2, '2025-09-30 18:14:34'),
(0, 1, '2025-09-30', 'bank', '100.00', 'trhtr', 2, '2025-09-30 18:14:34'),
(0, 1, '2025-09-30', 'cash', '-100.00', 'End of day transfer', 2, '2025-09-30 18:16:10'),
(0, 1, '2025-09-30', 'bank', '100.00', 'End of day transfer', 2, '2025-09-30 18:16:10'),
(0, 8, '2025-09-30', 'stock', '2500.00', 'Opening stock recorded from purchase page', 14, '2025-09-30 18:44:49'),
(0, 8, '2025-09-30', 'cash', '-250.00', 'By Ruban', 14, '2025-09-30 19:10:40'),
(0, 8, '2025-09-30', 'bank', '250.00', 'By Ruban', 14, '2025-09-30 19:10:40'),
(0, 8, '2025-10-01', 'cash', '-500.00', 'End of day transfer to BANK: NSB (7745625)', 14, '2025-10-01 08:13:57'),
(0, 8, '2025-10-01', 'bank', '500.00', 'End of day transfer to BANK: NSB (7745625)', 14, '2025-10-01 08:13:57'),
(0, 8, '2025-10-01', 'cash', '-100.00', 'Owner Withdrawal (write_off.php)', 14, '2025-10-01 09:50:55'),
(0, 8, '2025-10-01', 'bank', '-150.00', 'Owner Withdrawal (write_off.php)', 14, '2025-10-01 10:13:29'),
(0, 5, '2025-10-02', 'bank', '-7.50', 'Card commission fee for Sale #62', 11, '2025-10-02 10:08:09'),
(0, 5, '2025-10-02', 'other', '7.50', 'Card commission fee for Sale #62', 11, '2025-10-02 10:08:09'),
(0, 1, '2025-10-02', 'bank', '-100.00', 'Inter-branch settlement #5', 2, '2025-10-02 18:09:19'),
(0, 2, '2025-10-02', 'bank', '100.00', 'Inter-branch settlement #5', 2, '2025-10-02 18:09:19'),
(0, 1, '2025-10-02', 'bank', '-7.50', 'Card commission fee for Sale #71', 2, '2025-10-02 18:33:57'),
(0, 1, '2025-10-02', 'other', '7.50', 'Card commission fee for Sale #71', 2, '2025-10-02 18:33:57'),
(0, 9, '2025-10-03', 'stock', '1500.00', 'Opening stock recorded from purchase page', 15, '2025-10-03 08:34:08'),
(0, 2, '2025-10-06', 'stock', '-1200.00', 'Adj #4 — P:18/B:47 — Damage', 3, '2025-10-06 17:55:27'),
(0, 2, '2025-10-06', 'other', '1200.00', 'Drawings — Stock Adjustment #4 (P:18/B:47) — Damage', 3, '2025-10-06 17:55:27');

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `size` varchar(50) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `unit` varchar(50) DEFAULT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `description` text,
  `category_id` int(11) DEFAULT NULL,
  `brand_id` int(11) DEFAULT NULL,
  `unit_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`id`, `name`, `size`, `barcode`, `category`, `unit`, `cost_price`, `selling_price`, `description`, `category_id`, `brand_id`, `unit_id`) VALUES
(1, 'Bath Soap', '100g', '200001', NULL, NULL, '50.00', '90.00', NULL, 2, 1, 1),
(2, 'Shampoo', '250ml', '200002', NULL, NULL, '180.00', '300.00', NULL, 2, 2, 2),
(3, 'Conditioner', '250ml', '200003', NULL, NULL, '200.00', '330.00', NULL, 2, 2, 2),
(4, 'Facial Tissue', '100pcs', '200004', NULL, NULL, '60.00', '100.00', NULL, 2, 4, 4),
(5, 'Body Lotion', '200ml', '200005', NULL, NULL, '250.00', '400.00', NULL, 2, 5, 2),
(6, 'Dish Sponge', '2pcs', '200006', NULL, NULL, '20.00', '45.00', NULL, 7, 6, 6),
(7, 'Air Freshener', '300ml', '200007', NULL, NULL, '150.00', '250.00', NULL, 7, 7, 2),
(8, 'Rice', '10kg', '200008', NULL, NULL, '1000.00', '1600.00', NULL, 9, 8, 8),
(9, 'Sugar', '2kg', '200009', NULL, NULL, '300.00', '480.00', NULL, 9, 8, 8),
(10, 'Flour', '1kg', '200010', NULL, NULL, '150.00', '260.00', NULL, 9, 10, 8),
(11, 'Cooking Oil', '750ml', '200011', NULL, NULL, '350.00', '550.00', NULL, 9, 11, 2),
(12, 'Tea Leaves', '500g', '200012', NULL, NULL, '200.00', '350.00', NULL, 13, 12, 12),
(13, 'Coffee Beans', '500g', '200013', NULL, NULL, '450.00', '720.00', NULL, 13, 12, 12),
(14, 'Canned Tuna', '185g', '200014', NULL, NULL, '120.00', '200.00', NULL, 9, 14, 14),
(15, 'Canned Corn', '300g', '200015', NULL, NULL, '100.00', '170.00', NULL, 9, 14, 14),
(16, 'ice cream', 'm', '125643', NULL, NULL, '200.00', '300.00', '', 11, 12, NULL),
(17, 'Biscuit', 'm', '56767', NULL, NULL, '100.00', '120.00', '', 13, 10, NULL),
(18, 'lunch box', 'Full', '256479', NULL, NULL, '1200.00', '2000.00', '', 43, 12, 31),
(19, 'lunch box', 'S', '25467', NULL, NULL, '200.00', '350.00', '', 19, 16, 1),
(20, 'lunch box', 'M', '25647', NULL, NULL, '400.00', '650.00', '', 29, 43, 31),
(21, 'lunch box', 'L', '64666', NULL, NULL, '600.00', '1000.00', '', 36, 31, 31);

-- --------------------------------------------------------

--
-- Table structure for table `purchases`
--

CREATE TABLE `purchases` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `purchase_date` datetime NOT NULL,
  `purchased_by` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchases`
--

INSERT INTO `purchases` (`id`, `branch_id`, `supplier_id`, `purchase_date`, `purchased_by`) VALUES
(1, 1, 1, '2025-09-19 10:05:55', 2),
(2, 7, 1, '2025-09-20 01:06:39', 13),
(3, 1, 1, '2025-09-22 00:36:50', 2),
(4, 1, 1, '2025-09-22 00:38:58', 2),
(5, 7, 1, '2025-09-23 10:11:15', 13),
(6, 5, 1, '2025-09-27 00:16:24', 11),
(7, 5, 1, '2025-09-27 00:38:09', 11),
(8, 7, 1, '2025-09-27 00:49:06', 13),
(9, 7, 1, '2025-09-27 01:04:30', 13),
(10, 2, 1, '2025-09-29 11:37:18', 3),
(11, 1, 1, '2025-09-30 08:48:27', 2),
(12, 8, 1, '2025-09-30 19:01:52', 14),
(13, 8, 1, '2025-10-01 15:25:40', 14),
(14, 5, 1, '2025-10-02 08:30:12', 11),
(15, 5, 1, '2025-10-02 09:26:33', 11),
(16, 1, 1, '2025-10-02 15:54:04', 2),
(17, 1, 1, '2025-10-02 16:13:03', 2),
(18, 5, 1, '2025-10-06 09:38:36', 11),
(19, 2, 1, '2025-10-06 16:46:10', 3);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_no` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `cost_pin` varchar(100) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `stock_place` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_items`
--

INSERT INTO `purchase_items` (`id`, `purchase_id`, `product_id`, `batch_no`, `quantity`, `cost_price`, `cost_pin`, `selling_price`, `expiry_date`, `stock_place`) VALUES
(1, 1, 7, '3', 30, '160.00', 'HJK', '260.00', '2025-10-07', NULL),
(2, 2, 7, '1', 10, '120.00', 'KJG', '200.00', '2025-09-30', NULL),
(3, 2, 4, '1', 15, '150.00', 'KHG', '180.00', '2025-10-08', NULL),
(4, 3, 7, '3', 50, '160.00', 'KJHK', '260.00', NULL, NULL),
(5, 4, 7, '3', 50, '160.00', 'KJHK', '260.00', NULL, NULL),
(6, 5, 7, '1', 10, '120.00', 'KJHK', '200.00', '2025-09-30', NULL),
(7, 6, 15, '1', 10, '140.00', 'GHJ', '160.00', '2025-10-09', NULL),
(8, 7, 3, '1', 10, '120.00', 'dgjj', '150.00', '2025-10-07', NULL),
(9, 8, 3, '1', 100, '100.00', 'rrtyhr', '150.00', '2025-10-07', NULL),
(10, 9, 4, '1', 10, '150.00', 'FGH', '180.00', '2025-10-08', NULL),
(11, 10, 7, '1', 10, '150.00', 'JHG', '300.00', '2025-10-08', NULL),
(14, 11, 7, '4', 50, '190.00', 'KLM', '300.00', '2025-10-11', NULL),
(15, 11, 13, '1', 15, '120.00', 'GHK', '250.00', '2025-10-06', NULL),
(16, 12, 7, '1', 10, '150.00', 'FGH', '250.00', '2025-10-06', NULL),
(17, 13, 4, '1', 10, '150.00', 'ugyf', '400.00', '2025-11-07', NULL),
(18, 14, 16, '1', 10, '200.00', 'FGH', '300.00', '2025-11-05', NULL),
(19, 15, 17, '1', 10, '100.00', 'fj', '120.00', '2025-11-05', NULL),
(20, 16, 12, '03', 10, '200.00', '', '350.00', '2027-01-30', NULL),
(22, 17, 7, '4', 10, '190.00', 'KLM', '300.00', '2025-10-11', 'Top BarSheet'),
(23, 18, 16, '1', 10, '200.00', 'FGH', '300.00', '2025-11-05', NULL),
(24, 19, 18, '1', 10, '1200.00', 'fgf', '2000.00', '2025-10-13', 'top');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_payments`
--

CREATE TABLE `purchase_payments` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_by` int(11) NOT NULL,
  `paid_at` datetime NOT NULL,
  `method` varchar(50) NOT NULL DEFAULT 'cash',
  `cheque_number` varchar(100) DEFAULT NULL,
  `bank_name` varchar(150) DEFAULT NULL,
  `bank_branch` varchar(150) DEFAULT NULL,
  `deposit_date` date DEFAULT NULL,
  `deposit_bank_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `purchase_payments`
--

INSERT INTO `purchase_payments` (`id`, `purchase_id`, `amount`, `paid_by`, `paid_at`, `method`, `cheque_number`, `bank_name`, `bank_branch`, `deposit_date`, `deposit_bank_id`) VALUES
(1, 1, '1000.00', 2, '2025-09-23 12:50:07', 'cash', NULL, NULL, NULL, NULL, NULL),
(2, 3, '500.00', 2, '2025-09-24 18:51:37', 'cash', NULL, NULL, NULL, NULL, NULL),
(3, 6, '1400.00', 11, '2025-09-27 11:03:12', 'cheque', '66676', 'egesg', 'dsgsdgdg', '2025-10-08', NULL),
(4, 7, '200.00', 11, '2025-09-27 11:30:24', 'cheque', '464364', 'rdfhdrh', 'fdh', '2025-09-27', NULL),
(5, 7, '500.00', 11, '2025-09-27 12:46:01', 'cheque', '545475', 'hfgj', 'gjtrjttr', '2025-09-27', NULL),
(6, 10, '500.00', 3, '2025-09-29 11:37:48', 'cash', NULL, NULL, NULL, NULL, NULL),
(7, 12, '300.00', 14, '2025-10-01 08:51:06', 'cheque', '25641', 'NSB', 'JAFFNA', '2025-10-01', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `purchase_returns`
--

CREATE TABLE `purchase_returns` (
  `id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `return_date` datetime NOT NULL,
  `processed_by` int(11) NOT NULL,
  `total_refund` decimal(12,2) NOT NULL DEFAULT '0.00',
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `purchase_return_items`
--

CREATE TABLE `purchase_return_items` (
  `id` int(11) NOT NULL,
  `purchase_return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reversal_audit`
--

CREATE TABLE `reversal_audit` (
  `id` int(11) NOT NULL,
  `log_id` int(11) NOT NULL,
  `reversed_by` int(11) NOT NULL,
  `reversed_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`) VALUES
(1, 'admin'),
(2, 'manager'),
(3, 'cashier'),
(4, 'inventory_officer');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `invoice_no` int(11) DEFAULT NULL,
  `sale_date` datetime NOT NULL,
  `sold_by` int(11) NOT NULL,
  `total_amount` decimal(12,2) DEFAULT NULL,
  `overall_discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_received` decimal(12,2) NOT NULL DEFAULT '0.00',
  `change_given` decimal(12,2) NOT NULL DEFAULT '0.00',
  `customer_id` int(11) DEFAULT NULL,
  `worker_number` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `branch_id`, `invoice_no`, `sale_date`, `sold_by`, `total_amount`, `overall_discount`, `amount_received`, `change_given`, `customer_id`, `worker_number`) VALUES
(1, 1, 1, '2025-09-17 13:18:36', 2, '580.00', '0.00', '580.00', '0.00', NULL, NULL),
(2, 1, 2, '2025-09-17 13:25:47', 2, '330.00', '0.00', '80.00', '0.00', NULL, NULL),
(3, 1, 3, '2025-09-18 09:33:43', 2, '330.00', '0.00', '330.00', '0.00', NULL, NULL),
(4, 1, 4, '2025-09-18 09:35:05', 2, '250.00', '0.00', '250.00', '0.00', NULL, NULL),
(5, 1, 5, '2025-09-18 09:51:27', 2, '250.00', '0.00', '500.00', '250.00', NULL, NULL),
(6, 1, 6, '2025-09-18 09:52:32', 2, '300.00', '0.00', '50.00', '0.00', NULL, NULL),
(7, 1, 7, '2025-09-18 10:09:23', 2, '45.00', '0.00', '0.00', '285.00', NULL, NULL),
(8, 1, 8, '2025-09-18 11:15:12', 2, '580.00', '0.00', '1000.00', '420.00', NULL, NULL),
(9, 1, 9, '2025-09-18 11:16:12', 2, '330.00', '0.00', '0.00', '0.00', NULL, NULL),
(10, 1, 10, '2025-09-18 11:24:50', 2, '375.00', '0.00', '500.00', '125.00', NULL, NULL),
(11, 1, 11, '2025-09-18 11:36:09', 2, '730.00', '0.00', '1000.00', '270.00', NULL, NULL),
(12, 1, 12, '2025-09-18 12:03:35', 2, '1227.00', '0.00', '1500.00', '273.00', NULL, NULL),
(13, 1, 13, '2025-09-18 12:08:50', 2, '200.00', '0.00', '500.00', '300.00', NULL, NULL),
(14, 1, 14, '2025-09-18 12:09:36', 2, '100.00', '0.00', '500.00', '400.00', NULL, NULL),
(15, 1, 15, '2025-09-18 12:11:28', 2, '250.00', '0.00', '50.00', '0.00', NULL, NULL),
(16, 1, 16, '2025-09-18 17:11:33', 2, '880.00', '0.00', '505.00', '0.00', NULL, NULL),
(17, 1, 17, '2025-09-18 17:33:48', 2, '1205.00', '0.00', '0.00', '0.00', 1, '1'),
(18, 1, 18, '2025-09-18 17:37:10', 2, '400.00', '0.00', '0.00', '0.00', 1, '1'),
(19, 1, 19, '2025-09-19 09:04:53', 2, '1740.00', '0.00', '1740.00', '0.00', 1, '1'),
(20, 1, 20, '2025-09-19 09:10:45', 2, '250.00', '0.00', '500.00', '250.00', NULL, NULL),
(21, 1, 21, '2025-09-19 09:11:38', 2, '400.00', '0.00', '150.00', '0.00', NULL, NULL),
(22, 1, 22, '2025-09-19 09:16:39', 2, '550.00', '30.00', '550.00', '0.00', NULL, NULL),
(23, 1, 23, '2025-09-19 10:29:46', 2, '1350.00', '100.00', '1500.00', '150.00', NULL, NULL),
(24, 1, 24, '2025-09-19 11:08:10', 2, '270.00', '75.00', '300.00', '30.00', NULL, NULL),
(25, 1, 25, '2025-09-19 13:55:48', 2, '90.00', '0.00', '0.00', '0.00', 1, NULL),
(26, 7, 1, '2025-09-20 01:09:08', 13, '350.00', '30.00', '0.00', '0.00', 2, NULL),
(27, 7, 2, '2025-09-20 01:10:24', 13, '380.00', '0.00', '0.00', '0.00', NULL, NULL),
(28, 7, 3, '2025-09-20 01:10:50', 13, '580.00', '0.00', '1000.00', '420.00', NULL, NULL),
(29, 7, 4, '2025-09-20 01:11:59', 13, '400.00', '0.00', '400.00', '0.00', 2, NULL),
(30, 2, 1, '2025-09-20 09:10:00', 3, '720.00', '0.00', '1000.00', '280.00', NULL, NULL),
(31, 2, 2, '2025-09-20 12:52:07', 3, '1000.00', '0.00', '280.00', '0.00', NULL, NULL),
(32, 2, 3, '2025-09-20 12:53:43', 3, '170.00', '0.00', '200.00', '30.00', NULL, NULL),
(33, 2, 4, '2025-09-20 12:57:29', 3, '170.00', '0.00', '200.00', '30.00', NULL, NULL),
(34, 7, 5, '2025-09-23 10:12:11', 13, '200.00', '0.00', '0.00', '0.00', 2, NULL),
(35, 1, 26, '2025-09-23 13:25:17', 2, '6315.00', '0.00', '7000.00', '685.00', NULL, NULL),
(36, 1, 27, '2025-09-25 13:49:21', 2, '800.00', '90.00', '0.00', '0.00', 2, '1'),
(37, 1, 28, '2025-09-25 13:57:54', 2, '260.00', '0.00', '260.00', '0.00', 2, '1'),
(38, 1, 29, '2025-09-25 14:11:53', 2, '1000.00', '35.00', '0.00', '0.00', 1, '1'),
(39, 1, 30, '2025-09-26 09:37:48', 2, '260.00', '0.00', '260.00', '0.00', NULL, NULL),
(40, 1, 31, '2025-09-26 09:40:38', 2, '200.00', '0.00', '200.00', '0.00', NULL, NULL),
(41, 1, 32, '2025-09-26 11:34:35', 2, '300.00', '40.00', '300.00', '0.00', NULL, NULL),
(42, 1, 33, '2025-09-26 11:53:23', 2, '330.00', '0.00', '330.00', '0.00', NULL, NULL),
(43, 1, 34, '2025-09-26 11:59:41', 2, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(44, 1, 35, '2025-09-26 12:06:21', 2, '100.00', '0.00', '100.00', '0.00', NULL, NULL),
(45, 5, 1, '2025-09-26 13:18:20', 11, '300.00', '0.00', '300.00', '0.00', 3, NULL),
(46, 5, 2, '2025-09-26 23:33:27', 11, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(47, 5, 3, '2025-09-26 23:49:57', 11, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(48, 1, 36, '2025-09-28 08:13:39', 2, '200.00', '0.00', '0.00', '0.00', NULL, NULL),
(49, 1, 37, '2025-09-28 08:29:51', 2, '1000.00', '0.00', '1000.00', '0.00', 3, NULL),
(50, 1, 38, '2025-09-28 08:31:51', 2, '2250.00', '0.00', '3000.00', '750.00', 3, NULL),
(51, 1, 39, '2025-09-30 09:00:37', 2, '205.00', '0.00', '300.00', '95.00', 3, '1'),
(52, 8, 1, '2025-09-30 19:02:13', 14, '250.00', '0.00', '300.00', '50.00', NULL, NULL),
(53, 8, 2, '2025-09-30 19:37:49', 14, '1250.00', '0.00', '1500.00', '250.00', NULL, NULL),
(54, 8, 3, '2025-10-01 08:00:17', 14, '750.00', '0.00', '1000.00', '250.00', NULL, NULL),
(55, 8, 4, '2025-10-01 15:04:11', 14, '300.00', '0.00', '50.00', '0.00', NULL, NULL),
(56, 8, 5, '2025-10-01 15:22:49', 14, '250.00', '0.00', '300.00', '50.00', NULL, '2'),
(57, 5, 4, '2025-10-02 08:31:59', 11, '600.00', '0.00', '1000.00', '400.00', NULL, NULL),
(58, 5, 5, '2025-10-02 08:34:14', 11, '300.00', '0.00', '0.00', '300.00', NULL, NULL),
(59, 5, 6, '2025-10-02 09:22:47', 11, '600.00', '0.00', '1000.00', '400.00', NULL, NULL),
(60, 5, 7, '2025-10-02 09:54:28', 11, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(61, 5, 8, '2025-10-02 09:59:00', 11, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(62, 5, 9, '2025-10-02 10:08:09', 11, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(63, 5, 0, '2025-10-02 10:23:15', 11, '720.00', '0.00', '120.00', '0.00', NULL, NULL),
(64, 5, 10, '2025-10-02 10:24:34', 11, '300.00', '0.00', '500.00', '200.00', NULL, NULL),
(65, 5, 11, '2025-10-02 10:58:27', 11, '300.00', '0.00', '1000.00', '700.00', NULL, NULL),
(66, 5, 12, '2025-10-02 10:59:57', 11, '600.00', '0.00', '1000.00', '400.00', NULL, '3'),
(70, 5, 13, '2025-10-02 11:06:32', 11, '240.00', '0.00', '0.00', '360.00', NULL, NULL),
(71, 1, 40, '2025-10-02 18:33:57', 2, '300.00', '0.00', '300.00', '0.00', NULL, NULL),
(72, 1, 41, '2025-10-02 18:36:24', 2, '100.00', '0.00', '100.00', '0.00', NULL, NULL),
(73, 1, 42, '2025-10-02 18:36:58', 2, '100.00', '0.00', '0.00', '0.00', 3, NULL),
(74, 1, 43, '2025-10-02 18:37:48', 2, '100.00', '0.00', '100.00', '0.00', 3, NULL),
(75, 1, 44, '2025-10-02 18:39:45', 2, '100.00', '0.00', '0.00', '0.00', 2, NULL),
(76, 1, NULL, '2025-10-02 19:01:29', 2, '100.00', '0.00', '0.00', '0.00', 1, '1'),
(77, 9, NULL, '2025-10-03 08:35:45', 15, '400.00', '0.00', '0.00', '0.00', NULL, '4'),
(78, 9, NULL, '2025-10-03 08:36:41', 15, '400.00', '0.00', '0.00', '0.00', NULL, NULL),
(79, 1, NULL, '2025-10-03 22:11:21', 2, '250.00', '0.00', '0.00', '0.00', NULL, NULL),
(80, 1, NULL, '2025-10-03 22:13:51', 2, '500.00', '0.00', '0.00', '0.00', NULL, NULL),
(81, 1, NULL, '2025-10-03 22:29:53', 2, '160.00', '0.00', '200.00', '40.00', NULL, NULL),
(82, 1, NULL, '2025-10-03 22:30:29', 2, '330.00', '0.00', '500.00', '170.00', NULL, '1'),
(83, 1, NULL, '2025-10-03 22:31:06', 2, '2000.00', '30.00', '5000.00', '3000.00', NULL, NULL),
(84, 1, NULL, '2025-10-03 22:44:57', 2, '600.00', '50.00', '1000.00', '400.00', NULL, NULL),
(85, 1, NULL, '2025-10-03 22:45:45', 2, '1500.00', '20.00', '2000.00', '500.00', NULL, NULL),
(86, 1, NULL, '2025-10-03 23:24:11', 2, '1300.00', '20.00', '1500.00', '200.00', NULL, NULL),
(87, 1, NULL, '2025-10-03 23:28:55', 2, '600.00', '32.50', '1000.00', '400.00', NULL, NULL),
(88, 1, NULL, '2025-10-03 23:37:12', 2, '45.00', '0.00', '50.00', '5.00', NULL, NULL),
(89, 1, NULL, '2025-10-03 23:38:55', 2, '1800.00', '0.00', '0.00', '0.00', NULL, NULL),
(90, 1, 45, '2025-10-03 23:42:30', 2, '745.00', '0.00', '0.00', '1055.00', NULL, '1'),
(91, 1, NULL, '2025-10-04 07:13:50', 2, '850.00', '0.00', '0.00', '0.00', NULL, '1'),
(92, 1, NULL, '2025-10-04 07:31:55', 2, '2200.00', '0.00', '0.00', '0.00', NULL, NULL),
(93, 1, NULL, '2025-10-04 07:39:39', 2, '1200.00', '0.00', '0.00', '0.00', NULL, '1'),
(94, 1, 46, '2025-10-04 07:41:48', 2, '440.00', '20.00', '500.00', '60.00', NULL, '1'),
(95, 1, 47, '2025-10-04 07:44:12', 2, '730.00', '0.00', '270.00', '0.00', NULL, '1'),
(96, 1, 48, '2025-10-04 07:55:51', 2, '500.00', '14.00', '1000.00', '500.00', NULL, NULL),
(97, 1, 49, '2025-10-04 08:34:09', 2, '1040.00', '0.00', '876.00', '0.00', NULL, '1'),
(98, 1, 50, '2025-10-04 08:52:09', 2, '2250.00', '50.00', '1000.00', '0.00', 3, NULL),
(99, 1, 51, '2025-10-04 14:38:19', 2, '395.00', '0.00', '500.00', '105.00', NULL, NULL),
(100, 1, 52, '2025-10-04 14:45:06', 2, '505.00', '0.00', '110.00', '0.00', NULL, '1'),
(101, 1, 53, '2025-10-04 14:47:03', 2, '300.00', '0.00', '500.00', '200.00', NULL, '1'),
(102, 5, 14, '2025-10-05 08:57:00', 11, '240.00', '0.00', '240.00', '0.00', 3, '3'),
(103, 5, 15, '2025-10-05 09:00:07', 11, '300.00', '0.00', '0.00', '0.00', 1, '3'),
(104, 5, 16, '2025-10-05 09:29:55', 11, '300.00', '0.00', '0.00', '0.00', 2, '3'),
(105, 5, 17, '2025-10-05 09:33:24', 11, '300.00', '0.00', '300.00', '0.00', 2, '3'),
(106, 5, 18, '2025-10-05 09:39:36', 11, '300.00', '0.00', '0.00', '0.00', 3, NULL),
(107, 5, 19, '2025-10-05 09:42:03', 11, '300.00', '0.00', '300.00', '0.00', 1, NULL),
(108, 5, 20, '2025-10-06 09:37:20', 11, '300.00', '0.00', '300.00', '0.00', 3, '3'),
(109, 5, 21, '2025-10-06 09:38:53', 11, '600.00', '0.00', '0.00', '0.00', 3, '3'),
(110, 1, 54, '2025-10-06 12:38:35', 2, '250.00', '0.00', '500.00', '250.00', NULL, NULL),
(111, 2, 5, '2025-10-06 13:13:55', 3, '0.00', '0.00', '200.00', '200.00', NULL, NULL),
(112, 2, 6, '2025-10-06 18:14:47', 3, '0.00', '0.00', '200.00', '200.00', NULL, NULL),
(113, 2, 7, '2025-10-06 18:39:29', 3, '720.00', '0.00', '1000.00', '280.00', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `discount_type` varchar(20) DEFAULT NULL,
  `discount_value` decimal(10,2) DEFAULT '0.00',
  `tax_rate` decimal(5,2) DEFAULT '0.00'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `product_id`, `batch_id`, `quantity`, `selling_price`, `discount_type`, `discount_value`, `tax_rate`) VALUES
(1, 1, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(2, 1, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(3, 3, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(4, 4, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(5, 5, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(6, 8, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(7, 8, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(8, 9, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(9, 10, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(10, 10, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(11, 11, 7, 7, 3, '250.00', 'fixed', '20.00', '0.00'),
(12, 11, 6, 6, 1, '45.00', 'fixed', '5.00', '0.00'),
(13, 12, 7, 7, 3, '250.00', 'percentage', '20.00', '0.00'),
(14, 12, 3, 3, 2, '330.00', 'percentage', '5.00', '0.00'),
(15, 13, 7, 7, 1, '250.00', 'fixed', '50.00', '0.00'),
(16, 14, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(17, 17, 7, 7, 2, '250.00', NULL, '0.00', '0.00'),
(18, 17, 3, 3, 2, '330.00', NULL, '0.00', '0.00'),
(19, 17, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(20, 18, 5, 5, 1, '400.00', NULL, '0.00', '0.00'),
(21, 19, 7, 7, 3, '250.00', 'fixed', '50.00', '0.00'),
(22, 19, 3, 3, 3, '330.00', 'fixed', '30.00', '0.00'),
(23, 19, 6, 6, 6, '45.00', 'fixed', '5.00', '0.00'),
(24, 20, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(25, 22, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(26, 22, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(27, 23, 3, 3, 4, '330.00', 'fixed', '30.00', '0.00'),
(28, 23, 7, 16, 1, '250.00', NULL, '0.00', '0.00'),
(29, 24, 3, 3, 1, '330.00', 'fixed', '30.00', '0.00'),
(30, 24, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(31, 25, 1, 1, 1, '90.00', NULL, '0.00', '0.00'),
(32, 26, 7, 17, 1, '200.00', NULL, '0.00', '0.00'),
(33, 26, 4, 18, 1, '180.00', NULL, '0.00', '0.00'),
(34, 27, 7, 17, 1, '200.00', NULL, '0.00', '0.00'),
(35, 27, 4, 18, 1, '180.00', NULL, '0.00', '0.00'),
(36, 28, 7, 17, 2, '200.00', NULL, '0.00', '0.00'),
(37, 28, 4, 18, 1, '180.00', NULL, '0.00', '0.00'),
(38, 29, 7, 17, 2, '200.00', NULL, '0.00', '0.00'),
(39, 30, 15, 15, 1, '170.00', NULL, '0.00', '0.00'),
(40, 30, 11, 11, 1, '550.00', NULL, '0.00', '0.00'),
(41, 32, 15, 15, 1, '170.00', NULL, '0.00', '0.00'),
(42, 33, 15, 15, 1, '170.00', NULL, '0.00', '0.00'),
(43, 34, 7, 17, 1, '200.00', NULL, '0.00', '0.00'),
(44, 35, 14, 23, 5, '200.00', NULL, '0.00', '0.00'),
(45, 35, 6, 6, 5, '45.00', NULL, '0.00', '0.00'),
(46, 35, 15, 19, 4, '170.00', NULL, '0.00', '0.00'),
(47, 35, 2, 2, 5, '300.00', NULL, '0.00', '0.00'),
(48, 35, 9, 20, 4, '480.00', NULL, '0.00', '0.00'),
(49, 35, 3, 3, 3, '330.00', NULL, '0.00', '0.00'),
(50, 36, 1, 1, 1, '90.00', NULL, '0.00', '0.00'),
(51, 36, 15, 19, 1, '170.00', NULL, '0.00', '0.00'),
(52, 36, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(53, 36, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(54, 37, 7, 7, 1, '260.00', NULL, '0.00', '0.00'),
(55, 38, 15, 19, 3, '170.00', NULL, '0.00', '0.00'),
(56, 38, 6, 6, 5, '45.00', NULL, '0.00', '0.00'),
(57, 38, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(58, 39, 7, 7, 1, '260.00', NULL, '0.00', '0.00'),
(59, 40, 14, 23, 1, '200.00', NULL, '0.00', '0.00'),
(60, 41, 1, 1, 1, '90.00', NULL, '0.00', '0.00'),
(61, 41, 7, 7, 1, '250.00', NULL, '0.00', '0.00'),
(62, 42, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(63, 43, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(64, 44, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(65, 45, 7, 24, 1, '300.00', NULL, '0.00', '0.00'),
(66, 46, 7, 24, 1, '300.00', NULL, '0.00', '0.00'),
(67, 47, 7, 24, 1, '300.00', NULL, '0.00', '0.00'),
(68, 48, 14, 23, 1, '200.00', NULL, '0.00', '0.00'),
(69, 49, 14, 23, 1, '200.00', NULL, '0.00', '0.00'),
(70, 49, 7, 30, 2, '250.00', NULL, '0.00', '0.00'),
(71, 49, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(72, 50, 5, 5, 3, '400.00', NULL, '0.00', '0.00'),
(73, 50, 12, 22, 3, '350.00', NULL, '0.00', '0.00'),
(74, 51, 15, 33, 1, '160.00', NULL, '0.00', '0.00'),
(75, 51, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(76, 52, 7, 39, 1, '250.00', NULL, '0.00', '0.00'),
(77, 53, 7, 39, 5, '250.00', NULL, '0.00', '0.00'),
(78, 54, 7, 39, 3, '250.00', NULL, '0.00', '0.00'),
(79, 56, 7, 39, 1, '250.00', NULL, '0.00', '0.00'),
(80, 57, 16, 44, 2, '300.00', NULL, '0.00', '0.00'),
(81, 59, 16, 44, 2, '300.00', NULL, '0.00', '0.00'),
(82, 60, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(83, 61, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(84, 62, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(85, 63, 17, 45, 6, '120.00', NULL, '0.00', '0.00'),
(86, 64, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(87, 65, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(88, 66, 16, 44, 2, '300.00', NULL, '0.00', '0.00'),
(89, 70, 17, 45, 2, '120.00', NULL, '0.00', '0.00'),
(90, 71, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(91, 72, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(92, 73, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(93, 74, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(94, 75, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(95, 76, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(96, 77, 4, 46, 1, '400.00', NULL, '0.00', '0.00'),
(97, 78, 4, 46, 1, '400.00', NULL, '0.00', '0.00'),
(98, 79, 7, 30, 1, '250.00', NULL, '0.00', '0.00'),
(99, 80, 6, 6, 6, '45.00', 'fixed', '5.00', '0.00'),
(100, 80, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(101, 81, 15, 33, 1, '160.00', NULL, '0.00', '0.00'),
(102, 82, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(103, 83, 2, 2, 7, '300.00', 'fixed', '10.00', '0.00'),
(104, 84, 9, 20, 1, '480.00', 'fixed', '30.00', '0.00'),
(105, 84, 6, 6, 5, '45.00', 'fixed', '5.00', '0.00'),
(106, 85, 2, 2, 4, '300.00', 'fixed', '20.00', '0.00'),
(107, 85, 5, 5, 1, '400.00', NULL, '0.00', '0.00'),
(108, 86, 12, 22, 4, '350.00', 'fixed', '20.00', '0.00'),
(109, 87, 12, 22, 1, '350.00', 'percentage', '5.00', '0.00'),
(110, 87, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(111, 88, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(112, 89, 12, 22, 3, '350.00', 'fixed', '50.00', '0.00'),
(113, 89, 2, 2, 3, '300.00', NULL, '0.00', '0.00'),
(114, 90, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(115, 90, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(116, 90, 5, 5, 1, '400.00', NULL, '0.00', '0.00'),
(117, 91, 2, 2, 3, '300.00', 'fixed', '10.00', '0.00'),
(118, 92, 2, 2, 6, '300.00', 'fixed', '10.00', '0.00'),
(119, 92, 4, 4, 5, '100.00', NULL, '0.00', '0.00'),
(120, 93, 5, 5, 3, '400.00', 'fixed', '10.00', '0.00'),
(121, 93, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(122, 94, 6, 6, 4, '45.00', 'fixed', '5.00', '0.00'),
(123, 94, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(124, 95, 3, 3, 1, '330.00', NULL, '0.00', '0.00'),
(125, 95, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(126, 95, 4, 4, 1, '100.00', NULL, '0.00', '0.00'),
(127, 96, 6, 6, 4, '45.00', 'fixed', '4.00', '0.00'),
(128, 96, 12, 22, 1, '350.00', NULL, '0.00', '0.00'),
(129, 97, 3, 3, 2, '330.00', 'fixed', '10.00', '0.00'),
(130, 97, 5, 5, 1, '400.00', NULL, '0.00', '0.00'),
(131, 98, 2, 2, 2, '300.00', NULL, '0.00', '0.00'),
(132, 98, 5, 5, 2, '400.00', NULL, '0.00', '0.00'),
(133, 98, 3, 3, 3, '330.00', 'fixed', '30.00', '0.00'),
(134, 99, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(135, 99, 12, 22, 1, '350.00', NULL, '0.00', '0.00'),
(136, 100, 15, 33, 1, '160.00', NULL, '0.00', '0.00'),
(137, 100, 6, 6, 1, '45.00', NULL, '0.00', '0.00'),
(138, 100, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(139, 101, 2, 2, 1, '300.00', NULL, '0.00', '0.00'),
(140, 102, 17, 45, 2, '120.00', NULL, '0.00', '0.00'),
(141, 103, 7, 24, 1, '300.00', NULL, '0.00', '0.00'),
(142, 104, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(143, 105, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(144, 106, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(145, 107, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(146, 108, 16, 44, 1, '300.00', NULL, '0.00', '0.00'),
(147, 109, 16, 44, 2, '300.00', NULL, '0.00', '0.00'),
(148, 110, 7, 30, 1, '250.00', NULL, '0.00', '0.00'),
(149, 111, 15, 15, 1, '170.00', NULL, '0.00', '0.00'),
(150, 112, 14, 14, 1, '200.00', NULL, '0.00', '0.00'),
(151, 113, 13, 13, 1, '720.00', NULL, '0.00', '0.00');

-- --------------------------------------------------------

--
-- Table structure for table `sale_payments`
--

CREATE TABLE `sale_payments` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `method` varchar(50) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `cheque_number` varchar(100) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `bank_branch` varchar(100) DEFAULT NULL,
  `deposit_date` date DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_payments`
--

INSERT INTO `sale_payments` (`id`, `sale_id`, `method`, `amount`, `cheque_number`, `bank_name`, `bank_branch`, `deposit_date`, `created_at`) VALUES
(1, 1, 'cash', '580.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(2, 2, 'cash', '80.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(3, 3, 'cash', '330.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(4, 4, 'cash', '250.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(5, 5, 'cash', '250.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(6, 6, 'cash', '50.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(7, 7, 'cash', '-285.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(8, 8, 'cash', '580.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(9, 9, 'cash', '0.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(10, 10, 'cash', '375.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(11, 11, 'cash', '730.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(12, 12, 'cash', '1227.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(13, 13, 'cash', '200.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(14, 14, 'cash', '100.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(15, 15, 'cash', '50.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(16, 16, 'cash', '505.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(17, 17, 'cash', '0.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(18, 17, 'cash', '1205.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(19, 18, 'cash', '0.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(20, 18, 'cash', '400.00', NULL, NULL, NULL, NULL, '0000-00-00 00:00:00'),
(21, 19, 'cheque', '1740.00', '25364', 'HNB', 'Main Street', '2025-09-19', '2025-09-19 09:04:53'),
(22, 20, 'cash', '250.00', NULL, NULL, NULL, NULL, '2025-09-19 09:10:45'),
(23, 21, 'cash', '150.00', NULL, NULL, NULL, NULL, '2025-09-19 09:11:38'),
(24, 22, 'card', '550.00', NULL, NULL, NULL, NULL, '2025-09-19 09:16:39'),
(25, 23, 'cash', '1350.00', NULL, NULL, NULL, NULL, '2025-09-19 10:29:46'),
(26, 24, 'cash', '270.00', NULL, NULL, NULL, NULL, '2025-09-19 11:08:10'),
(27, 25, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-19 13:55:48'),
(28, 25, 'cash', '50.00', NULL, NULL, NULL, NULL, '2025-09-20 00:11:58'),
(29, 25, 'cheque', '20.00', '45657', 'MOM', 'JAFF', '2025-09-19', '2025-09-20 00:17:32'),
(30, 26, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-20 01:09:08'),
(31, 27, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-20 01:10:24'),
(32, 28, 'cash', '580.00', NULL, NULL, NULL, NULL, '2025-09-20 01:10:50'),
(33, 29, 'cash', '400.00', NULL, NULL, NULL, NULL, '2025-09-20 01:11:59'),
(34, 30, 'cash', '720.00', NULL, NULL, NULL, NULL, '2025-09-20 09:10:00'),
(35, 31, 'cash', '280.00', NULL, NULL, NULL, NULL, '2025-09-20 12:52:07'),
(36, 32, 'cash', '170.00', NULL, NULL, NULL, NULL, '2025-09-20 12:53:43'),
(37, 33, 'cash', '170.00', NULL, NULL, NULL, NULL, '2025-09-20 12:57:29'),
(38, 34, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-23 10:12:11'),
(39, 35, 'cash', '6315.00', NULL, NULL, NULL, NULL, '2025-09-23 13:25:17'),
(40, 25, 'cheque', '20.00', '256452', 'HNB', 'KKS', '2025-09-25', '2025-09-25 13:37:12'),
(41, 36, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-25 13:49:21'),
(42, 36, 'cheque', '400.00', '454656', 'fhfhj', 'fjdjjjj', '2025-09-25', '2025-09-25 13:49:53'),
(43, 37, 'cheque', '260.00', '35565', 'NDB', 'Colombo', '2025-09-25', '2025-09-25 13:57:54'),
(44, 38, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-25 14:11:53'),
(45, 38, 'cheque', '1000.00', '2566545', 'JHK', 'JAFF', '2025-09-25', '2025-09-25 14:12:33'),
(46, 39, 'card', '260.00', NULL, NULL, NULL, NULL, '2025-09-26 09:37:48'),
(47, 40, 'card', '200.00', NULL, NULL, NULL, NULL, '2025-09-26 09:40:38'),
(48, 41, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-09-26 11:34:35'),
(49, 42, 'card', '330.00', NULL, NULL, NULL, NULL, '2025-09-26 11:53:23'),
(50, 43, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-09-26 11:59:41'),
(51, 44, 'card', '100.00', NULL, NULL, NULL, NULL, '2025-09-26 12:06:21'),
(52, 45, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-09-26 13:18:20'),
(53, 46, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-09-26 23:33:27'),
(54, 47, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-09-26 23:49:58'),
(55, 36, 'cash', '200.00', NULL, NULL, NULL, NULL, '2025-09-28 02:23:22'),
(56, 48, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-09-28 08:13:39'),
(57, 49, 'cash', '1000.00', NULL, NULL, NULL, NULL, '2025-09-28 08:29:51'),
(58, 50, 'cash', '2250.00', NULL, NULL, NULL, NULL, '2025-09-28 08:31:51'),
(59, 51, 'cash', '205.00', NULL, NULL, NULL, NULL, '2025-09-30 09:00:37'),
(60, 52, 'cash', '250.00', NULL, NULL, NULL, NULL, '2025-09-30 19:02:13'),
(61, 53, 'cash', '1250.00', NULL, NULL, NULL, NULL, '2025-09-30 19:37:49'),
(62, 54, 'cash', '750.00', NULL, NULL, NULL, NULL, '2025-10-01 08:00:17'),
(63, 55, 'cash', '50.00', NULL, NULL, NULL, NULL, '2025-10-01 15:04:11'),
(64, 56, 'cash', '250.00', NULL, NULL, NULL, NULL, '2025-10-01 15:22:49'),
(65, 57, 'cash', '600.00', NULL, NULL, NULL, NULL, '2025-10-02 08:31:59'),
(66, 58, 'cash', '-300.00', NULL, NULL, NULL, NULL, '2025-10-02 08:34:14'),
(67, 59, 'cash', '600.00', NULL, NULL, NULL, NULL, '2025-10-02 09:22:47'),
(68, 60, 'cash', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 09:54:28'),
(69, 61, 'cash', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 09:59:00'),
(70, 62, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 10:08:09'),
(71, 63, 'cash', '120.00', NULL, NULL, NULL, NULL, '2025-10-02 10:23:15'),
(72, 64, 'cash', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 10:24:34'),
(73, 65, 'cash', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 10:58:27'),
(74, 66, 'cash', '600.00', NULL, NULL, NULL, NULL, '2025-10-02 10:59:57'),
(75, 70, 'cash', '-360.00', NULL, NULL, NULL, NULL, '2025-10-02 11:06:32'),
(76, 71, 'card', '300.00', NULL, NULL, NULL, NULL, '2025-10-02 18:33:57'),
(77, 72, 'bank_transfer', '100.00', NULL, NULL, NULL, NULL, '2025-10-02 18:36:24'),
(78, 73, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-10-02 18:36:58'),
(79, 74, 'cash', '50.00', NULL, NULL, NULL, NULL, '2025-10-02 18:37:48'),
(80, 74, 'credit', '50.00', NULL, NULL, NULL, NULL, '2025-10-02 18:37:48'),
(81, 75, 'credit', '0.00', NULL, NULL, NULL, NULL, '2025-10-02 18:39:45'),
(82, 76, 'cash', '50.00', NULL, NULL, NULL, NULL, '2025-10-02 19:01:29'),
(83, 77, 'cash', '400.00', NULL, NULL, NULL, NULL, '2025-10-03 08:35:45'),
(84, 78, 'cash', '400.00', NULL, NULL, NULL, NULL, '2025-10-03 08:36:41'),
(85, 79, 'cash', '250.00', NULL, NULL, NULL, NULL, '2025-10-03 22:11:21'),
(86, 80, 'cash', '500.00', NULL, NULL, NULL, NULL, '2025-10-03 22:13:51'),
(87, 81, 'cash', '160.00', NULL, NULL, NULL, NULL, '2025-10-03 22:29:53'),
(88, 82, 'cash', '330.00', NULL, NULL, NULL, NULL, '2025-10-03 22:30:29'),
(89, 83, 'cash', '2000.00', NULL, NULL, NULL, NULL, '2025-10-03 22:31:06'),
(90, 84, 'cash', '600.00', NULL, NULL, NULL, NULL, '2025-10-03 22:44:57'),
(91, 85, 'cash', '1500.00', NULL, NULL, NULL, NULL, '2025-10-03 22:45:45'),
(92, 86, 'cash', '1300.00', NULL, NULL, NULL, NULL, '2025-10-03 23:24:11'),
(93, 87, 'cash', '600.00', NULL, NULL, NULL, NULL, '2025-10-03 23:28:55'),
(94, 88, 'cash', '45.00', NULL, NULL, NULL, NULL, '2025-10-03 23:37:12'),
(95, 89, 'cash', '1800.00', NULL, NULL, NULL, NULL, '2025-10-03 23:38:55'),
(96, 90, 'cash', '-1055.00', NULL, NULL, NULL, NULL, '2025-10-03 23:42:30'),
(97, 91, 'cash', '850.00', NULL, NULL, NULL, NULL, '2025-10-04 07:13:50'),
(98, 92, 'cash', '2200.00', NULL, NULL, NULL, NULL, '2025-10-04 07:31:55'),
(99, 93, 'cash', '1200.00', NULL, NULL, NULL, NULL, '2025-10-04 07:39:39'),
(100, 94, 'cash', '440.00', NULL, NULL, NULL, NULL, '2025-10-04 07:41:48'),
(101, 95, 'cash', '270.00', NULL, NULL, NULL, NULL, '2025-10-04 07:44:12'),
(102, 96, 'cash', '500.00', NULL, NULL, NULL, NULL, '2025-10-04 07:55:51'),
(103, 97, 'cash', '876.00', NULL, NULL, NULL, NULL, '2025-10-04 08:34:09'),
(104, 98, 'cash', '1000.00', NULL, NULL, NULL, NULL, '2025-10-04 08:52:09'),
(105, 99, 'cash', '395.00', NULL, NULL, NULL, NULL, '2025-10-04 14:38:19'),
(106, 100, 'cash', '110.00', NULL, NULL, NULL, NULL, '2025-10-04 14:45:06'),
(107, 101, 'cash', '300.00', NULL, NULL, NULL, NULL, '2025-10-04 14:47:03'),
(108, 102, 'cheque', '240.00', '67567', 'yjhyjty', 'tyjytjty', '2025-10-05', '2025-10-05 08:57:00'),
(109, 103, 'cheque', '300.00', '456', 'bc', 'tgrtg', '2025-10-08', '2025-10-05 03:31:06'),
(110, 104, 'cheque', '300.00', '647575', 'rhrjhr', 'rhrej', '2025-10-30', '2025-10-05 04:01:50'),
(111, 105, 'cheque', '300.00', '34346', 'nnn', 'mmm', '2025-10-12', '2025-10-05 09:33:24'),
(112, 106, 'cheque', '300.00', '454577', 'fghfgh', 'tfgthfh', '2025-10-31', '2025-10-05 04:10:27'),
(113, 107, 'cheque', '300.00', '456456', 'fhf', 'hfh', NULL, '2025-10-05 09:42:03'),
(114, 108, 'cheque', '300.00', '6767', 'hhk', 'jkl', NULL, '2025-10-06 09:37:20'),
(115, 109, 'cheque', '600.00', '34646', 'gngfnfg', 'ngfnfg', '2025-10-06', '2025-10-06 04:09:41'),
(116, 110, 'cash', '250.00', NULL, NULL, NULL, NULL, '2025-10-06 12:38:35'),
(117, 113, 'cash', '720.00', NULL, NULL, NULL, NULL, '2025-10-06 18:39:29');

-- --------------------------------------------------------

--
-- Table structure for table `sale_returns`
--

CREATE TABLE `sale_returns` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `return_date` datetime NOT NULL,
  `processed_by` int(11) NOT NULL,
  `return_type` enum('cash','exchange') NOT NULL DEFAULT 'cash',
  `total_refund` decimal(12,2) NOT NULL DEFAULT '0.00',
  `amount_received` decimal(12,2) NOT NULL DEFAULT '0.00',
  `change_given` decimal(12,2) NOT NULL DEFAULT '0.00',
  `settled_with_sale_id` int(11) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_returns`
--

INSERT INTO `sale_returns` (`id`, `sale_id`, `branch_id`, `return_date`, `processed_by`, `return_type`, `total_refund`, `amount_received`, `change_given`, `settled_with_sale_id`, `remarks`) VALUES
(1, 1, 1, '2025-09-17 07:55:25', 2, 'exchange', '250.00', '0.00', '0.00', 2, ' Settled with sale ID: 2'),
(2, 5, 1, '2025-09-18 04:22:15', 2, 'exchange', '250.00', '50.00', '0.00', 6, NULL),
(3, 3, 1, '2025-09-18 04:38:59', 2, 'exchange', '330.00', '0.00', '285.00', 7, NULL),
(4, 4, 1, '2025-09-18 04:41:02', 2, 'exchange', '250.00', '50.00', '0.00', 55, NULL),
(5, 10, 1, '2025-09-18 06:37:10', 2, 'exchange', '375.00', '505.00', '0.00', 16, NULL),
(6, 13, 1, '2025-09-18 06:41:03', 2, 'exchange', '200.00', '50.00', '0.00', 15, NULL),
(7, 20, 1, '2025-09-19 03:41:16', 2, 'exchange', '250.00', '150.00', '0.00', 21, NULL),
(8, 30, 2, '2025-09-20 07:21:34', 3, 'exchange', '720.00', '280.00', '0.00', 31, NULL),
(9, 54, 8, '2025-10-01 09:32:20', 14, 'exchange', '250.00', '0.00', '0.00', NULL, NULL),
(10, 56, 8, '2025-10-01 09:53:41', 14, 'exchange', '250.00', '0.00', '0.00', NULL, NULL),
(11, 57, 5, '2025-10-02 03:03:37', 11, 'exchange', '600.00', '0.00', '300.00', 58, NULL),
(12, 59, 5, '2025-10-02 03:55:16', 11, 'exchange', '600.00', '0.00', '0.00', 63, NULL),
(13, 66, 5, '2025-10-02 05:30:21', 11, 'exchange', '600.00', '0.00', '0.00', 70, NULL),
(14, 89, 1, '2025-10-03 18:11:09', 2, 'exchange', '1800.00', '0.00', '0.00', 90, NULL),
(15, 94, 1, '2025-10-04 02:12:43', 2, 'exchange', '460.00', '0.00', '0.00', 95, NULL),
(16, 96, 1, '2025-10-04 02:26:59', 2, 'exchange', '164.00', '0.00', '0.00', 97, NULL),
(17, 99, 1, '2025-10-04 09:11:33', 2, 'exchange', '395.00', '0.00', '0.00', 100, NULL),
(18, 101, 1, '2025-10-04 09:17:39', 2, 'cash', '300.00', '0.00', '300.00', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `sale_return_items`
--

CREATE TABLE `sale_return_items` (
  `id` int(11) NOT NULL,
  `sale_return_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `unit_price` decimal(10,2) NOT NULL,
  `line_total` decimal(12,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `sale_return_items`
--

INSERT INTO `sale_return_items` (`id`, `sale_return_id`, `product_id`, `batch_id`, `quantity`, `unit_price`, `line_total`) VALUES
(1, 1, 7, 7, 1, '250.00', '250.00'),
(2, 2, 7, 7, 1, '250.00', '250.00'),
(3, 3, 3, 3, 1, '330.00', '330.00'),
(4, 4, 7, 7, 1, '250.00', '250.00'),
(5, 5, 3, 3, 1, '330.00', '330.00'),
(6, 5, 6, 6, 1, '45.00', '45.00'),
(7, 6, 7, 7, 1, '200.00', '200.00'),
(8, 7, 7, 7, 1, '250.00', '250.00'),
(9, 8, 15, 15, 1, '170.00', '170.00'),
(10, 8, 11, 11, 1, '550.00', '550.00'),
(11, 9, 7, 39, 1, '250.00', '250.00'),
(12, 10, 7, 39, 1, '250.00', '250.00'),
(13, 11, 16, 44, 2, '300.00', '600.00'),
(14, 12, 16, 44, 2, '300.00', '600.00'),
(15, 13, 16, 44, 2, '300.00', '600.00'),
(16, 14, 12, 22, 3, '300.00', '900.00'),
(17, 14, 2, 2, 3, '300.00', '900.00'),
(18, 15, 6, 6, 4, '40.00', '160.00'),
(19, 15, 2, 2, 1, '300.00', '300.00'),
(20, 16, 6, 6, 4, '41.00', '164.00'),
(21, 17, 6, 6, 1, '45.00', '45.00'),
(22, 17, 12, 22, 1, '350.00', '350.00'),
(23, 18, 2, 2, 1, '300.00', '300.00');

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustments`
--

CREATE TABLE `stock_adjustments` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `adjusted_by` int(11) NOT NULL,
  `adj_datetime` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `header_reason` varchar(64) DEFAULT NULL,
  `header_note` text,
  `total_lines` int(11) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stock_adjustments`
--

INSERT INTO `stock_adjustments` (`id`, `branch_id`, `adjusted_by`, `adj_datetime`, `header_reason`, `header_note`, `total_lines`, `created_at`) VALUES
(1, 5, 11, '2025-10-04 17:39:35', NULL, NULL, 1, '2025-10-04 17:39:35'),
(2, 5, 11, '2025-10-05 09:50:56', 'Split', NULL, 1, '2025-10-05 09:50:56'),
(3, 5, 11, '2025-10-05 09:51:33', 'Split', NULL, 1, '2025-10-05 09:51:33'),
(4, 2, 3, '2025-10-06 17:55:27', NULL, NULL, 1, '2025-10-06 17:55:27');

-- --------------------------------------------------------

--
-- Table structure for table `stock_adjustment_items`
--

CREATE TABLE `stock_adjustment_items` (
  `id` int(11) NOT NULL,
  `adjustment_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity_before` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantity_delta` decimal(12,2) NOT NULL DEFAULT '0.00',
  `quantity_after` decimal(12,2) NOT NULL DEFAULT '0.00',
  `cost_price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `value_impact` decimal(12,2) NOT NULL DEFAULT '0.00',
  `line_reason` varchar(64) DEFAULT NULL,
  `line_note` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stock_adjustment_items`
--

INSERT INTO `stock_adjustment_items` (`id`, `adjustment_id`, `product_id`, `batch_id`, `quantity_before`, `quantity_delta`, `quantity_after`, `cost_price`, `value_impact`, `line_reason`, `line_note`, `created_at`) VALUES
(1, 1, 7, 24, '87.00', '-1.00', '86.00', '150.00', '-150.00', 'Split', 'Test By Ruban', '2025-10-04 17:39:35'),
(2, 2, 3, 26, '5.00', '1.00', '6.00', '120.00', '120.00', 'Split', NULL, '2025-10-05 09:50:56'),
(3, 3, 3, 26, '6.00', '1.00', '7.00', '120.00', '120.00', 'Split', NULL, '2025-10-05 09:51:33'),
(4, 4, 18, 47, '10.00', '-1.00', '9.00', '1200.00', '-1200.00', 'Damage', NULL, '2025-10-06 17:55:27');

-- --------------------------------------------------------

--
-- Table structure for table `stock_batches`
--

CREATE TABLE `stock_batches` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `batch_no` varchar(50) DEFAULT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(10,2) DEFAULT NULL,
  `cost_pin` varchar(100) DEFAULT NULL,
  `selling_price` decimal(10,2) DEFAULT NULL,
  `expiry_date` date DEFAULT NULL,
  `stock_place` varchar(100) DEFAULT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stock_batches`
--

INSERT INTO `stock_batches` (`id`, `product_id`, `branch_id`, `batch_no`, `quantity`, `cost_price`, `cost_pin`, `selling_price`, `expiry_date`, `stock_place`, `created_at`) VALUES
(1, 1, 1, '01', 97, '50.00', 'BATH100', '90.00', '2027-12-31', 'lower Cabert', '2025-09-17 13:15:04'),
(2, 2, 1, '01', 48, '180.00', 'SMP250', '300.00', '2028-01-15', NULL, '2025-09-17 13:15:04'),
(3, 3, 1, '01', 47, '200.00', 'COND250', '330.00', '2028-01-15', NULL, '2025-09-17 13:15:04'),
(4, 4, 1, '01', 117, '60.00', 'FT100', '100.00', '2027-09-30', NULL, '2025-09-17 13:15:04'),
(5, 5, 1, '01', 48, '250.00', 'BL200', '400.00', '2028-02-20', NULL, '2025-09-17 13:15:04'),
(6, 6, 1, '02', 145, '20.00', 'DS2', '45.00', '2029-06-10', NULL, '2025-09-17 13:15:04'),
(7, 7, 1, '02', 87, '150.00', 'AF300', '250.00', '2028-11-05', NULL, '2025-09-17 13:15:04'),
(8, 8, 2, '03', 60, '1000.00', 'R10', '1600.00', '2026-06-01', NULL, '2025-09-17 13:15:04'),
(9, 9, 2, '03', 70, '300.00', 'S2', '480.00', '2026-07-15', NULL, '2025-09-17 13:15:04'),
(10, 10, 2, '03', 90, '150.00', 'F1', '260.00', '2026-05-20', NULL, '2025-09-17 13:15:04'),
(11, 11, 2, '03', 54, '350.00', 'CO750', '550.00', '2026-08-10', NULL, '2025-09-17 13:15:04'),
(12, 12, 2, '03', 56, '200.00', 'TEA500', '350.00', '2027-01-30', NULL, '2025-09-17 13:15:04'),
(13, 13, 2, '03', 44, '450.00', 'COF500', '720.00', '2027-01-30', NULL, '2025-09-17 13:15:04'),
(14, 14, 2, '04', 111, '120.00', 'TUNA185', '200.00', '2027-03-15', NULL, '2025-09-17 13:15:04'),
(15, 15, 2, '04', 94, '100.00', 'CORN300', '170.00', '2027-03-15', NULL, '2025-09-17 13:15:04'),
(16, 7, 1, '3', 129, '160.00', 'KJHK', '260.00', NULL, NULL, '2025-09-19 10:05:55'),
(17, 7, 7, '1', 4, '120.00', 'KJHK', '200.00', '2025-09-30', NULL, '2025-09-20 01:06:39'),
(18, 4, 7, '1', 22, '150.00', 'FGH', '180.00', '2025-10-08', NULL, '2025-09-20 01:06:39'),
(19, 15, 1, '04', 0, '100.00', NULL, '170.00', '2027-03-15', NULL, '2025-09-20 11:32:46'),
(20, 9, 1, '03', 0, '300.00', NULL, '480.00', '2026-07-15', NULL, '2025-09-20 12:07:49'),
(21, 7, 6, '1', 10, '120.00', NULL, '200.00', '2025-09-30', NULL, '2025-09-23 10:19:02'),
(22, 12, 1, '03', 11, '200.00', '', '350.00', '2027-01-30', NULL, '2025-09-23 11:41:13'),
(23, 14, 1, '04', 0, '120.00', NULL, '200.00', '2027-03-15', NULL, '2025-09-23 11:42:36'),
(24, 7, 5, '1', 85, '150.00', 'DFG', '300.00', '2025-09-30', NULL, '2025-09-26 13:06:12'),
(25, 15, 5, '1', 0, '140.00', 'GHJ', '160.00', '2025-10-09', NULL, '2025-09-27 00:16:24'),
(26, 3, 5, '1', 7, '120.00', 'dgjj', '150.00', '2025-10-07', NULL, '2025-09-27 00:38:09'),
(27, 3, 7, '1', 100, '100.00', 'rrtyhr', '150.00', '2025-10-07', NULL, '2025-09-27 00:49:06'),
(28, 1, 5, '01', 0, '50.00', NULL, '90.00', '2027-12-31', NULL, '2025-09-27 08:38:33'),
(29, 15, 2, '1', 5, '140.00', NULL, '160.00', '2025-10-09', NULL, '2025-09-27 08:44:03'),
(30, 7, 1, '1', 6, '150.00', NULL, '300.00', '2025-09-30', NULL, '2025-09-27 08:45:02'),
(31, 4, 2, '01', 20, '60.00', NULL, '100.00', '2027-09-30', NULL, '2025-09-27 08:47:07'),
(32, 7, 2, '1', 10, '150.00', 'JHG', '300.00', '2025-10-08', NULL, '2025-09-29 11:37:18'),
(33, 15, 1, '1', 2, '140.00', NULL, '160.00', '2025-10-09', NULL, '2025-09-29 11:59:38'),
(34, 1, 2, '01', 10, '50.00', NULL, '90.00', '2027-12-31', NULL, '2025-09-29 12:00:13'),
(35, 7, 1, '4', 60, '190.00', 'KLM', '300.00', '2025-10-11', 'Top BarSheet', '2025-09-30 08:48:27'),
(36, 13, 1, '1', 15, '120.00', 'GHK', '250.00', '2025-10-06', NULL, '2025-09-30 08:48:27'),
(37, 12, 8, '1', 8, '100.00', 'DFG', '150.00', '2025-10-09', NULL, '2025-09-30 18:44:49'),
(38, 13, 8, '1', 9, '150.00', 'DTF', '200.00', '2025-10-05', NULL, '2025-09-30 18:44:49'),
(39, 7, 8, '1', 1, '150.00', 'FGH', '250.00', '2025-10-06', NULL, '2025-09-30 19:01:52'),
(40, 13, 7, '1', 1, '150.00', NULL, '200.00', '2025-10-05', NULL, '2025-10-01 08:45:30'),
(41, 12, 7, '1', 2, '100.00', NULL, '150.00', '2025-10-09', NULL, '2025-10-01 08:47:48'),
(42, 3, 8, '1', 5, '120.00', NULL, '150.00', '2025-10-07', NULL, '2025-10-01 09:19:47'),
(43, 4, 8, '1', 10, '150.00', 'ugyf', '400.00', '2025-11-07', NULL, '2025-10-01 15:25:40'),
(44, 16, 5, '1', 8, '200.00', 'FGH', '300.00', '2025-11-05', NULL, '2025-10-02 08:30:12'),
(45, 17, 5, '1', 0, '100.00', 'fj', '120.00', '2025-11-05', NULL, '2025-10-02 09:26:33'),
(46, 4, 9, '1', 8, '150.00', 'ugyf', '400.00', '2025-11-07', 'top track', '2025-10-03 08:34:08'),
(47, 18, 2, '1', 9, '1200.00', 'fgf', '2000.00', '2025-10-13', 'top', '2025-10-06 16:46:10');

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfers`
--

CREATE TABLE `stock_transfers` (
  `id` int(11) NOT NULL,
  `from_branch_id` int(11) NOT NULL,
  `to_branch_id` int(11) NOT NULL,
  `transferred_by` int(11) NOT NULL,
  `transferred_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stock_transfers`
--

INSERT INTO `stock_transfers` (`id`, `from_branch_id`, `to_branch_id`, `transferred_by`, `transferred_at`) VALUES
(1, 2, 1, 3, '2025-09-20 11:32:46'),
(2, 2, 1, 3, '2025-09-20 12:07:49'),
(3, 1, 2, 2, '2025-09-20 20:06:15'),
(4, 7, 6, 13, '2025-09-23 10:19:02'),
(5, 2, 1, 3, '2025-09-23 11:41:13'),
(6, 2, 1, 2, '2025-09-23 11:42:36'),
(7, 1, 2, 2, '2025-09-25 17:51:06'),
(8, 1, 5, 2, '2025-09-27 08:38:32'),
(9, 5, 2, 11, '2025-09-27 08:44:03'),
(10, 5, 1, 11, '2025-09-27 08:45:02'),
(11, 1, 2, 2, '2025-09-27 08:47:07'),
(12, 1, 2, 2, '2025-09-29 11:57:52'),
(13, 5, 1, 11, '2025-09-29 11:59:38'),
(14, 5, 2, 11, '2025-09-29 12:00:13'),
(15, 8, 7, 14, '2025-10-01 08:18:29'),
(16, 8, 7, 14, '2025-10-01 08:45:30'),
(17, 8, 7, 14, '2025-10-01 08:47:48'),
(18, 5, 8, 11, '2025-10-01 09:19:47'),
(19, 7, 8, 13, '2025-10-01 09:36:05');

-- --------------------------------------------------------

--
-- Table structure for table `stock_transfer_items`
--

CREATE TABLE `stock_transfer_items` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `stock_transfer_items`
--

INSERT INTO `stock_transfer_items` (`id`, `transfer_id`, `batch_id`, `quantity`) VALUES
(1, 1, 15, 10),
(2, 2, 9, 5),
(3, 3, 19, 2),
(4, 4, 17, 10),
(5, 5, 12, 10),
(6, 6, 14, 10),
(7, 7, 23, 2),
(8, 8, 1, 10),
(9, 9, 25, 5),
(10, 10, 24, 10),
(11, 11, 4, 10),
(12, 12, 4, 10),
(13, 13, 25, 5),
(14, 14, 28, 10),
(15, 15, 39, 1),
(16, 16, 38, 2),
(17, 17, 37, 2),
(18, 18, 26, 5),
(19, 19, 40, 1);

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `contact` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `contact`, `phone`, `address`) VALUES
(1, 'APPICO', 'Noshan', '774526345', 'Colo');

-- --------------------------------------------------------

--
-- Table structure for table `transfer_payments`
--

CREATE TABLE `transfer_payments` (
  `id` int(11) NOT NULL,
  `transfer_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `paid_by` int(11) NOT NULL,
  `paid_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `transfer_payments`
--

INSERT INTO `transfer_payments` (`id`, `transfer_id`, `amount`, `paid_by`, `paid_at`) VALUES
(1, 1, '500.00', 2, '2025-09-20 11:39:28'),
(2, 1, '500.00', 2, '2025-09-20 11:44:20'),
(3, 2, '500.00', 2, '2025-09-20 12:08:34'),
(4, 2, '500.00', 2, '2025-09-20 12:10:14'),
(5, 2, '200.00', 2, '2025-09-20 12:15:31'),
(6, 2, '100.00', 2, '2025-09-20 12:15:57'),
(10, 2, '100.00', 2, '2025-09-20 12:24:14'),
(11, 2, '50.00', 2, '2025-09-20 12:32:52'),
(12, 2, '50.00', 2, '2025-09-20 12:44:57'),
(13, 3, '100.00', 3, '2025-09-20 20:06:47'),
(14, 3, '100.00', 3, '2025-09-22 16:31:24'),
(15, 5, '500.00', 2, '2025-09-23 11:56:48'),
(16, 5, '500.00', 2, '2025-09-25 14:26:41'),
(17, 5, '200.00', 2, '2025-09-25 17:49:47'),
(18, 8, '500.00', 11, '2025-09-27 08:41:27'),
(19, 5, '100.00', 2, '2025-10-02 18:09:19'),
(20, 5, '100.00', 2, '2025-10-02 18:13:48'),
(21, 5, '100.00', 2, '2025-10-02 18:18:18');

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `name`) VALUES
(1, 'pcs'),
(2, 'bottle'),
(3, 'bottle'),
(4, 'box'),
(5, 'bottle'),
(6, 'pack'),
(7, 'bottle'),
(8, 'bag'),
(9, 'bag'),
(10, 'bag'),
(11, 'bottle'),
(12, 'packet'),
(13, 'packet'),
(14, 'can'),
(15, 'can'),
(16, 'pcs'),
(17, 'bottle'),
(18, 'bottle'),
(19, 'box'),
(20, 'bottle'),
(21, 'pack'),
(22, 'bottle'),
(23, 'bag'),
(24, 'bag'),
(25, 'bag'),
(26, 'bottle'),
(27, 'packet'),
(28, 'packet'),
(29, 'can'),
(30, 'can'),
(31, 'pcs'),
(32, 'bottle'),
(33, 'bottle'),
(34, 'box'),
(35, 'bottle'),
(36, 'pack'),
(37, 'bottle'),
(38, 'bag'),
(39, 'bag'),
(40, 'bag'),
(41, 'bottle'),
(42, 'packet'),
(43, 'packet'),
(44, 'can'),
(45, 'can');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role_id` int(11) NOT NULL,
  `branch_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `full_name`, `password`, `role_id`, `branch_id`) VALUES
(1, 'admin', 'Administrator', '$2b$12$3X7LZh.6LErOUjRwoIjSzOG2I.MFfAqGQatULbHYiZuMbDFlrypmO', 1, NULL),
(2, 'mari1', 'Marianayagam Mariaruban', '$2y$10$8rZQS46/vujaWVMpglhkle6mmXT6wZjkAVsv1LOPWsfPaUQfbYQFm', 2, 1),
(3, 'mari2', 'Marianayagam Mariaruban', '$2y$10$ZzBv/ksmRVTpjSO0QOgzDe6yTPlvMR2hhGLohVXv//rBk7H8QsdWe', 2, 2),
(5, 'keerthi2', 'keerthi kethiswaran', '$2y$10$yowEb7YMTIbj4a3Mrc61WOSlJDlWilH91epUjXL6LUkFG0viwte.2', 4, 2),
(6, 'arthi1', 'arthi kethiswaran', '$2y$10$UBheIeD6bXYDa4M3WUSmnuQ5Vqy7xgH4OfZUhF3JBoCTYDBhY8bIi', 3, 1),
(7, 'arthi2', 'arthi kethiswaran', '$2y$10$EF..797SvB2QfkHlyJukh.wh5uGD1dV65ujbAInmxGLvL26N4CO5i', 3, 2),
(9, 'dinu', 'Dinushan', '$2y$10$RPcdvgmhlpTDk6NqiIWXlOGTCQb6XIGYp4rELvdfNk0CFSWigE.mC', 2, 3),
(10, 'thanu', 'Thanushan Marianayagam', '$2y$10$lpmceoOaoMITnFMeII0mTuiNFSk/q8IqJK/dlmgq//FJYM9YLz.n6', 2, 4),
(11, 'leony', 'leony', '$2y$10$BlowczipXb34oTmjtu5EPujP48Ris2PdKEzN4Q6MGZ.6XifI6w7ky', 2, 5),
(12, 'reny', 'sachu', '$2y$10$vzRN4Ef1DopVG9CirMz9/Oe2uMKhgY0RGfUj.Q3zOUCvsPf7qM6fW', 2, 6),
(13, 'Ineya', 'Sachu Ineya', '$2y$10$eECj8FyNSzFdB7S7hjth9eudbu8fItzMsg4KMNFbCCSw6X1CXBbv.', 2, 7),
(14, 'calis', 'calistor', '$2y$10$LxcsSwePx7oG3GnG4YYMAOjalPA/faAaTGWvS9luwznmIUUjKaMWi', 2, 8),
(15, 'abc', 'abcd', '$2y$10$bq0r8L4Z7G9Onr1uJLMVCuTtjH1mV90i.xvOBeu..tv6JdVOQRaWS', 2, 9);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_branch_bank_balance_net`
-- (See below for the actual view)
--
CREATE TABLE `v_branch_bank_balance_net` (
`bank_balance_net` decimal(36,2)
,`branch_id` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_branch_cargo_payable`
-- (See below for the actual view)
--
CREATE TABLE `v_branch_cargo_payable` (
`branch_id` int(11)
,`cargo_payable` decimal(57,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_branch_cash_balance`
-- (See below for the actual view)
--
CREATE TABLE `v_branch_cash_balance` (
`branch_id` int(11)
,`cash_in_hand` decimal(40,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_branch_cheques_in_hand`
-- (See below for the actual view)
--
CREATE TABLE `v_branch_cheques_in_hand` (
`branch_id` int(11)
,`cheques_in_hand` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_cargo_provider_balance`
-- (See below for the actual view)
--
CREATE TABLE `v_cargo_provider_balance` (
`balance` decimal(35,2)
,`branch_id` int(11)
,`cargo_provider_id` int(11)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `v_transfer_values`
-- (See below for the actual view)
--
CREATE TABLE `v_transfer_values` (
`from_branch_id` int(11)
,`to_branch_id` int(11)
,`transfer_id` int(11)
,`transfer_value` decimal(42,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `wallets`
--

CREATE TABLE `wallets` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `opening_balance` decimal(12,2) NOT NULL DEFAULT '0.00',
  `bank_tariff` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `wallets`
--

INSERT INTO `wallets` (`id`, `name`, `branch_id`, `opening_balance`, `bank_tariff`, `created_by`, `created_at`) VALUES
(1, 'Wall_abc', 9, '500.00', '0.00', 1, '2025-10-03 08:27:33');

-- --------------------------------------------------------

--
-- Table structure for table `workers`
--

CREATE TABLE `workers` (
  `id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `workers`
--

INSERT INTO `workers` (`id`, `branch_id`, `name`, `phone`) VALUES
(1, 1, 'Thusi', '77251456'),
(2, 8, 'Romy', '0333333332'),
(3, 5, 'thanu', '43534545345'),
(4, 9, 'Dani', '777777725');

-- --------------------------------------------------------

--
-- Table structure for table `worker_payments`
--

CREATE TABLE `worker_payments` (
  `id` int(11) NOT NULL,
  `worker_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `payment_date` date NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `type` enum('salary','advance','special') NOT NULL,
  `method` enum('cash','bank') NOT NULL DEFAULT 'cash',
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `write_offs`
--

CREATE TABLE `write_offs` (
  `id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `branch_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(12,2) NOT NULL,
  `write_off_date` datetime NOT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `write_offs`
--

INSERT INTO `write_offs` (`id`, `batch_id`, `product_id`, `branch_id`, `quantity`, `cost_price`, `write_off_date`, `remarks`, `created_by`, `created_at`) VALUES
(1, 7, 7, 1, 2, '150.00', '2025-09-24 18:03:06', 'Take by Thusah', 2, '2025-09-24 23:33:06'),
(2, 1, 1, 1, 10, '50.00', '2025-09-24 18:15:48', '', 2, '2025-09-24 23:45:48'),
(3, 2, 2, 1, 5, '180.00', '2025-09-24 18:21:36', '', 2, '2025-09-24 23:51:36');

-- --------------------------------------------------------

--
-- Structure for view `v_branch_bank_balance_net`
--
DROP TABLE IF EXISTS `v_branch_bank_balance_net`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_branch_bank_balance_net`  AS  select `b`.`id` AS `branch_id`,((coalesce((select sum(`sp`.`amount`) from (`sales` `s` join `sale_payments` `sp` on((`sp`.`sale_id` = `s`.`id`))) where ((`s`.`branch_id` = `b`.`id`) and (`sp`.`method` in ('card','upi','bank_transfer')))),0) - coalesce((select sum(`cc`.`commission_amount`) from `card_commissions` `cc` where (`cc`.`branch_id` = `b`.`id`)),0)) + coalesce((select sum(`je`.`amount`) from `journal_entries` `je` where ((`je`.`branch_id` = `b`.`id`) and (`je`.`account_type` = 'bank'))),0)) AS `bank_balance_net` from `branches` `b` ;

-- --------------------------------------------------------

--
-- Structure for view `v_branch_cargo_payable`
--
DROP TABLE IF EXISTS `v_branch_cargo_payable`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_branch_cargo_payable`  AS  select `v_cargo_provider_balance`.`branch_id` AS `branch_id`,coalesce(sum(`v_cargo_provider_balance`.`balance`),0) AS `cargo_payable` from `v_cargo_provider_balance` group by `v_cargo_provider_balance`.`branch_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_branch_cash_balance`
--
DROP TABLE IF EXISTS `v_branch_cash_balance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_branch_cash_balance`  AS  select `b`.`id` AS `branch_id`,((((((coalesce((select coalesce(sum(`co`.`opening_amount`),0) from `cash_openings` `co` where (`co`.`branch_id` = `b`.`id`)),0) + coalesce((select sum(`sp`.`amount`) from (`sale_payments` `sp` join `sales` `s` on((`s`.`id` = `sp`.`sale_id`))) where ((`s`.`branch_id` = `b`.`id`) and (`sp`.`method` = 'cash'))),0)) - coalesce((select sum(`pp`.`amount`) from (`purchase_payments` `pp` join `purchases` `p` on((`p`.`id` = `pp`.`purchase_id`))) where (`p`.`branch_id` = `b`.`id`)),0)) - coalesce((select sum(`e`.`amount`) from `expenses` `e` where ((`e`.`branch_id` = `b`.`id`) and (`e`.`payment_method` = 'cash'))),0)) - coalesce((select sum(`wp`.`amount`) from `worker_payments` `wp` where ((`wp`.`branch_id` = `b`.`id`) and (`wp`.`method` = 'cash'))),0)) - coalesce((select sum(`tp`.`amount`) from (`transfer_payments` `tp` join `stock_transfers` `t` on((`t`.`id` = `tp`.`transfer_id`))) where (`t`.`from_branch_id` = `b`.`id`)),0)) + coalesce((select sum(`tp`.`amount`) from (`transfer_payments` `tp` join `stock_transfers` `t` on((`t`.`id` = `tp`.`transfer_id`))) where (`t`.`to_branch_id` = `b`.`id`)),0)) AS `cash_in_hand` from `branches` `b` ;

-- --------------------------------------------------------

--
-- Structure for view `v_branch_cheques_in_hand`
--
DROP TABLE IF EXISTS `v_branch_cheques_in_hand`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_branch_cheques_in_hand`  AS  select `b`.`id` AS `branch_id`,coalesce(sum(`sp`.`amount`),0) AS `cheques_in_hand` from ((`branches` `b` left join `sales` `s` on((`s`.`branch_id` = `b`.`id`))) left join `sale_payments` `sp` on(((`sp`.`sale_id` = `s`.`id`) and (`sp`.`method` = 'cheque') and (`sp`.`deposit_date` is null)))) group by `b`.`id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_cargo_provider_balance`
--
DROP TABLE IF EXISTS `v_cargo_provider_balance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_cargo_provider_balance`  AS  select `cs`.`branch_id` AS `branch_id`,`cs`.`cargo_provider_id` AS `cargo_provider_id`,(coalesce(sum(`cs`.`amount`),0) - coalesce((select sum(`cp`.`amount`) from `cargo_payments` `cp` where ((`cp`.`branch_id` = `cs`.`branch_id`) and (`cp`.`cargo_provider_id` = `cs`.`cargo_provider_id`))),0)) AS `balance` from `cargo_services` `cs` group by `cs`.`branch_id`,`cs`.`cargo_provider_id` ;

-- --------------------------------------------------------

--
-- Structure for view `v_transfer_values`
--
DROP TABLE IF EXISTS `v_transfer_values`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `v_transfer_values`  AS  select `t`.`id` AS `transfer_id`,`t`.`from_branch_id` AS `from_branch_id`,`t`.`to_branch_id` AS `to_branch_id`,sum((`sti`.`quantity` * `sb`.`cost_price`)) AS `transfer_value` from ((`stock_transfers` `t` join `stock_transfer_items` `sti` on((`sti`.`transfer_id` = `t`.`id`))) join `stock_batches` `sb` on((`sb`.`id` = `sti`.`batch_id`))) where (`sb`.`branch_id` = `t`.`from_branch_id`) group by `t`.`id`,`t`.`from_branch_id`,`t`.`to_branch_id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `bank_accounts_branch_id_idx` (`branch_id`);

--
-- Indexes for table `branches`
--
ALTER TABLE `branches`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `branch_counters`
--
ALTER TABLE `branch_counters`
  ADD PRIMARY KEY (`branch_id`),
  ADD KEY `branch_counters_branch_id_idx` (`branch_id`);

--
-- Indexes for table `brands`
--
ALTER TABLE `brands`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `card_commissions`
--
ALTER TABLE `card_commissions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `cargo_payments`
--
ALTER TABLE `cargo_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_provider` (`cargo_provider_id`),
  ADD KEY `idx_branch` (`branch_id`);

--
-- Indexes for table `cargo_providers`
--
ALTER TABLE `cargo_providers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `cargo_services`
--
ALTER TABLE `cargo_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_supplier` (`supplier_id`),
  ADD KEY `idx_provider` (`cargo_provider_id`),
  ADD KEY `idx_cargo_services_purchase` (`purchase_id`);

--
-- Indexes for table `cash_drawer_daily`
--
ALTER TABLE `cash_drawer_daily`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_cash_drawer_daily` (`branch_id`,`business_date`),
  ADD KEY `idx_cdd_date` (`business_date`),
  ADD KEY `fk_cdd_user` (`created_by`);

--
-- Indexes for table `cash_draws`
--
ALTER TABLE `cash_draws`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_branch` (`branch_id`),
  ADD KEY `idx_bank` (`bank_id`),
  ADD KEY `idx_date` (`draw_date`);

--
-- Indexes for table `cash_openings`
--
ALTER TABLE `cash_openings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_opening` (`branch_id`,`opening_date`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `expenses`
--
ALTER TABLE `expenses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_products_category` (`category_id`),
  ADD KEY `fk_products_brand` (`brand_id`),
  ADD KEY `fk_products_unit` (`unit_id`);

--
-- Indexes for table `purchases`
--
ALTER TABLE `purchases`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `supplier_id` (`supplier_id`),
  ADD KEY `purchased_by` (`purchased_by`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_id` (`purchase_id`),
  ADD KEY `paid_by` (`paid_by`),
  ADD KEY `idx_pp_method` (`method`),
  ADD KEY `idx_pp_deposit_date` (`deposit_date`),
  ADD KEY `idx_pp_deposit_bank` (`deposit_bank_id`);

--
-- Indexes for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_returns_purchase_id_idx` (`purchase_id`);

--
-- Indexes for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `purchase_return_items_purchase_return_id_idx` (`purchase_return_id`);

--
-- Indexes for table `reversal_audit`
--
ALTER TABLE `reversal_audit`
  ADD PRIMARY KEY (`id`),
  ADD KEY `log_id` (`log_id`),
  ADD KEY `reversed_by` (`reversed_by`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_branch_invoice` (`branch_id`,`invoice_no`),
  ADD KEY `branch_id` (`branch_id`),
  ADD KEY `sold_by` (`sold_by`),
  ADD KEY `fk_sales_customer` (`customer_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `sale_payments`
--
ALTER TABLE `sale_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`);

--
-- Indexes for table `sale_returns`
--
ALTER TABLE `sale_returns`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_returns_sale_id_idx` (`sale_id`);

--
-- Indexes for table `sale_return_items`
--
ALTER TABLE `sale_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_return_items_sale_return_id_idx` (`sale_return_id`);

--
-- Indexes for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `stock_adjustment_items`
--
ALTER TABLE `stock_adjustment_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `adjustment_id` (`adjustment_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `stock_batches`
--
ALTER TABLE `stock_batches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `from_branch_id` (`from_branch_id`),
  ADD KEY `to_branch_id` (`to_branch_id`),
  ADD KEY `transferred_by` (`transferred_by`);

--
-- Indexes for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `transfer_payments`
--
ALTER TABLE `transfer_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `transfer_id` (`transfer_id`),
  ADD KEY `paid_by` (`paid_by`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD KEY `role_id` (`role_id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `wallets`
--
ALTER TABLE `wallets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `wallets_branch_id_idx` (`branch_id`);

--
-- Indexes for table `workers`
--
ALTER TABLE `workers`
  ADD PRIMARY KEY (`id`),
  ADD KEY `branch_id` (`branch_id`);

--
-- Indexes for table `worker_payments`
--
ALTER TABLE `worker_payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `worker_payments_worker_id_idx` (`worker_id`);

--
-- Indexes for table `write_offs`
--
ALTER TABLE `write_offs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `write_offs_batch_id_idx` (`batch_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activity_logs`
--
ALTER TABLE `activity_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=168;

--
-- AUTO_INCREMENT for table `bank_accounts`
--
ALTER TABLE `bank_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `branches`
--
ALTER TABLE `branches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `brands`
--
ALTER TABLE `brands`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `card_commissions`
--
ALTER TABLE `card_commissions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `cargo_payments`
--
ALTER TABLE `cargo_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `cargo_providers`
--
ALTER TABLE `cargo_providers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `cargo_services`
--
ALTER TABLE `cargo_services`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `cash_drawer_daily`
--
ALTER TABLE `cash_drawer_daily`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `cash_draws`
--
ALTER TABLE `cash_draws`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `cash_openings`
--
ALTER TABLE `cash_openings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `expenses`
--
ALTER TABLE `expenses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `purchases`
--
ALTER TABLE `purchases`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- AUTO_INCREMENT for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reversal_audit`
--
ALTER TABLE `reversal_audit`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=114;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=152;

--
-- AUTO_INCREMENT for table `sale_payments`
--
ALTER TABLE `sale_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=118;

--
-- AUTO_INCREMENT for table `sale_returns`
--
ALTER TABLE `sale_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `sale_return_items`
--
ALTER TABLE `sale_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `stock_adjustments`
--
ALTER TABLE `stock_adjustments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `stock_adjustment_items`
--
ALTER TABLE `stock_adjustment_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `stock_batches`
--
ALTER TABLE `stock_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `transfer_payments`
--
ALTER TABLE `transfer_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=22;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=46;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `wallets`
--
ALTER TABLE `wallets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `workers`
--
ALTER TABLE `workers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `worker_payments`
--
ALTER TABLE `worker_payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `write_offs`
--
ALTER TABLE `write_offs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `activity_logs`
--
ALTER TABLE `activity_logs`
  ADD CONSTRAINT `activity_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `activity_logs_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `branch_counters`
--
ALTER TABLE `branch_counters`
  ADD CONSTRAINT `branch_counters_branch_fk` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `card_commissions`
--
ALTER TABLE `card_commissions`
  ADD CONSTRAINT `fk_cc_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`);

--
-- Constraints for table `cash_drawer_daily`
--
ALTER TABLE `cash_drawer_daily`
  ADD CONSTRAINT `fk_cdd_branch` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_cdd_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `cash_openings`
--
ALTER TABLE `cash_openings`
  ADD CONSTRAINT `cash_openings_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `cash_openings_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `expenses`
--
ALTER TABLE `expenses`
  ADD CONSTRAINT `expenses_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `expenses_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_brand` FOREIGN KEY (`brand_id`) REFERENCES `brands` (`id`),
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`),
  ADD CONSTRAINT `fk_products_unit` FOREIGN KEY (`unit_id`) REFERENCES `units` (`id`);

--
-- Constraints for table `purchases`
--
ALTER TABLE `purchases`
  ADD CONSTRAINT `purchases_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchases_ibfk_2` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `purchases_ibfk_3` FOREIGN KEY (`purchased_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_payments`
--
ALTER TABLE `purchase_payments`
  ADD CONSTRAINT `fk_pp_deposit_bank` FOREIGN KEY (`deposit_bank_id`) REFERENCES `bank_accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_payments_ibfk_1` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_payments_ibfk_2` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `purchase_returns`
--
ALTER TABLE `purchase_returns`
  ADD CONSTRAINT `purchase_returns_purchase_fk` FOREIGN KEY (`purchase_id`) REFERENCES `purchases` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `purchase_return_items_fk` FOREIGN KEY (`purchase_return_id`) REFERENCES `purchase_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_ibfk_2` FOREIGN KEY (`sold_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sale_items_ibfk_3` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sale_payments`
--
ALTER TABLE `sale_payments`
  ADD CONSTRAINT `sale_payments_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `sale_returns`
--
ALTER TABLE `sale_returns`
  ADD CONSTRAINT `sale_returns_sale_fk` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `sale_return_items`
--
ALTER TABLE `sale_return_items`
  ADD CONSTRAINT `sale_return_items_fk` FOREIGN KEY (`sale_return_id`) REFERENCES `sale_returns` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `stock_batches`
--
ALTER TABLE `stock_batches`
  ADD CONSTRAINT `stock_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_batches_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `stock_transfers`
--
ALTER TABLE `stock_transfers`
  ADD CONSTRAINT `stock_transfers_ibfk_1` FOREIGN KEY (`from_branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_transfers_ibfk_2` FOREIGN KEY (`to_branch_id`) REFERENCES `branches` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_transfers_ibfk_3` FOREIGN KEY (`transferred_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `stock_transfer_items`
--
ALTER TABLE `stock_transfer_items`
  ADD CONSTRAINT `stock_transfer_items_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `stock_transfer_items_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `transfer_payments`
--
ALTER TABLE `transfer_payments`
  ADD CONSTRAINT `transfer_payments_ibfk_1` FOREIGN KEY (`transfer_id`) REFERENCES `stock_transfers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `transfer_payments_ibfk_2` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  ADD CONSTRAINT `users_ibfk_2` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `workers`
--
ALTER TABLE `workers`
  ADD CONSTRAINT `workers_ibfk_1` FOREIGN KEY (`branch_id`) REFERENCES `branches` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `worker_payments`
--
ALTER TABLE `worker_payments`
  ADD CONSTRAINT `worker_payments_worker_fk` FOREIGN KEY (`worker_id`) REFERENCES `workers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `write_offs`
--
ALTER TABLE `write_offs`
  ADD CONSTRAINT `write_offs_batch_fk` FOREIGN KEY (`batch_id`) REFERENCES `stock_batches` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
