-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 10, 2025 at 03:14 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `ref`
--

-- --------------------------------------------------------

--
-- Table structure for table `agencies`
--

CREATE TABLE `agencies` (
  `id` int(11) NOT NULL,
  `representative_id` int(11) NOT NULL,
  `agency_name` enum('agency 1','agency 2') NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agencies`
--

INSERT INTO `agencies` (`id`, `representative_id`, `agency_name`, `created_at`) VALUES
(1, 1001, 'agency 1', '2025-11-09 09:21:59'),
(2, 1001, 'agency 2', '2025-11-09 09:21:59'),
(3, 34, 'agency 1', '2025-11-09 10:44:59'),
(4, 34, 'agency 2', '2025-11-09 10:44:59');

-- --------------------------------------------------------

--
-- Table structure for table `agency_invites`
--

CREATE TABLE `agency_invites` (
  `id` int(11) NOT NULL,
  `representative_id` int(11) NOT NULL,
  `agency_id` int(11) NOT NULL,
  `rep_user_id` int(11) DEFAULT NULL,
  `token` varchar(255) NOT NULL,
  `status` enum('pending','accepted','rejected') DEFAULT 'pending',
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agency_invites`
--

INSERT INTO `agency_invites` (`id`, `representative_id`, `agency_id`, `rep_user_id`, `token`, `status`, `created_at`) VALUES
(1, 1001, 1, NULL, 'b554735ecdb3bf159447abc91f997e3d', 'pending', '2025-11-09 09:44:44'),
(2, 1001, 1, NULL, '522b920e5dc02d33afeea1c08e5e91ad', 'pending', '2025-11-09 09:45:07'),
(3, 1001, 1, 1001, '7ff8ec36da3ca9d55335d1f28f7a054a', 'rejected', '2025-11-09 09:48:23'),
(4, 1001, 1, 32, '7724b2056b8f3016af37f0f1f20109cc', 'accepted', '2025-11-09 09:51:49'),
(5, 1001, 2, 32, '1cb22b7a92fa5026409a499000dc350e', 'rejected', '2025-11-09 09:53:22'),
(6, 1001, 1, NULL, 'b53d005a12188f527905c594962091ff', 'pending', '2025-11-09 09:55:01'),
(7, 1001, 1, NULL, 'ad6d4a95215a9e6793f70d29e0160f40', 'pending', '2025-11-09 09:56:19'),
(8, 1001, 2, 32, 'e3e7d5f7531a87a5e102e1f99c771eda', 'rejected', '2025-11-09 09:58:11'),
(9, 1001, 1, 32, '98615d36d5419a3575e67eb6113968ba', 'accepted', '2025-11-09 09:58:34'),
(10, 1001, 1, 25, '5feb590a037f3eb9f41f374c7baf27ea', 'accepted', '2025-11-09 10:03:40'),
(11, 1001, 2, 34, '3c8673859e1ec390acf725538b2559b5', 'rejected', '2025-11-09 10:06:55'),
(12, 1001, 1, NULL, '47047e1c105b8571f0b1ea11504e702e', 'pending', '2025-11-09 10:26:52'),
(13, 1001, 1, 32, 'c990f8346859104e535d5370fd856ba7', 'accepted', '2025-11-09 10:29:33'),
(14, 34, 4, 34, 'c1a72b47bf4a96bd27c3eba7ac83aabb', 'pending', '2025-11-09 10:45:09'),
(15, 1001, 2, 21, 'ac7b196b8267491721b6a1131dbb8a05', 'accepted', '2025-11-10 16:38:43');

-- --------------------------------------------------------

--
-- Table structure for table `agency_points`
--

CREATE TABLE `agency_points` (
  `id` int(11) NOT NULL,
  `agency_id` int(11) NOT NULL,
  `total_rep_points` int(11) NOT NULL DEFAULT 0,
  `total_representative_points` int(11) NOT NULL DEFAULT 0,
  `last_updated` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agency_points`
--

INSERT INTO `agency_points` (`id`, `agency_id`, `total_rep_points`, `total_representative_points`, `last_updated`) VALUES
(1, 1, -20000, -120000, '2025-11-10 18:44:23'),
(2, 2, 160000, 0, '2025-11-10 16:59:36'),
(3, 1, 180000, 0, '2025-11-10 18:44:23'),
(4, 2, 0, 0, '2025-11-10 19:20:03'),
(5, 1, 40000, 20000, '2025-11-10 19:23:10');

-- --------------------------------------------------------

--
-- Table structure for table `agency_reps`
--

CREATE TABLE `agency_reps` (
  `id` int(11) NOT NULL,
  `rep_user_id` int(11) NOT NULL,
  `representative_id` int(11) NOT NULL,
  `agency_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `agency_reps`
--

INSERT INTO `agency_reps` (`id`, `rep_user_id`, `representative_id`, `agency_id`) VALUES
(3, 25, 1001, 1),
(4, 32, 1001, 1),
(5, 21, 1001, 2);

-- --------------------------------------------------------

--
-- Table structure for table `items`
--

CREATE TABLE `items` (
  `id` int(11) NOT NULL,
  `item_code` varchar(50) NOT NULL,
  `item_name` varchar(100) DEFAULT NULL,
  `rep_points` int(11) NOT NULL,
  `representative_points` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `items`
--

INSERT INTO `items` (`id`, `item_code`, `item_name`, `rep_points`, `representative_points`, `price`) VALUES
(1, 'ITM001', 'Notebook A4', 10, 5, 100.00),
(2, 'ITM002', 'Ballpoint Pen Blue', 5, 2, 100.00),
(3, 'ITM003', 'Pencil HB', 3, 1, 100.00),
(4, 'ITM004', 'Eraser White', 2, 1, 100.00),
(5, 'ITM005', 'Permanent Marker Black', 8, 4, 100.00),
(6, 'ITM006', 'Stapler Small', 15, 7, 100.00),
(7, 'ITM007', 'Notebook B5', 12, 6, 100.00),
(8, 'ITM008', 'Highlighter Pink', 7, 3, 100.00),
(9, 'ITM009', 'Glue Stick', 6, 3, 10050.00);

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `payment_type` enum('weekly','monthly','agency') NOT NULL,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `paid_date` datetime DEFAULT current_timestamp(),
  `remarks` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payments`
--

INSERT INTO `payments` (`id`, `user_id`, `payment_type`, `points_redeemed`, `amount`, `paid_date`, `remarks`) VALUES
(1, 1001, 'agency', 100000, 10000.00, '2025-11-10 16:59:30', 'Agency bonus payout for 10 batch(es).'),
(2, 1001, 'agency', 320000, 32000.00, '2025-11-10 16:59:36', 'Agency bonus payout for 32 batch(es).'),
(3, 21, 'weekly', 320000, 3200.00, '2025-11-10 17:13:45', 'Weekly payment for 320000 points (Period: 2025-11-08 to 2025-11-15).'),
(4, 1001, 'weekly', 488500, 4885.00, '2025-11-10 18:43:49', 'Weekly payment for 488500 points (Period: 2025-11-08 to 2025-11-15).'),
(5, 1001, 'agency', 240000, 24000.00, '2025-11-10 18:44:23', 'Agency bonus payout for 24 batch(es).');

-- --------------------------------------------------------

--
-- Table structure for table `points_ledger`
--

CREATE TABLE `points_ledger` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `rep_user_id` int(11) NOT NULL,
  `representative_id` int(11) DEFAULT NULL,
  `agency_id` int(11) DEFAULT NULL,
  `sale_date` date NOT NULL,
  `points_rep` int(11) NOT NULL DEFAULT 0,
  `points_representative` int(11) NOT NULL DEFAULT 0,
  `redeemed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `points_ledger`
--

INSERT INTO `points_ledger` (`id`, `sale_id`, `rep_user_id`, `representative_id`, `agency_id`, `sale_date`, `points_rep`, `points_representative`, `redeemed`, `created_at`) VALUES
(1, 11, 32, 1001, 1, '2025-11-10', 150000, 50000, 0, '2025-11-10 16:35:07'),
(2, 12, 21, 1001, 2, '2025-11-10', 320000, 160000, 1, '2025-11-10 16:40:04'),
(3, 13, 32, 1001, 1, '2025-11-10', 300000, 120000, 0, '2025-11-10 18:00:41'),
(4, 19, 1001, NULL, NULL, '2025-11-10', 22500, 9000, 1, '2025-11-10 18:25:52'),
(5, 20, 1001, NULL, NULL, '2025-11-10', 1000, 500, 1, '2025-11-10 18:35:19'),
(6, 21, 1001, NULL, NULL, '2025-11-10', 465000, 155000, 1, '2025-11-10 18:41:57'),
(7, 22, 1001, NULL, NULL, '2025-11-10', 1500000, 700000, 0, '2025-11-10 18:58:41'),
(8, 23, 1001, NULL, NULL, '2025-11-10', 75000, 30000, 0, '2025-11-10 19:17:37'),
(9, 24, 21, 1001, 2, '2025-11-11', 0, 0, 0, '2025-11-10 19:20:03'),
(10, 25, 32, 1001, 1, '2025-11-10', 40000, 20000, 0, '2025-11-10 19:23:10');

-- --------------------------------------------------------

--
-- Table structure for table `sales`
--

CREATE TABLE `sales` (
  `id` int(11) NOT NULL,
  `rep_user_id` int(11) NOT NULL,
  `sale_date` date NOT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `sale_type` enum('full','half') NOT NULL DEFAULT 'full',
  `admin_approved` tinyint(1) NOT NULL DEFAULT 1,
  `admin_request` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sales`
--

INSERT INTO `sales` (`id`, `rep_user_id`, `sale_date`, `total_amount`, `created_at`, `sale_type`, `admin_approved`, `admin_request`) VALUES
(1, 32, '2025-11-09', NULL, '2025-11-09 07:05:00', 'full', 1, 0),
(2, 32, '2025-11-09', NULL, '2025-11-09 07:05:00', 'full', 1, 0),
(3, 32, '2025-10-21', NULL, '2025-10-21 07:22:00', 'full', 1, 0),
(4, 32, '2025-11-10', NULL, '2025-11-10 04:20:00', 'full', 1, 0),
(5, 32, '2025-11-10', NULL, '2025-11-10 04:43:00', 'full', 1, 0),
(6, 32, '2025-11-10', NULL, '2025-11-10 04:46:00', 'full', 1, 0),
(7, 32, '2025-11-10', NULL, '2025-11-10 05:09:00', 'full', 1, 0),
(8, 32, '2025-11-10', NULL, '2025-11-10 05:10:00', 'full', 1, 0),
(9, 32, '2025-11-10', NULL, '2025-11-10 05:27:00', 'full', 1, 0),
(10, 32, '2025-11-18', NULL, '2025-11-18 06:30:00', 'full', 1, 0),
(11, 32, '2025-11-10', NULL, '2025-11-10 12:04:00', 'full', 0, 0),
(12, 21, '2025-11-10', NULL, '2025-11-10 12:09:00', 'full', 0, 0),
(13, 32, '2025-11-10', NULL, '2025-11-10 13:30:00', 'full', 0, 0),
(14, 1001, '2025-11-10', NULL, '2025-11-10 13:32:00', 'full', 0, 0),
(15, 1001, '2025-11-10', NULL, '2025-11-10 13:32:00', 'full', 0, 0),
(16, 1001, '2025-11-10', NULL, '2025-11-10 13:44:00', 'full', 0, 0),
(17, 1001, '2025-11-10', NULL, '2025-11-10 13:46:00', 'full', 0, 0),
(18, 1001, '2025-11-10', NULL, '2025-11-10 13:46:00', 'full', 0, 0),
(19, 1001, '2025-11-10', NULL, '2025-11-10 13:55:00', 'full', 0, 0),
(20, 1001, '2025-11-10', NULL, '2025-11-10 14:05:00', 'full', 0, 0),
(21, 1001, '2025-11-10', NULL, '2025-11-10 14:11:00', 'full', 0, 0),
(22, 1001, '2025-11-10', NULL, '2025-11-10 14:28:00', 'full', 0, 0),
(23, 1001, '2025-11-10', NULL, '2025-11-10 14:47:00', 'full', 0, 0),
(24, 21, '2025-11-11', NULL, '2025-11-11 14:49:00', 'full', 0, 0),
(25, 32, '2025-11-10', NULL, '2025-11-10 14:52:00', 'full', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `sale_items`
--

CREATE TABLE `sale_items` (
  `id` int(11) NOT NULL,
  `sale_id` int(11) NOT NULL,
  `item_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sale_items`
--

INSERT INTO `sale_items` (`id`, `sale_id`, `item_id`, `quantity`) VALUES
(1, 2, 4, 1),
(2, 2, 5, 1),
(3, 3, 9, 2),
(4, 3, 1, 4),
(5, 6, 1, 2),
(6, 6, 6, 1),
(7, 7, 4, 1),
(8, 8, 6, 5),
(9, 9, 6, 3),
(10, 9, 9, 2),
(11, 10, 3, 1),
(12, 11, 3, 50000),
(13, 12, 4, 160000),
(14, 13, 2, 60000),
(15, 19, 2, 4500),
(16, 20, 1, 100),
(17, 21, 3, 155000),
(18, 22, 6, 100000),
(19, 23, 2, 15000),
(20, 25, 5, 5000);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `username` varchar(20) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','rep','representative') NOT NULL,
  `contact_number` varchar(20) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `nic_number` varchar(20) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `join_date` date DEFAULT NULL,
  `age` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `bank_account` varchar(50) DEFAULT NULL,
  `bank_name` varchar(100) DEFAULT NULL,
  `branch` varchar(100) DEFAULT NULL,
  `account_holder` varchar(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `first_name`, `last_name`, `username`, `password`, `role`, `contact_number`, `email`, `nic_number`, `address`, `city`, `join_date`, `age`, `created_at`, `bank_account`, `bank_name`, `branch`, `account_holder`) VALUES
(20, 'admin', '123', 'admin', '$2y$10$qyNQNedV4qzMNq3MEK2NiuMpWFhm83fPMgtSkU/qfo/yhNSBQlfnW', 'admin', '0768368202', 'admin@gmail.com', '122345678', '225/2 Henpitagedara Marandagahamaula', 'Marandagahmaula', '0000-00-00', 0, '2025-11-07 04:42:23', '', '', '', ''),
(21, 'member2', 'mem', 'member2', '$2y$10$Od1JeklRpfAUTWNSee6JT.6wWjnm9nKSy5hmbPgEG7DaaTsFNvOTG', 'rep', '', 'asdasds@gmail.com', '12254443', '', '', '0000-00-00', 0, '2025-11-07 05:22:30', '', '', '', ''),
(22, 'John', 'Perera', 'johnp', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0771234567', 'johnp@example.com', '951234567V', '12 Galle Road', 'Colombo', '2024-03-12', 29, '2025-11-07 05:22:59', '1002456789', 'Bank of Ceylon', 'Colombo Main', 'John Perera'),
(23, 'Samantha', 'Fernando', 'samf', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0712345678', 'samf@example.com', '962345678V', '45 Kandy Street', 'Kandy', '2024-05-10', 28, '2025-11-07 05:22:59', '2003456789', 'People\'s Bank', 'Kandy City', 'Samantha Fernando'),
(24, 'Ruwan', 'Silva', 'ruwans', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0753456789', 'ruwans@example.com', '973456789V', '78 Temple Road', 'Galle', '2024-07-22', 31, '2025-11-07 05:22:59', '3004567890', 'Commercial Bank', 'Galle Branch', 'Ruwan Silva'),
(25, 'Anjali', 'Kumari', 'anjalik', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0704567890', 'anjalik@example.com', '984567890V', '56 Hill View', 'Matara', '2024-08-15', 26, '2025-11-07 05:22:59', '4005678901', 'Sampath Bank', 'Matara', 'Anjali Kumari'),
(26, 'Dinesh', 'Wijesinghe', 'dineshw', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0725678901', 'dineshw@example.com', '953456781V', '34 Lake Road', 'Negombo', '2024-04-05', 33, '2025-11-07 05:22:59', '5006789012', 'NDB Bank', 'Negombo', 'Dinesh Wijesinghe'),
(27, 'Nimali', 'De Silva', 'nimalids', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0766789012', 'nimalids@example.com', '967890123V', '23 Palm Grove', 'Kurunegala', '2024-09-01', 27, '2025-11-07 05:22:59', '6007890123', 'Hatton National Bank', 'Kurunegala', 'Nimali De Silva'),
(28, 'Suresh', 'Jayawardena', 'sureshj', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0747890123', 'sureshj@example.com', '975678901V', '89 Garden Street', 'Rathnapura', '2024-02-14', 35, '2025-11-07 05:22:59', '7008901234', 'DFCC Bank', 'Rathnapura', 'Suresh Jayawardena'),
(29, 'Tharindu', 'Peris', 'tharindup', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0788901234', 'tharindup@example.com', '985678902V', '11 Park Avenue', 'Anuradhapura', '2024-01-20', 30, '2025-11-07 05:22:59', '8009012345', 'Seylan Bank', 'Anuradhapura', 'Tharindu Peris'),
(30, 'Isuri', 'Gunasekara', 'isurig', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0799012345', 'isurig@example.com', '995678903V', '90 New Road', 'Jaffna', '2024-06-12', 24, '2025-11-07 05:22:59', '9000123456', 'Union Bank', 'Jaffna', 'Isuri Gunasekara'),
(31, 'Ravindu', 'Senanayake', 'ravindus', 'ef92b778bafe771e89245b89ecbc08a44a4e166c06659911881f383d4473e94f', 'rep', '0730123456', 'ravindus@example.com', '993456784V', '67 Station Road', 'Batticaloa', '2024-10-25', 32, '2025-11-07 05:22:59', '9101234567', 'Pan Asia Bank', 'Batticaloa', 'Ravindu Senanayake'),
(32, 'mem', '1', 'mem1', '$2y$10$.DZNhZuHSGoYVI5TPSz30OmEzOoLI07Gb5hcAELzXEElMFBkBYnsq', 'rep', '1111111', 'sadasd@gmi.com', '55555', '', '', '0000-00-00', 0, '2025-11-07 06:58:08', '', '', '', ''),
(34, 'lead1', 'asads', 'lead1', '$2y$10$LKAKaFAxOwj9dWmvBfNEtObo5lb280uDeDGamhzH3uckTp.cPF8fK', 'representative', '', 'lead1@gmail.com', '11111111111111111', '', '', '0000-00-00', 0, '2025-11-07 07:00:58', '', '', '', ''),
(1001, 'leader1', 'Bashitha', 'leader1', '$2y$10$iKwlkZZdvbaqLHi4Z98Eo.FnkjdJNXI2JvBI6c4H/W9X/d2pNVD5u', 'representative', '0768368202', 'leader@gmail.com', '57963625544', '225/2 Henpitagedara Marandagahamaula', 'Marandagahmaula', '0000-00-00', 0, '2025-11-09 03:51:59', '', '', '', '');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `agencies`
--
ALTER TABLE `agencies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `representative_id` (`representative_id`);

--
-- Indexes for table `agency_invites`
--
ALTER TABLE `agency_invites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`),
  ADD KEY `representative_id` (`representative_id`),
  ADD KEY `agency_id` (`agency_id`),
  ADD KEY `rep_user_id` (`rep_user_id`);

--
-- Indexes for table `agency_points`
--
ALTER TABLE `agency_points`
  ADD PRIMARY KEY (`id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indexes for table `agency_reps`
--
ALTER TABLE `agency_reps`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `rep_user_id` (`rep_user_id`),
  ADD KEY `representative_id` (`representative_id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indexes for table `items`
--
ALTER TABLE `items`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `item_code` (`item_code`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `points_ledger`
--
ALTER TABLE `points_ledger`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `rep_user_id` (`rep_user_id`),
  ADD KEY `representative_id` (`representative_id`),
  ADD KEY `agency_id` (`agency_id`);

--
-- Indexes for table `sales`
--
ALTER TABLE `sales`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rep_user_id` (`rep_user_id`);

--
-- Indexes for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `sale_id` (`sale_id`),
  ADD KEY `item_id` (`item_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `nic_number` (`nic_number`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `agencies`
--
ALTER TABLE `agencies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `agency_invites`
--
ALTER TABLE `agency_invites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `agency_points`
--
ALTER TABLE `agency_points`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `agency_reps`
--
ALTER TABLE `agency_reps`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `items`
--
ALTER TABLE `items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `points_ledger`
--
ALTER TABLE `points_ledger`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `sales`
--
ALTER TABLE `sales`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `sale_items`
--
ALTER TABLE `sale_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1002;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `agencies`
--
ALTER TABLE `agencies`
  ADD CONSTRAINT `agencies_ibfk_1` FOREIGN KEY (`representative_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `agency_invites`
--
ALTER TABLE `agency_invites`
  ADD CONSTRAINT `agency_invites_ibfk_1` FOREIGN KEY (`representative_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `agency_invites_ibfk_2` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`),
  ADD CONSTRAINT `agency_invites_ibfk_3` FOREIGN KEY (`rep_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `agency_points`
--
ALTER TABLE `agency_points`
  ADD CONSTRAINT `agency_points_ibfk_1` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `agency_reps`
--
ALTER TABLE `agency_reps`
  ADD CONSTRAINT `agency_reps_ibfk_1` FOREIGN KEY (`rep_user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `agency_reps_ibfk_2` FOREIGN KEY (`representative_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `agency_reps_ibfk_3` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`);

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `payments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `points_ledger`
--
ALTER TABLE `points_ledger`
  ADD CONSTRAINT `points_ledger_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `points_ledger_ibfk_2` FOREIGN KEY (`rep_user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `points_ledger_ibfk_3` FOREIGN KEY (`representative_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `points_ledger_ibfk_4` FOREIGN KEY (`agency_id`) REFERENCES `agencies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `sales`
--
ALTER TABLE `sales`
  ADD CONSTRAINT `sales_ibfk_1` FOREIGN KEY (`rep_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `sale_items`
--
ALTER TABLE `sale_items`
  ADD CONSTRAINT `sale_items_ibfk_1` FOREIGN KEY (`sale_id`) REFERENCES `sales` (`id`),
  ADD CONSTRAINT `sale_items_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `items` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
