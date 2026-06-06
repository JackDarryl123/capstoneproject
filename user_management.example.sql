-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 17, 2026 at 04:49 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `user_management`
--

-- --------------------------------------------------------

--
-- Table structure for table `activities`
--

CREATE TABLE `activities` (
  `id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `photo` varchar(255) DEFAULT 'default.jpg',
  `activity_type` enum('Inspection','Maintenance/Repair','Appointment') NOT NULL,
  `property_no` varchar(100) DEFAULT NULL,
  `location` varchar(100) NOT NULL,
  `activity_date` date NOT NULL,
  `activity_time` time NOT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activities`
--


-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `activity_type` varchar(100) NOT NULL,
  `property_no` varchar(100) NOT NULL,
  `location` varchar(50) NOT NULL DEFAULT 'mamburao',
  `date_time` datetime NOT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'Pending',
  `performed_by` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--


-- --------------------------------------------------------

--
-- Table structure for table `appointment_requests`
--

CREATE TABLE `appointment_requests` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `pre_repair_no` varchar(50) NOT NULL,
  `property_no` varchar(50) NOT NULL,
  `location` varchar(50) NOT NULL,
  `appointment_date` date NOT NULL,
  `appointment_time` time NOT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','cancelled') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `appointment_requests`
--


-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL DEFAULT 0,
  `category_id` int(11) DEFAULT NULL,
  `equipment` varchar(255) DEFAULT NULL,
  `description_of_property` varchar(255) DEFAULT NULL,
  `designation_of_property` varchar(255) DEFAULT NULL,
  `acquisition_date` date DEFAULT NULL,
  `acquisition_cost` decimal(15,2) DEFAULT NULL,
  `last_repair_date` date DEFAULT NULL,
  `carrying_amount` decimal(15,2) DEFAULT NULL,
  `officer_name` varchar(100) DEFAULT NULL,
  `complaint` text DEFAULT NULL,
  `property_no` varchar(100) DEFAULT NULL,
  `pre_repair_no` varchar(100) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `date_requested` date NOT NULL,
  `inspector_name` varchar(100) DEFAULT NULL,
  `inspector_position` varchar(100) DEFAULT NULL,
  `defect` text DEFAULT NULL,
  `findings` text DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `recommendation` varchar(100) DEFAULT NULL,
  `inspected_by` varchar(100) DEFAULT NULL,
  `inspected_by_sig` varchar(255) DEFAULT NULL,
  `approved_by_pepo` varchar(100) DEFAULT NULL,
  `approved_by_pepo_sig` text DEFAULT NULL,
  `witnessed_by` varchar(100) DEFAULT NULL,
  `witnessed_by_sig` text DEFAULT NULL,
  `approved_by_gso` varchar(100) DEFAULT NULL,
  `approved_by_gso_sig` text DEFAULT NULL,
  `material_1` varchar(255) DEFAULT NULL,
  `quantity_1` varchar(50) DEFAULT NULL,
  `material_2` varchar(255) DEFAULT NULL,
  `quantity_2` varchar(50) DEFAULT NULL,
  `material_3` varchar(255) DEFAULT NULL,
  `quantity_3` varchar(50) DEFAULT NULL,
  `material_4` varchar(255) DEFAULT NULL,
  `quantity_4` varchar(50) DEFAULT NULL,
  `material_5` varchar(255) DEFAULT NULL,
  `quantity_5` varchar(50) DEFAULT NULL,
  `material_6` varchar(255) DEFAULT NULL,
  `quantity_6` varchar(50) DEFAULT NULL,
  `material_7` varchar(255) DEFAULT NULL,
  `quantity_7` varchar(50) DEFAULT NULL,
  `material_8` varchar(255) DEFAULT NULL,
  `quantity_8` varchar(50) DEFAULT NULL,
  `material_9` varchar(255) DEFAULT NULL,
  `quantity_9` varchar(50) DEFAULT NULL,
  `material_10` varchar(255) DEFAULT NULL,
  `quantity_10` varchar(50) DEFAULT NULL,
  `signature` varchar(255) DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `attached_file_path` varchar(255) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `notification_seen` tinyint(1) DEFAULT 0,
  `is_read` tinyint(1) DEFAULT 0,
  `date_completed` date DEFAULT NULL,
  `date_approved` date DEFAULT NULL,
  `date_done` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `documents`
--


-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

CREATE TABLE `equipment` (
  `id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `property_no` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `designation` varchar(255) DEFAULT NULL,
  `acquisition_date` date DEFAULT NULL,
  `acquisition_cost` decimal(10,2) DEFAULT NULL,
  `last_repair_date` date DEFAULT NULL,
  `location` varchar(100) NOT NULL,
  `type` varchar(100) NOT NULL,
  `status` enum('Operational','Under repair','Unserviceable') NOT NULL DEFAULT 'Operational',
  `date_added` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment`
--


-- --------------------------------------------------------

--
-- Table structure for table `equipment_category`
--

CREATE TABLE `equipment_category` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `equipment_category`
--


-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

CREATE TABLE `inventory` (
  `id` int(11) UNSIGNED NOT NULL,
  `category` varchar(100) NOT NULL,
  `item` varchar(150) NOT NULL,
  `model_no` varchar(100) NOT NULL,
  `allocation` varchar(100) NOT NULL,
  `status` enum('GOOD','LOW','WORN_OUT','OUT_OF_STOCK') NOT NULL DEFAULT 'GOOD',
  `date_added` timestamp NOT NULL DEFAULT current_timestamp(),
  `log_stats` varchar(50) DEFAULT 'AVAILABLE',
  `borrowed_date` datetime DEFAULT NULL,
  `returned_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory`
--


-- --------------------------------------------------------

--
-- Table structure for table `inventory_activity_log`
--

CREATE TABLE `inventory_activity_log` (
  `id` int(11) NOT NULL,
  `inventory_id` int(11) NOT NULL,
  `action_type` enum('ADDED','UPDATED','ISSUED','RETURNED') NOT NULL,
  `item_name` varchar(150) DEFAULT NULL,
  `quantity_changed` int(11) DEFAULT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `date_time` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `inventory_activity_log`
--


-- --------------------------------------------------------

--
-- Table structure for table `inventory_category`
--

CREATE TABLE `inventory_category` (
  `id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `logs`
--

CREATE TABLE `logs` (
  `id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `action_type` varchar(50) NOT NULL,
  `result` varchar(50) NOT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `logs`
--


-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT 'supply_request',
  `link` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--


-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `signup_requests`
--

CREATE TABLE `signup_requests` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `designation` varchar(100) DEFAULT NULL,
  `role` varchar(50) DEFAULT NULL,
  `requested_role` enum('user','staff','supply','admin','pgdh_gso') NOT NULL,
  `status` enum('pending','approved','declined') NOT NULL DEFAULT 'pending',
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL,
  `processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `signup_requests`
--


-- --------------------------------------------------------

--
-- Table structure for table `supply_requests`
--

CREATE TABLE `supply_requests` (
  `id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `pre_repair_no` varchar(50) DEFAULT NULL,
  `property_no` varchar(100) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `supply_location` varchar(50) NOT NULL,
  `requested_by` varchar(100) NOT NULL,
  `admin_location` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `status` enum('pending','approved','complied','received','archived') DEFAULT 'pending',
  `complied_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `approved_by` varchar(100) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `supply_requests`
--


-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_admin` tinyint(1) NOT NULL DEFAULT 0,
  `failed_attempts` int(11) DEFAULT 0,
  `lockout_time` datetime DEFAULT NULL,
  `role` enum('admin','user','staff','supply','pgdh_gso','pgdh_pepo','pgdh_pacco') NOT NULL DEFAULT 'user',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `signature` varchar(255) DEFAULT NULL,
  `location` varchar(50) DEFAULT 'mamburao'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--


-- --------------------------------------------------------

--
-- Table structure for table `user_notifications_read`
--

CREATE TABLE `user_notifications_read` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  `read_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_notifications_read`
--


--
-- Indexes for dumped tables
--

--
-- Indexes for table `activities`
--
ALTER TABLE `activities`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_location` (`location`);

--
-- Indexes for table `appointment_requests`
--
ALTER TABLE `appointment_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `equipment`
--
ALTER TABLE `equipment`
  ADD PRIMARY KEY (`id`),
  ADD KEY `category_id` (`category_id`);

--
-- Indexes for table `equipment_category`
--
ALTER TABLE `equipment_category`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `inventory`
--
ALTER TABLE `inventory`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_activity_log`
--
ALTER TABLE `inventory_activity_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_category`
--
ALTER TABLE `inventory_category`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_category` (`category_name`);

--
-- Indexes for table `logs`
--
ALTER TABLE `logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `signup_requests`
--
ALTER TABLE `signup_requests`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `supply_requests`
--
ALTER TABLE `supply_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `document_id` (`document_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `user_notifications_read`
--
ALTER TABLE `user_notifications_read`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_read` (`user_id`,`document_id`),
  ADD KEY `idx_user_id` (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `activities`
--
ALTER TABLE `activities`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=104;

--
-- AUTO_INCREMENT for table `activity_log`
--
ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=170;

--
-- AUTO_INCREMENT for table `appointment_requests`
--
ALTER TABLE `appointment_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=48;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=153;

--
-- AUTO_INCREMENT for table `equipment`
--
ALTER TABLE `equipment`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=134;

--
-- AUTO_INCREMENT for table `equipment_category`
--
ALTER TABLE `equipment_category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=35;

--
-- AUTO_INCREMENT for table `inventory`
--
ALTER TABLE `inventory`
  MODIFY `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `inventory_activity_log`
--
ALTER TABLE `inventory_activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=163;

--
-- AUTO_INCREMENT for table `inventory_category`
--
ALTER TABLE `inventory_category`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `logs`
--
ALTER TABLE `logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2166;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=132;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `signup_requests`
--
ALTER TABLE `signup_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `supply_requests`
--
ALTER TABLE `supply_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=73;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=65;

--
-- AUTO_INCREMENT for table `user_notifications_read`
--
ALTER TABLE `user_notifications_read`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointment_requests`
--
ALTER TABLE `appointment_requests`
  ADD CONSTRAINT `appointment_requests_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `equipment`
--
ALTER TABLE `equipment`
  ADD CONSTRAINT `equipment_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `equipment_category` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `password_reset_tokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `supply_requests`
--
ALTER TABLE `supply_requests`
  ADD CONSTRAINT `supply_requests_ibfk_1` FOREIGN KEY (`document_id`) REFERENCES `documents` (`id`) ON DELETE CASCADE;
--
-- Demo users for local development
-- Password for all demo accounts: password
--
INSERT INTO `users` (`id`, `username`, `email`, `password`, `is_admin`, `failed_attempts`, `lockout_time`, `role`, `status`, `signature`, `location`) VALUES
(1, 'Demo Admin', 'admin@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 1, 0, NULL, 'admin', 'active', NULL, 'Mamburao'),
(2, 'Demo Staff', 'staff@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 0, 0, NULL, 'staff', 'active', NULL, 'Mamburao'),
(3, 'Demo Supply', 'supply@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 0, 0, NULL, 'supply', 'active', NULL, 'Mamburao'),
(4, 'Demo User', 'user@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 0, 0, NULL, 'user', 'active', NULL, 'Mamburao'),
(5, 'Demo PACCO', 'pacco@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 0, 0, NULL, 'pgdh_pacco', 'active', NULL, 'Mamburao'),
(6, 'Demo GSO', 'gso@example.test', '$2y$10$NJZCG7PNg3wpC/pXjYWD6uxe2Jc9fv6cZ1LloHmDN7OnZ7iNQZUty', 0, 0, NULL, 'pgdh_gso', 'active', NULL, 'Mamburao');
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
