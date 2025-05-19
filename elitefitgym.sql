-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3306
-- Generation Time: May 07, 2025 at 08:22 AM
-- Server version: 9.1.0
-- PHP Version: 8.3.14

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `elitefitgym`
--

DELIMITER $$
--
-- Functions
--
DROP FUNCTION IF EXISTS `log_activity`$$
CREATE DEFINER=`root`@`localhost` FUNCTION `log_activity` (`p_module` VARCHAR(50), `p_action` TEXT, `p_user_id` INT, `p_ip_address` VARCHAR(45)) RETURNS INT  BEGIN
    INSERT INTO activity_log (module, action, user_id, ip_address)
    VALUES (p_module, p_action, p_user_id, p_ip_address);
    RETURN LAST_INSERT_ID();
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

DROP TABLE IF EXISTS `activity_log`;
CREATE TABLE IF NOT EXISTS `activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `equipment_id` int DEFAULT NULL,
  `action` text NOT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `equipment_id` (`equipment_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_logs`
--

DROP TABLE IF EXISTS `admin_logs`;
CREATE TABLE IF NOT EXISTS `admin_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `admin_id` int NOT NULL,
  `action` text NOT NULL,
  `log_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `admin_id` (`admin_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_settings`
--

DROP TABLE IF EXISTS `admin_settings`;
CREATE TABLE IF NOT EXISTS `admin_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `email_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `login_alerts` tinyint(1) NOT NULL DEFAULT '1',
  `registration_alerts` tinyint(1) NOT NULL DEFAULT '1',
  `dashboard_layout` varchar(50) DEFAULT 'default',
  `items_per_page` int DEFAULT '10',
  `default_report_period` varchar(20) DEFAULT 'monthly',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `fk_admin_settings_user` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dashboard_settings`
--

DROP TABLE IF EXISTS `dashboard_settings`;
CREATE TABLE IF NOT EXISTS `dashboard_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `theme_preference` varchar(20) DEFAULT 'dark',
  `theme` varchar(20) NOT NULL DEFAULT 'dark',
  `layout` varchar(20) NOT NULL DEFAULT 'default',
  `widgets` json DEFAULT NULL COMMENT 'Stores user widget preferences',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `dashboard_settings`
--

INSERT INTO `dashboard_settings` (`id`, `user_id`, `theme_preference`, `theme`, `layout`, `widgets`, `created_at`, `updated_at`) VALUES
(1, 8, 'dark', 'dark', 'default', NULL, '2025-05-05 14:24:22', '2025-05-05 14:50:57');

-- --------------------------------------------------------

--
-- Table structure for table `equipment`
--

DROP TABLE IF EXISTS `equipment`;
CREATE TABLE IF NOT EXISTS `equipment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `type` varchar(50) NOT NULL,
  `status` enum('Available','In Use','Maintenance') DEFAULT 'Available',
  `last_maintenance_date` date DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_by` int DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `equipment`
--

INSERT INTO `equipment` (`id`, `name`, `type`, `status`, `last_maintenance_date`, `created_at`, `updated_by`, `location`) VALUES
(1, 'Treadmill 1', 'Cardio', 'Available', NULL, '2025-05-01 10:08:04', NULL, NULL),
(3, 'Dumbbells Set', 'Free Weights', 'Available', NULL, '2025-05-01 10:09:26', NULL, NULL),
(4, 'Elliptical Machine', 'Cardio', 'Maintenance', NULL, '2025-05-01 10:09:45', NULL, NULL),
(5, 'Leg Press Machine', 'Strength', 'Available', NULL, '2025-05-01 10:10:32', NULL, NULL);

--
-- Triggers `equipment`
--
DROP TRIGGER IF EXISTS `equipment_after_update`;
DELIMITER $$
CREATE TRIGGER `equipment_after_update` AFTER UPDATE ON `equipment` FOR EACH ROW BEGIN
    IF OLD.status != NEW.status THEN
        INSERT INTO activity_log (module, action, user_id)
        VALUES ('Equipment', CONCAT('Status changed from ', OLD.status, ' to ', NEW.status, ' for equipment: ', NEW.name), NEW.updated_by);
    END IF;
    
    IF OLD.location != NEW.location THEN
        INSERT INTO activity_log (module, action, user_id)
        VALUES ('Equipment', CONCAT('Location changed from ', OLD.location, ' to ', NEW.location, ' for equipment: ', NEW.name), NEW.updated_by);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_attachments`
--

DROP TABLE IF EXISTS `equipment_attachments`;
CREATE TABLE IF NOT EXISTS `equipment_attachments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `file_type` varchar(100) NOT NULL,
  `file_size` int NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `uploaded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_categories`
--

DROP TABLE IF EXISTS `equipment_categories`;
CREATE TABLE IF NOT EXISTS `equipment_categories` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_history`
--

DROP TABLE IF EXISTS `equipment_history`;
CREATE TABLE IF NOT EXISTS `equipment_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `action` enum('created','updated','status_changed','deleted') NOT NULL,
  `user_id` int DEFAULT NULL,
  `old_value` text COMMENT 'JSON encoded old values',
  `new_value` text COMMENT 'JSON encoded new values',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_images`
--

DROP TABLE IF EXISTS `equipment_images`;
CREATE TABLE IF NOT EXISTS `equipment_images` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `is_primary` tinyint(1) DEFAULT '0',
  `uploaded_by` int DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `uploaded_by` (`uploaded_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_logs`
--

DROP TABLE IF EXISTS `equipment_logs`;
CREATE TABLE IF NOT EXISTS `equipment_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `updated_by` int NOT NULL,
  `status` enum('Available','In Use','Under Maintenance') DEFAULT NULL,
  `update_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `updated_by` (`updated_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_maintenance`
--

DROP TABLE IF EXISTS `equipment_maintenance`;
CREATE TABLE IF NOT EXISTS `equipment_maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `maintenance_type` varchar(50) NOT NULL,
  `performed_by` int DEFAULT NULL,
  `maintenance_date` date NOT NULL,
  `cost` decimal(10,2) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `performed_by` (`performed_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_purchases`
--

DROP TABLE IF EXISTS `equipment_purchases`;
CREATE TABLE IF NOT EXISTS `equipment_purchases` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `supplier_id` int DEFAULT NULL,
  `purchase_order_number` varchar(100) DEFAULT NULL,
  `invoice_number` varchar(100) DEFAULT NULL,
  `warranty_end_date` date DEFAULT NULL,
  `purchase_notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `supplier_id` (`supplier_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_suppliers`
--

DROP TABLE IF EXISTS `equipment_suppliers`;
CREATE TABLE IF NOT EXISTS `equipment_suppliers` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `contact_person` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `address` text,
  `website` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_types`
--

DROP TABLE IF EXISTS `equipment_types`;
CREATE TABLE IF NOT EXISTS `equipment_types` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `equipment_types`
--

INSERT INTO `equipment_types` (`id`, `name`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Cardio', 'Cardiovascular training equipment', '2025-05-02 00:47:03', '2025-05-02 00:47:03'),
(2, 'Strength', 'Strength training and weight equipment', '2025-05-02 00:47:03', '2025-05-02 00:47:03'),
(3, 'Functional', 'Functional training equipment', '2025-05-02 00:47:03', '2025-05-02 00:47:03'),
(4, 'Free Weights', 'Dumbbells, barbells, and weight plates', '2025-05-02 00:47:03', '2025-05-02 00:47:03'),
(5, 'Accessories', 'Exercise mats, balls, and other accessories', '2025-05-02 00:47:03', '2025-05-02 00:47:03');

-- --------------------------------------------------------

--
-- Table structure for table `equipment_usage`
--

DROP TABLE IF EXISTS `equipment_usage`;
CREATE TABLE IF NOT EXISTS `equipment_usage` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `member_name` varchar(100) DEFAULT NULL,
  `usage_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_time` time DEFAULT NULL,
  `duration` int DEFAULT NULL,
  `notes` text,
  `recorded_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `recorded_by` (`recorded_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `equipment_usage_stats`
--

DROP TABLE IF EXISTS `equipment_usage_stats`;
CREATE TABLE IF NOT EXISTS `equipment_usage_stats` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `date` date NOT NULL,
  `total_usage_count` int DEFAULT '0',
  `total_usage_minutes` int DEFAULT '0',
  `avg_usage_minutes` decimal(10,2) DEFAULT '0.00',
  `peak_hour` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `equipment_id` (`equipment_id`,`date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `equipment_usage_summary`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `equipment_usage_summary`;
CREATE TABLE IF NOT EXISTS `equipment_usage_summary` (
`avg_usage_minutes` decimal(14,4)
,`id` int
,`last_used_date` date
,`name` varchar(100)
,`total_usage_minutes` decimal(32,0)
,`type` varchar(50)
,`usage_count` bigint
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `equipment_with_maintenance`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `equipment_with_maintenance`;
CREATE TABLE IF NOT EXISTS `equipment_with_maintenance` (
`created_at` timestamp
,`id` int
,`last_maintenance_date` date
,`location` varchar(100)
,`name` varchar(100)
,`pending_maintenance` bigint
,`status` enum('Available','In Use','Maintenance')
,`total_maintenance` bigint
,`total_maintenance_cost` decimal(32,2)
,`type` varchar(50)
,`updated_by` int
);

-- --------------------------------------------------------

--
-- Table structure for table `exercises`
--

DROP TABLE IF EXISTS `exercises`;
CREATE TABLE IF NOT EXISTS `exercises` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `category` enum('Cardio','Strength','Flexibility','Balance') NOT NULL,
  `description` text,
  `difficulty` enum('Beginner','Intermediate','Advanced') DEFAULT NULL,
  `duration_minutes` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `exercise_equipment`
--

DROP TABLE IF EXISTS `exercise_equipment`;
CREATE TABLE IF NOT EXISTS `exercise_equipment` (
  `id` int NOT NULL AUTO_INCREMENT,
  `exercise_id` int NOT NULL,
  `equipment_id` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `exercise_id` (`exercise_id`),
  KEY `equipment_id` (`equipment_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `gym_sessions`
--

DROP TABLE IF EXISTS `gym_sessions`;
CREATE TABLE IF NOT EXISTS `gym_sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `title` varchar(100) NOT NULL,
  `trainer_id` int DEFAULT NULL,
  `session_type` enum('Personal','Group','Class') NOT NULL,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `max_capacity` int DEFAULT NULL,
  `current_participants` int DEFAULT '0',
  `status` enum('Scheduled','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Scheduled',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `trainer_id` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory`
--

DROP TABLE IF EXISTS `inventory`;
CREATE TABLE IF NOT EXISTS `inventory` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `category` varchar(50) NOT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `unit_price` decimal(10,2) DEFAULT '0.00',
  `supplier` varchar(100) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `min_quantity` int DEFAULT '5',
  `description` text,
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_checks`
--

DROP TABLE IF EXISTS `inventory_checks`;
CREATE TABLE IF NOT EXISTS `inventory_checks` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `check_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_check_items`
--

DROP TABLE IF EXISTS `inventory_check_items`;
CREATE TABLE IF NOT EXISTS `inventory_check_items` (
  `id` int NOT NULL AUTO_INCREMENT,
  `check_id` int NOT NULL,
  `inventory_id` int NOT NULL,
  `expected_quantity` int NOT NULL,
  `actual_quantity` int NOT NULL,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `check_id` (`check_id`),
  KEY `inventory_id` (`inventory_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_log`
--

DROP TABLE IF EXISTS `inventory_log`;
CREATE TABLE IF NOT EXISTS `inventory_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `quantity` int NOT NULL,
  `action_type` enum('Added','Removed','Updated') NOT NULL,
  `user_id` int DEFAULT NULL,
  `action_date` datetime NOT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
--

DROP TABLE IF EXISTS `inventory_transactions`;
CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `inventory_id` int NOT NULL,
  `user_id` int NOT NULL,
  `transaction_type` enum('add','subtract','set','check') NOT NULL,
  `quantity_before` int NOT NULL,
  `quantity_after` int NOT NULL,
  `adjustment_quantity` int NOT NULL,
  `reason` text,
  `transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `inventory_id` (`inventory_id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `attempt_type` enum('login','reset') NOT NULL,
  `attempt_time` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ip_address` (`ip_address`),
  KEY `email` (`email`),
  KEY `attempt_time` (`attempt_time`),
  KEY `idx_ip_time` (`ip_address`,`attempt_time`),
  KEY `idx_email_time` (`email`,`attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_history`
--

DROP TABLE IF EXISTS `login_history`;
CREATE TABLE IF NOT EXISTS `login_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL,
  `login_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `status` enum('success','failed') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

DROP TABLE IF EXISTS `login_logs`;
CREATE TABLE IF NOT EXISTS `login_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `role` varchar(20) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=137 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`id`, `email`, `success`, `ip_address`, `user_agent`, `role`, `timestamp`) VALUES
(1, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-07 02:10:55'),
(2, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-07 23:33:40'),
(3, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-09 01:29:23'),
(4, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-09 01:30:05'),
(5, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-09 02:34:23'),
(6, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-09 02:34:41'),
(7, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-09 02:51:31'),
(8, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 01:45:02'),
(9, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 01:47:21'),
(10, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 01:54:41'),
(11, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 03:16:26'),
(12, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 03:34:07'),
(13, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-10 04:01:20'),
(14, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-10 04:02:26'),
(15, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-11 08:08:24'),
(16, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-11 09:06:55'),
(17, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-12 01:34:49'),
(18, 'joyce.eli@st.rmu.edu.gh', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-04-12 01:36:00'),
(19, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-12 01:48:38'),
(20, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-12 02:42:38'),
(21, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-12 03:07:07'),
(22, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-12 03:07:29'),
(23, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-12 03:18:04'),
(24, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-12 03:45:56'),
(25, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-13 02:05:51'),
(26, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-13 02:56:28'),
(27, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-13 03:11:27'),
(28, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-13 03:13:26'),
(29, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-13 12:04:36'),
(30, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-14 01:08:45'),
(31, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-14 01:33:12'),
(32, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-14 01:37:45'),
(33, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-14 01:44:07'),
(34, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-14 01:53:45'),
(35, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-14 03:18:26'),
(36, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-14 03:24:00'),
(37, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-14 11:47:55'),
(38, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-14 12:01:14'),
(39, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-14 12:02:03'),
(40, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-14 12:10:50'),
(41, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-15 00:41:37'),
(42, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-15 01:58:04'),
(43, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-15 03:14:31'),
(44, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-15 03:23:22'),
(45, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-15 11:27:32'),
(46, 'harry-johnson.agyeman@rmu.edu.gh', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-04-15 11:36:00'),
(47, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-15 11:36:19'),
(48, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-15 11:44:21'),
(49, 'harry-johnson.agyeman@rmu.edu.gh', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-04-15 11:58:28'),
(50, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-15 11:58:43'),
(51, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-15 12:00:46'),
(52, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-15 12:08:34'),
(53, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-16 01:46:41'),
(54, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-16 01:49:49'),
(55, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-18 14:21:08'),
(56, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-18 14:24:42'),
(57, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-18 14:25:15'),
(58, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-18 14:25:38'),
(59, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-20 17:39:28'),
(60, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-20 17:47:02'),
(61, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-22 09:09:41'),
(62, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-24 01:27:36'),
(63, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-26 17:48:39'),
(64, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 17:36:12'),
(65, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 17:37:49'),
(66, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-27 17:38:00'),
(67, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 18:04:49'),
(68, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-27 18:05:06'),
(69, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-27 18:39:21'),
(70, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 18:48:24'),
(71, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 18:53:23'),
(72, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-27 18:54:14'),
(73, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 18:56:42'),
(74, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-27 19:09:27'),
(75, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:10:19'),
(76, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:13:42'),
(77, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:16:47'),
(78, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:18:39'),
(79, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:19:22'),
(80, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:20:50'),
(81, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:21:10'),
(82, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-27 19:21:31'),
(83, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:24:14'),
(84, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:27:02'),
(85, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:31:22'),
(86, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:32:08'),
(87, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:32:36'),
(88, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:45:45'),
(89, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:51:31'),
(90, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-27 19:51:59'),
(91, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 19:52:31'),
(92, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:53:22'),
(93, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:54:49'),
(94, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 19:55:12'),
(95, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:00:25'),
(96, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:03:41'),
(97, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:07:46'),
(98, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:08:41'),
(99, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:23:16'),
(100, 'joyce.eli@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Trainer', '2025-04-27 20:27:24'),
(101, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:31:13'),
(102, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-04-27 20:38:03'),
(103, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-28 03:05:14'),
(104, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-28 05:36:13'),
(105, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-28 09:00:53'),
(106, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-28 09:49:03'),
(107, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-28 14:04:29'),
(108, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-04-28 16:20:57'),
(109, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-04-30 01:42:57'),
(110, 'lovelacejohnkwakubaidoo@gmail.com', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Member', '2025-05-01 10:15:58'),
(111, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 10:16:22'),
(112, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 10:35:02'),
(113, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 10:46:12'),
(114, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 10:49:46'),
(115, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 11:14:04'),
(116, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 11:41:34'),
(117, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 12:10:54'),
(118, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-01 13:11:06'),
(119, 'admin@elitefitgym.com', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-05-01 16:00:52'),
(120, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'Admin', '2025-05-01 16:01:01'),
(121, 'admin@elitefitgym.com', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-05-02 01:06:20'),
(122, 'admin@elitefit.com', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', NULL, '2025-05-02 01:08:23'),
(123, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36 Edg/135.0.0.0', 'EquipmentManager', '2025-05-02 01:32:57'),
(124, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-05 08:42:53'),
(125, 'lovelace.baidoo@st.rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'Admin', '2025-05-05 14:06:18'),
(126, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-05 14:19:23'),
(127, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-05 21:37:12'),
(128, 'harry-johnson.agyemang@rmu.edu.gh', 0, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', NULL, '2025-05-05 23:10:56'),
(129, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-05 23:11:10'),
(130, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-05 23:44:54'),
(131, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-06 00:47:44'),
(132, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-06 00:55:50'),
(133, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-06 00:59:58'),
(134, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-07 06:55:15'),
(135, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-07 07:44:51'),
(136, 'harry-johnson.agyemang@rmu.edu.gh', 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/136.0.0.0 Safari/537.36 Edg/136.0.0.0', 'EquipmentManager', '2025-05-07 08:18:58');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance`
--

DROP TABLE IF EXISTS `maintenance`;
CREATE TABLE IF NOT EXISTS `maintenance` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `maintenance_date` date NOT NULL,
  `description` text NOT NULL,
  `technician` varchar(255) NOT NULL,
  `status` enum('pending','in_progress','completed','cancelled') NOT NULL DEFAULT 'pending',
  `cost` decimal(10,2) DEFAULT '0.00',
  `completion_date` date DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Triggers `maintenance`
--
DROP TRIGGER IF EXISTS `after_maintenance_complete`;
DELIMITER $$
CREATE TRIGGER `after_maintenance_complete` AFTER UPDATE ON `maintenance` FOR EACH ROW BEGIN
    IF NEW.status = 'completed' AND OLD.status != 'completed' THEN
        UPDATE equipment 
        SET last_maintenance_date = NEW.completion_date,
            status = 'available'
        WHERE id = NEW.equipment_id;
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_history`
--

DROP TABLE IF EXISTS `maintenance_history`;
CREATE TABLE IF NOT EXISTS `maintenance_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `maintenance_date` date NOT NULL,
  `description` text NOT NULL,
  `performed_by` varchar(100) DEFAULT NULL,
  `cost` decimal(10,2) DEFAULT '0.00',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_records`
--

DROP TABLE IF EXISTS `maintenance_records`;
CREATE TABLE IF NOT EXISTS `maintenance_records` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `maintenance_date` date NOT NULL,
  `maintenance_type` varchar(50) NOT NULL,
  `description` text,
  `cost` decimal(10,2) DEFAULT NULL,
  `parts_replaced` text,
  `notes` text,
  `performed_by` int DEFAULT NULL,
  `schedule_id` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `performed_by` (`performed_by`),
  KEY `schedule_id` (`schedule_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_schedule`
--

DROP TABLE IF EXISTS `maintenance_schedule`;
CREATE TABLE IF NOT EXISTS `maintenance_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `equipment_id` int NOT NULL,
  `scheduled_date` date NOT NULL,
  `description` text,
  `status` enum('Scheduled','In Progress','Completed','Overdue') DEFAULT 'Scheduled',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `maintenance_summary`
-- (See below for the actual view)
--
DROP VIEW IF EXISTS `maintenance_summary`;
CREATE TABLE IF NOT EXISTS `maintenance_summary` (
`completed_count` decimal(23,0)
,`equipment_id` int
,`equipment_name` varchar(100)
,`equipment_type` varchar(50)
,`last_maintenance_date` date
,`maintenance_count` bigint
,`pending_count` decimal(23,0)
,`total_cost` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `meals`
--

DROP TABLE IF EXISTS `meals`;
CREATE TABLE IF NOT EXISTS `meals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `meal_date` date NOT NULL,
  `meal_type` varchar(50) NOT NULL,
  `name` varchar(255) NOT NULL,
  `calories` int NOT NULL,
  `protein` float NOT NULL,
  `carbs` float NOT NULL,
  `fat` float NOT NULL,
  `fiber` float DEFAULT '0',
  `sugar` float DEFAULT '0',
  `sodium` float DEFAULT '0',
  `is_favorite` tinyint(1) DEFAULT '0',
  `meal_image` varchar(255) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`meal_date`),
  KEY `is_favorite` (`is_favorite`)
) ENGINE=MyISAM AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `meals`
--

INSERT INTO `meals` (`id`, `user_id`, `meal_date`, `meal_type`, `name`, `calories`, `protein`, `carbs`, `fat`, `fiber`, `sugar`, `sodium`, `is_favorite`, `meal_image`, `notes`, `created_at`) VALUES
(1, 5, '2025-04-27', 'Lunch', 'Chicken Salad', 450, 35, 20, 25, 5, 3, 300, 0, NULL, NULL, '2025-04-27 17:57:06'),
(2, 5, '2025-04-27', 'Snacks', 'Protein Shake', 200, 25, 10, 5, 1, 5, 100, 0, NULL, NULL, '2025-04-27 17:57:47');

-- --------------------------------------------------------

--
-- Table structure for table `meal_entries`
--

DROP TABLE IF EXISTS `meal_entries`;
CREATE TABLE IF NOT EXISTS `meal_entries` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `meal_type` varchar(20) NOT NULL,
  `food_name` varchar(100) NOT NULL,
  `calories` int NOT NULL,
  `protein` float NOT NULL,
  `carbs` float NOT NULL,
  `fat` float NOT NULL,
  `quantity` varchar(50) NOT NULL,
  `entry_date` date NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`,`entry_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `meal_templates`
--

DROP TABLE IF EXISTS `meal_templates`;
CREATE TABLE IF NOT EXISTS `meal_templates` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `meal_type` varchar(50) NOT NULL,
  `calories` int NOT NULL,
  `protein` float NOT NULL,
  `carbs` float NOT NULL,
  `fat` float NOT NULL,
  `fiber` float DEFAULT '0',
  `sugar` float DEFAULT '0',
  `sodium` float DEFAULT '0',
  `is_public` tinyint(1) DEFAULT '1',
  `created_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `created_by` (`created_by`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `meal_templates`
--

INSERT INTO `meal_templates` (`id`, `name`, `meal_type`, `calories`, `protein`, `carbs`, `fat`, `fiber`, `sugar`, `sodium`, `is_public`, `created_by`, `created_at`) VALUES
(1, 'Oatmeal with Berries', 'Breakfast', 350, 10, 60, 7, 8, 12, 50, 1, NULL, '2025-04-24 01:27:43'),
(2, 'Greek Yogurt with Honey', 'Breakfast', 220, 15, 25, 5, 0, 20, 70, 1, NULL, '2025-04-24 01:27:43'),
(3, 'Chicken Salad', 'Lunch', 450, 35, 20, 25, 5, 3, 300, 1, NULL, '2025-04-24 01:27:43'),
(4, 'Salmon with Vegetables', 'Dinner', 550, 40, 30, 25, 6, 5, 400, 1, NULL, '2025-04-24 01:27:43'),
(5, 'Protein Shake', 'Snacks', 200, 25, 10, 5, 1, 5, 100, 1, NULL, '2025-04-24 01:27:43');

-- --------------------------------------------------------

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
CREATE TABLE IF NOT EXISTS `members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `membership_id` varchar(50) DEFAULT NULL,
  `membership_type` varchar(50) DEFAULT NULL,
  `status` enum('Active','Inactive','Suspended') DEFAULT 'Active',
  `barcode` varchar(50) DEFAULT NULL,
  `rfid_tag` varchar(50) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `membership_id` (`membership_id`),
  UNIQUE KEY `barcode` (`barcode`),
  UNIQUE KEY `rfid_tag` (`rfid_tag`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `membership_plans`
--

DROP TABLE IF EXISTS `membership_plans`;
CREATE TABLE IF NOT EXISTS `membership_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `duration` int NOT NULL COMMENT 'Duration in months',
  `price` decimal(10,2) NOT NULL,
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_messages`
--

DROP TABLE IF EXISTS `member_messages`;
CREATE TABLE IF NOT EXISTS `member_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `receiver_id` int NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`),
  KEY `is_read` (`is_read`)
) ENGINE=MyISAM AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `member_messages`
--

INSERT INTO `member_messages` (`id`, `sender_id`, `receiver_id`, `message`, `is_read`, `created_at`) VALUES
(1, 5, 4, 'hi', 0, '2025-04-15 03:12:42'),
(2, 5, 4, 'hi', 0, '2025-04-15 12:07:37'),
(3, 5, 4, 'hi', 0, '2025-04-16 01:49:31');

-- --------------------------------------------------------

--
-- Table structure for table `member_notifications`
--

DROP TABLE IF EXISTS `member_notifications`;
CREATE TABLE IF NOT EXISTS `member_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `message` text NOT NULL,
  `icon` varchar(50) DEFAULT 'bell',
  `is_read` tinyint(1) DEFAULT '0',
  `link` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `is_read` (`is_read`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_nutrition`
--

DROP TABLE IF EXISTS `member_nutrition`;
CREATE TABLE IF NOT EXISTS `member_nutrition` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `log_date` date NOT NULL,
  `meal_type` enum('breakfast','lunch','dinner','snack') NOT NULL,
  `food_name` varchar(100) NOT NULL,
  `calories` int DEFAULT NULL,
  `protein` decimal(5,2) DEFAULT NULL,
  `carbs` decimal(5,2) DEFAULT NULL,
  `fat` decimal(5,2) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `log_date` (`log_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `member_settings`
--

DROP TABLE IF EXISTS `member_settings`;
CREATE TABLE IF NOT EXISTS `member_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `theme_preference` varchar(20) DEFAULT 'dark',
  `notification_email` tinyint(1) DEFAULT '1',
  `notification_sms` tinyint(1) DEFAULT '0',
  `show_weight_on_profile` tinyint(1) DEFAULT '0',
  `measurement_unit` varchar(10) DEFAULT 'metric',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `member_settings`
--

INSERT INTO `member_settings` (`id`, `user_id`, `theme_preference`, `notification_email`, `notification_sms`, `show_weight_on_profile`, `measurement_unit`, `created_at`, `updated_at`) VALUES
(1, 5, 'dark', 1, 0, 0, 'metric', '2025-04-15 12:02:13', '2025-04-27 19:09:30');

-- --------------------------------------------------------

--
-- Table structure for table `member_subscriptions`
--

DROP TABLE IF EXISTS `member_subscriptions`;
CREATE TABLE IF NOT EXISTS `member_subscriptions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `plan_id` int NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `payment_status` enum('Paid','Pending','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  `amount_paid` decimal(10,2) NOT NULL,
  `payment_method` varchar(50) DEFAULT NULL,
  `transaction_id` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `plan_id` (`plan_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `mobile_access_tokens`
--

DROP TABLE IF EXISTS `mobile_access_tokens`;
CREATE TABLE IF NOT EXISTS `mobile_access_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(191) NOT NULL,
  `device_info` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` datetime NOT NULL,
  `last_used_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

DROP TABLE IF EXISTS `notifications`;
CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int DEFAULT NULL COMMENT 'NULL for system-wide notifications',
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` enum('Info','Success','Warning','Error') NOT NULL DEFAULT 'Info',
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notification_settings`
--

DROP TABLE IF EXISTS `notification_settings`;
CREATE TABLE IF NOT EXISTS `notification_settings` (
  `user_id` int NOT NULL,
  `email_notifications` tinyint(1) NOT NULL DEFAULT '1',
  `maintenance_reminders` tinyint(1) NOT NULL DEFAULT '1',
  `inventory_alerts` tinyint(1) NOT NULL DEFAULT '1',
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_goals`
--

DROP TABLE IF EXISTS `nutrition_goals`;
CREATE TABLE IF NOT EXISTS `nutrition_goals` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `daily_calorie_goal` int DEFAULT '2000',
  `daily_protein_goal` int DEFAULT '150',
  `daily_carbs_goal` int DEFAULT '200',
  `daily_fat_goal` int DEFAULT '65',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `nutrition_goals`
--

INSERT INTO `nutrition_goals` (`id`, `user_id`, `daily_calorie_goal`, `daily_protein_goal`, `daily_carbs_goal`, `daily_fat_goal`, `created_at`, `updated_at`) VALUES
(1, 5, 2000, 150, 200, 65, '2025-04-15 03:54:09', '2025-04-15 03:54:09');

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_logs`
--

DROP TABLE IF EXISTS `nutrition_logs`;
CREATE TABLE IF NOT EXISTS `nutrition_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `log_date` date NOT NULL,
  `total_calories` int NOT NULL,
  `total_protein` float NOT NULL,
  `total_carbs` float NOT NULL,
  `total_fat` float NOT NULL,
  `total_water` int NOT NULL,
  `weight` float DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`log_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `nutrition_settings`
--

DROP TABLE IF EXISTS `nutrition_settings`;
CREATE TABLE IF NOT EXISTS `nutrition_settings` (
  `user_id` int NOT NULL,
  `daily_calories` int DEFAULT '2000',
  `protein_target` int DEFAULT '150',
  `carbs_target` int DEFAULT '200',
  `fat_target` int DEFAULT '65',
  `water_target` int DEFAULT '2500',
  `fiber_target` int DEFAULT '30',
  `sugar_target` int DEFAULT '50',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `nutrition_settings`
--

INSERT INTO `nutrition_settings` (`user_id`, `daily_calories`, `protein_target`, `carbs_target`, `fat_target`, `water_target`, `fiber_target`, `sugar_target`, `created_at`, `updated_at`) VALUES
(5, 2000, 150, 200, 65, 2500, 30, 50, '2025-04-24 01:27:43', '2025-04-24 01:27:43');

-- --------------------------------------------------------

--
-- Table structure for table `parts`
--

DROP TABLE IF EXISTS `parts`;
CREATE TABLE IF NOT EXISTS `parts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `part_number` varchar(50) DEFAULT NULL,
  `description` text,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int NOT NULL DEFAULT '0',
  `min_quantity` int DEFAULT '5',
  `cost` decimal(10,2) DEFAULT NULL,
  `location` varchar(100) DEFAULT NULL,
  `supplier` varchar(100) DEFAULT NULL,
  `supplier_contact` varchar(100) DEFAULT NULL,
  `barcode` varchar(50) DEFAULT NULL,
  `qr_code` varchar(191) DEFAULT NULL,
  `created_by` int DEFAULT NULL,
  `updated_by` int DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `barcode` (`barcode`),
  UNIQUE KEY `qr_code` (`qr_code`),
  KEY `created_by` (`created_by`),
  KEY `updated_by` (`updated_by`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Triggers `parts`
--
DROP TRIGGER IF EXISTS `parts_after_update`;
DELIMITER $$
CREATE TRIGGER `parts_after_update` AFTER UPDATE ON `parts` FOR EACH ROW BEGIN
    IF NEW.quantity <= NEW.min_quantity AND OLD.quantity > OLD.min_quantity THEN
        INSERT INTO activity_log (module, action, user_id)
        VALUES ('Inventory', CONCAT('Low stock alert: ', NEW.name, ' (', NEW.quantity, ' remaining)'), NEW.updated_by);
    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `password_history`
--

DROP TABLE IF EXISTS `password_history`;
CREATE TABLE IF NOT EXISTS `password_history` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `idx_user_id_created` (`user_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset`
--

DROP TABLE IF EXISTS `password_reset`;
CREATE TABLE IF NOT EXISTS `password_reset` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `token` (`token`),
  KEY `user_id` (`user_id`),
  KEY `idx_token` (`token`),
  KEY `idx_expires` (`expires`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `password_reset`
--

INSERT INTO `password_reset` (`id`, `user_id`, `token`, `expires`, `created_at`) VALUES
(15, 8, 'c2d3692a070a063635c07a11ea28a29ac6aae9064fb63871a7fa98d5dbec0b1c', '2025-05-06 01:25:30', '2025-05-06 00:25:30');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(255) NOT NULL,
  `expiry` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `expires` datetime NOT NULL,
  `reset_method` varchar(20) NOT NULL DEFAULT 'email',
  `verification_code` varchar(6) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_user` (`id`),
  KEY `password_resets_ibfk_1` (`user_id`)
) ENGINE=MyISAM AUTO_INCREMENT=40 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `password_resets`
--

INSERT INTO `password_resets` (`id`, `user_id`, `token`, `expiry`, `created_at`, `expires`, `reset_method`, `verification_code`) VALUES
(1, 0, 'fd29f17b3efb29f8c7d325a01802f9f50c9609bfd18025bcdb3d3f3c013f3523', '0000-00-00 00:00:00', '2025-04-27 21:36:58', '0000-00-00 00:00:00', 'email', NULL),
(2, 8, '3da7ee1c0cba79e6a28999e10fb594810250b17a56a091de20be8d1ab6257102', '2025-04-28 04:49:17', '2025-04-28 03:45:00', '0000-00-00 00:00:00', 'email', NULL),
(3, 8, '69da4e376e84449d04f3f49c40944a05f506d4cf455f6b6e15616b4c9325048a', '0000-00-00 00:00:00', '2025-05-02 12:51:08', '2025-05-02 13:51:08', 'email', NULL),
(4, 8, '41055cbde804847a425d2d560645563f0dfc16052ff21c81ab0ff19727830ae68b27da0b4ab5b9940bc6ea6b7be19e4f5614', '0000-00-00 00:00:00', '2025-05-02 13:28:13', '2025-05-02 14:28:13', 'email', NULL),
(5, 8, 'a5b823c071447efa75c5c8cc8a3b4d9c9d23caeda8864add6e91aea88b9618fe75cd05e9cc744d27bfd376e96e61e11ca84b', '0000-00-00 00:00:00', '2025-05-02 14:22:30', '2025-05-02 15:22:30', 'email', NULL),
(6, 8, '24fb88fba4726c07ab937bf640305435210d90e15ee71ec25b8f895c22446e2e0c8f5a78053e8810cd071d925ecae56fd8c9', '0000-00-00 00:00:00', '2025-05-02 14:23:50', '2025-05-02 15:23:50', 'email', NULL),
(7, 8, '5e1c492056eaede6da4294b5635868c665c92cdcd856413b5551f62cdbebeb3e7ef626ec04f2f6e427e2dd5b8e352267ecbb', '0000-00-00 00:00:00', '2025-05-02 14:25:06', '2025-05-02 15:25:06', 'email', NULL),
(8, 8, '343cda99830b517a8207d919c4c571c4b11ff7f5e736ea054f575aa5d5e6da8e560cf6101785e000bed8e3193cb9d710a11b', '0000-00-00 00:00:00', '2025-05-02 14:25:10', '2025-05-02 15:25:10', 'email', NULL),
(9, 8, 'a7a709807a59a4e24ba68b048fcd156cd47c3270e7f7bbdbde8383cf4fb28195bfa53ff70313b87e476242f7712176e0c44b', '0000-00-00 00:00:00', '2025-05-02 14:25:36', '2025-05-02 15:25:36', 'email', NULL),
(10, 8, 'a4ce2ed892aa916ac2fd05a1adba436a23e6757149ba4df07d4623e58b718d42c02c56f80efe273a5644467fc9d93272eabe', '0000-00-00 00:00:00', '2025-05-02 14:27:16', '2025-05-02 15:27:16', 'email', NULL),
(11, 8, 'df571494f05ba4d86de73a1ff68714ec3d66b598977ab5497d1a74c9a39e311664a7ae3bef9e4155f837150653970192c11f', '0000-00-00 00:00:00', '2025-05-02 14:27:23', '2025-05-02 15:27:23', 'email', NULL),
(12, 8, 'b4f6531e226bda9963d39a3abca1563fbf63b9d59552cbd4f86f847144ceaec30210b403d347398c0946b56f96bf412662fd', '0000-00-00 00:00:00', '2025-05-02 14:32:02', '2025-05-02 15:32:02', 'email', NULL),
(13, 8, '1cc22a761ab695b722ecdb678bd3b0563cc51cc0f0eacf6f5d4c958ad5d9f9b5e9424ec711273e70d0f02735a43738518430', '0000-00-00 00:00:00', '2025-05-02 14:33:07', '2025-05-02 15:33:07', 'email', NULL),
(14, 8, '532aa1b2382dfc8d8cbacd73f4ee045fd0003d361f0ec014f47602966fc5a2c2a5a9cd77c97d81309c050c9b6a7fc86b2891', '0000-00-00 00:00:00', '2025-05-02 14:36:29', '2025-05-02 15:36:29', 'email', NULL),
(15, 8, 'aa518a851ed32fa29c6d899db3dc8fd7a25e203fc7251630e61c77842e7c70e316b7299fd1129cf283ccd777ff347775d07a', '0000-00-00 00:00:00', '2025-05-02 14:37:38', '2025-05-02 15:37:38', 'email', NULL),
(16, 8, '999f0b90b5b6fc38f916918a6a6ae0e40196b03ed55f150c31299df30c2613a3564d9c4cae7795a6f0f5c2eff038bee2c2e7', '0000-00-00 00:00:00', '2025-05-02 14:38:30', '2025-05-02 15:38:30', 'email', NULL),
(17, 8, '9335b688d711467aafcb4cc113e3f2d97a6eb3147803484ca9f93d38849db7414c10d5389d0b2a0a43e97d77bafc509c8db1', '0000-00-00 00:00:00', '2025-05-02 14:41:31', '2025-05-02 15:41:31', 'email', NULL),
(18, 8, '374284ae67fe93d240caea569ab08e5e9ffce7ea4e2233bc8da2bf5889bb21edfa5efa57a580f36f797e7908c2e019541e96', '0000-00-00 00:00:00', '2025-05-02 14:43:57', '2025-05-02 15:43:57', 'email', NULL),
(19, 8, 'a6d27aef34ef3790af29f3034f58769d3ad4ba33f39bcf392555aa4dc6441479e31bce7ecc9eec9e6f209b2e39e95c88f2a2', '0000-00-00 00:00:00', '2025-05-02 14:45:09', '2025-05-02 15:45:09', 'email', NULL),
(20, 8, 'eaadebac9c3654ac01b4e279e5353f138d8586c201167d390d9503e6aaf1d8aa0c28cabdbce4c4b782c34277715c44cd81cb', '0000-00-00 00:00:00', '2025-05-02 14:49:16', '2025-05-02 15:49:16', 'email', NULL),
(21, 8, 'b9d69242f673b2cd09cb52a03cc937e0039843dc032f4a3ab357ae02517e729c6d220b75ca7a00339f44d79afda650877d6e', '0000-00-00 00:00:00', '2025-05-02 14:57:19', '2025-05-02 15:57:19', 'email', NULL),
(22, 8, 'fc025ce6515df6edc684dd71d7af1308ffdcc459552e65986a0aff9e5f8c48a79c0fd0ebad7ff190852af030a6cede043dc0', '0000-00-00 00:00:00', '2025-05-02 15:02:07', '2025-05-02 16:02:07', 'email', NULL),
(23, 8, 'dfc59d0af34c4404dd2896277d2b170a1a7ba6415a6c44703011acb41b0893229d985f767d7e8e41cc937081602921e1fd04', '0000-00-00 00:00:00', '2025-05-02 15:04:54', '2025-05-02 16:04:54', 'email', NULL),
(24, 8, '38850128e05323379f7d61dfe91efe53ec8af8a0e6f3029a8279724f64a47ea789fedfb63777871c669888b2985437a8b1c8', '0000-00-00 00:00:00', '2025-05-02 15:06:02', '2025-05-02 16:06:02', 'email', NULL),
(25, 8, '4ff6147b81bd3a6d4ac509bfff899f24245036a9f09035debb9fe50eca93ce300606328cc583002f5c542b0ff00d0f0a5487', '0000-00-00 00:00:00', '2025-05-02 15:07:17', '2025-05-02 16:07:17', 'email', NULL),
(26, 8, 'bf867d8e8d2094f77d594d681d40557d28a6d479f8d4ec1b8ba3178f066c14aa5e89af56e7d247985734a50ccc2d5e00d117', '0000-00-00 00:00:00', '2025-05-02 15:10:32', '2025-05-02 16:10:32', 'email', NULL),
(27, 8, 'd07db8758fa141e810e6507243cd20fdfabee867d0f75f2e6c9726d307b15f13baa7e69ba5d0d340e9a2524e184d8de8e2d5', '0000-00-00 00:00:00', '2025-05-05 08:41:34', '2025-05-05 09:41:34', 'email', NULL),
(28, 8, 'bda8e36dcf8edbad2fa4858926df0b848722a7d368de9a391c3f44ebe3d870b0752ca3dbd52d71ccd4eaf24a5b9cba566de9', '0000-00-00 00:00:00', '2025-05-05 08:44:55', '2025-05-05 09:44:55', 'email', NULL),
(29, 8, 'e6dd8656ac2b717c2c81edb4a7a133a64781799f5d27801ba1b6f771357c89cb1aab66e9ce4a87aed5b2e45b7f5867e3e102', '0000-00-00 00:00:00', '2025-05-05 08:45:00', '2025-05-05 09:45:00', 'email', NULL),
(30, 8, 'ed81e939a458cd14847f263d69570c81f6f12fed0323ab6e5d4adec1583c81a81f6e11a5d7f57105b913b01c051e0f659dc5', '0000-00-00 00:00:00', '2025-05-05 08:45:37', '2025-05-05 09:45:37', 'email', NULL),
(31, 8, '00d40b4ce756d8b7070a62b641e9fbf79e72908b76196ba2e9bf9a38c631c5b1f940eb0555ce17a9d6a35410092c64574d1a', '0000-00-00 00:00:00', '2025-05-05 08:45:41', '2025-05-05 09:45:41', 'email', NULL),
(32, 8, '722e90c5e2f6d2e82dca49a34822edb3c0f95d429c50eb35d1a78ea0ab0066913d0fc30a6f093d0ed13273c098ae7bb9ace0', '0000-00-00 00:00:00', '2025-05-05 08:46:24', '2025-05-05 09:46:24', 'email', NULL),
(33, 8, 'd79c0bd80a90db39d7e3ea625a2106646e52b7749c3c9fffd9927341e884a53ab9cbd3d52d55e9603c92e03d58150aeb46dc', '0000-00-00 00:00:00', '2025-05-05 08:52:30', '2025-05-05 09:52:30', 'email', NULL),
(34, 8, '9786def5c0c29d808447eaac27cd6c072c92a44adc0ef69060a5a9be46a1154af0e274419b75d1ef89028baa8a015070eec5', '0000-00-00 00:00:00', '2025-05-05 08:52:58', '2025-05-05 09:52:58', 'email', NULL),
(35, 8, '5b638ca7d9b78546c31c56301c7315d9aef168dcef544e540b3993a2dc4690aba2bbb7dd30322c3c5df94d41a311ce29259f', '0000-00-00 00:00:00', '2025-05-05 08:55:51', '2025-05-05 09:55:51', 'email', NULL),
(36, 8, '9945ef89c4327d3b08866cc85b7421e8baf1b7973c06fd7dae00ad0dd1e38a3897fa41aac92cd586ec9854f68556eb84b83b', '0000-00-00 00:00:00', '2025-05-05 09:00:57', '2025-05-05 10:00:57', 'email', NULL),
(37, 8, '990fdc3f87748b5c519ce4573ec4fe53a48a21e212c02e35d7de8139bfe3036f074838ebba27ebd5a936e6ea9ece0f4cb867', '0000-00-00 00:00:00', '2025-05-05 09:01:16', '2025-05-05 10:01:16', 'email', NULL),
(38, 8, 'f5f48eb19a01daf7277a293e32444b2260cdb61e94d6141bb9028b0b345acbe999c7e8f61b2c836ce4406a31a07c1fd0da2b', '0000-00-00 00:00:00', '2025-05-05 21:45:38', '2025-05-05 22:45:38', 'email', NULL),
(39, 8, '55b40f808c85786037bb7ba8444995fd5dbedababaef05e53dd12ebd1b9c3637751991db257d205f59cc558f2c5de380137f', '0000-00-00 00:00:00', '2025-05-05 21:45:57', '2025-05-05 22:45:57', 'email', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

DROP TABLE IF EXISTS `password_reset_tokens`;
CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `expiry_date` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `progress_photos`
--

DROP TABLE IF EXISTS `progress_photos`;
CREATE TABLE IF NOT EXISTS `progress_photos` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `photo_url` varchar(255) NOT NULL,
  `photo_date` date NOT NULL,
  `photo_type` enum('front','side','back','other') DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `photo_date` (`photo_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `progress_tracking`
--

DROP TABLE IF EXISTS `progress_tracking`;
CREATE TABLE IF NOT EXISTS `progress_tracking` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `date` date NOT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `body_fat` decimal(5,2) DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `trainer_id` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `rate_limits`
--

DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE IF NOT EXISTS `rate_limits` (
  `id` int NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `action` varchar(50) NOT NULL,
  `timestamp` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ip_action` (`ip_address`,`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3;

-- --------------------------------------------------------

--
-- Table structure for table `recurring_appointments`
--

DROP TABLE IF EXISTS `recurring_appointments`;
CREATE TABLE IF NOT EXISTS `recurring_appointments` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `day_of_week` tinyint NOT NULL COMMENT '0=Sunday, 1=Monday, etc.',
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `frequency` enum('weekly','biweekly','monthly') NOT NULL DEFAULT 'weekly',
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('active','paused','cancelled') NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `trainer_id` (`trainer_id`),
  KEY `status` (`status`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registration_logs`
--

DROP TABLE IF EXISTS `registration_logs`;
CREATE TABLE IF NOT EXISTS `registration_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `email` varchar(100) NOT NULL,
  `success` tinyint(1) NOT NULL,
  `role` varchar(20) NOT NULL,
  `message` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `registration_logs`
--

INSERT INTO `registration_logs` (`id`, `email`, `success`, `role`, `message`, `ip_address`, `timestamp`) VALUES
(1, 'lovelacejohnkwakubaidoo@gmail.com', 1, 'Member', 'Registration successful', '::1', '2025-04-07 02:10:37'),
(2, 'lovelace.baidoo@st.rmu.edu.gh', 1, 'Admin', 'Registration successful', '::1', '2025-04-07 23:33:01'),
(3, 'joyce.eli@st.rmu.edu.gh', 1, 'Trainer', 'Registration successful', '::1', '2025-04-10 04:01:02'),
(4, 'joyce.eli@st.rmu.edu.gh', 1, 'Trainer', 'Registration successful', '::1', '2025-04-12 03:45:49'),
(5, 'lovelacejohnkwakubaidoo@gmail.com', 1, 'Member', 'Registration successful', '::1', '2025-04-14 01:43:59'),
(6, 'harry-johnson.agyemang@rmu.edu.gh', 1, 'EquipmentManager', 'Registration successful', '::1', '2025-04-14 12:01:00'),
(7, 'harry-johnson.agyemang@rmu.edu.gh', 0, 'Member', 'Email already exists', '::1', '2025-04-15 11:32:17'),
(8, 'harry-johnson.agyeman@rmu.edu.gh', 1, 'Member', 'Registration successful', '::1', '2025-04-15 11:35:36'),
(9, 'harry-johnson.agyemang@rmu.edu.gh', 1, 'EquipmentManager', 'Registration successful', '::1', '2025-04-18 14:24:36');

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `revenue_transactions`
--

DROP TABLE IF EXISTS `revenue_transactions`;
CREATE TABLE IF NOT EXISTS `revenue_transactions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `transaction_type` enum('Membership','Personal Training','Product Sale','Other') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `user_id` int DEFAULT NULL,
  `subscription_id` int DEFAULT NULL,
  `payment_method` varchar(50) NOT NULL,
  `transaction_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `notes` text,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `subscription_id` (`subscription_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `saved_reports`
--

DROP TABLE IF EXISTS `saved_reports`;
CREATE TABLE IF NOT EXISTS `saved_reports` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text,
  `report_type` enum('inventory','maintenance','usage') NOT NULL,
  `parameters` text NOT NULL COMMENT 'JSON encoded parameters',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `scan_logs`
--

DROP TABLE IF EXISTS `scan_logs`;
CREATE TABLE IF NOT EXISTS `scan_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `code_type` enum('barcode','qr_code','rfid') NOT NULL,
  `code_value` varchar(255) NOT NULL,
  `equipment_id` int DEFAULT NULL,
  `part_id` int DEFAULT NULL,
  `user_id` int DEFAULT NULL,
  `scan_purpose` varchar(50) DEFAULT NULL,
  `scan_location` varchar(100) DEFAULT NULL,
  `scan_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `equipment_id` (`equipment_id`),
  KEY `part_id` (`part_id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `sessions`
--

DROP TABLE IF EXISTS `sessions`;
CREATE TABLE IF NOT EXISTS `sessions` (
  `id` int NOT NULL AUTO_INCREMENT,
  `plan_id` int NOT NULL,
  `member_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `session_date` date NOT NULL,
  `session_time` time NOT NULL,
  `status` enum('Scheduled','Completed','Cancelled') DEFAULT 'Scheduled',
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `plan_id` (`plan_id`),
  KEY `member_id` (`member_id`),
  KEY `trainer_id` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_participants`
--

DROP TABLE IF EXISTS `session_participants`;
CREATE TABLE IF NOT EXISTS `session_participants` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `user_id` int NOT NULL,
  `status` enum('Registered','Checked In','Completed','Cancelled','No Show') NOT NULL DEFAULT 'Registered',
  `registration_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `check_in_time` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`,`user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `session_ratings`
--

DROP TABLE IF EXISTS `session_ratings`;
CREATE TABLE IF NOT EXISTS `session_ratings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `session_id` int NOT NULL,
  `member_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `rating` int NOT NULL,
  `feedback` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `session_id` (`session_id`,`member_id`),
  KEY `trainer_id` (`trainer_id`),
  KEY `member_id` (`member_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE IF NOT EXISTS `system_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text,
  `setting_description` text,
  `is_public` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`),
  KEY `setting_key_2` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_members`
--

DROP TABLE IF EXISTS `trainer_members`;
CREATE TABLE IF NOT EXISTS `trainer_members` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trainer_id` int NOT NULL,
  `member_id` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `status` enum('active','inactive','pending') DEFAULT 'active',
  PRIMARY KEY (`id`),
  KEY `trainer_id` (`trainer_id`),
  KEY `member_id` (`member_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_messages`
--

DROP TABLE IF EXISTS `trainer_messages`;
CREATE TABLE IF NOT EXISTS `trainer_messages` (
  `id` int NOT NULL AUTO_INCREMENT,
  `sender_id` int NOT NULL,
  `receiver_id` int NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `sender_id` (`sender_id`),
  KEY `receiver_id` (`receiver_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_notifications`
--

DROP TABLE IF EXISTS `trainer_notifications`;
CREATE TABLE IF NOT EXISTS `trainer_notifications` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trainer_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `message` text NOT NULL,
  `type` varchar(50) DEFAULT NULL,
  `is_read` tinyint(1) DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `trainer_id` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_profiles`
--

DROP TABLE IF EXISTS `trainer_profiles`;
CREATE TABLE IF NOT EXISTS `trainer_profiles` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `experience_years` int DEFAULT NULL,
  `certification` varchar(255) DEFAULT NULL,
  `bio` text,
  `hourly_rate` decimal(10,2) DEFAULT NULL,
  `availability` text,
  `profile_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `trainer_schedule`
--

DROP TABLE IF EXISTS `trainer_schedule`;
CREATE TABLE IF NOT EXISTS `trainer_schedule` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trainer_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `start_time` datetime NOT NULL,
  `end_time` datetime NOT NULL,
  `status` enum('scheduled','completed','cancelled') DEFAULT 'scheduled',
  `location` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `trainer_id` (`trainer_id`),
  KEY `member_id` (`member_id`)
) ENGINE=MyISAM AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `trainer_schedule`
--

INSERT INTO `trainer_schedule` (`id`, `trainer_id`, `member_id`, `title`, `description`, `start_time`, `end_time`, `status`, `location`, `created_at`, `updated_at`) VALUES
(1, 4, 5, 'Session Request', '', '2025-04-15 09:00:00', '2025-04-15 10:00:00', 'scheduled', NULL, '2025-04-15 12:06:17', '2025-04-15 12:06:17');

-- --------------------------------------------------------

--
-- Table structure for table `trainer_settings`
--

DROP TABLE IF EXISTS `trainer_settings`;
CREATE TABLE IF NOT EXISTS `trainer_settings` (
  `id` int NOT NULL AUTO_INCREMENT,
  `trainer_id` int NOT NULL,
  `notification_email` tinyint(1) DEFAULT '1',
  `notification_system` tinyint(1) DEFAULT '1',
  `calendar_view` varchar(20) DEFAULT 'week',
  `theme` varchar(20) DEFAULT 'dark',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `theme_preference` varchar(50) DEFAULT 'dark',
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_trainer_settings` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
CREATE TABLE IF NOT EXISTS `users` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('Member','Trainer','Admin','EquipmentManager') NOT NULL,
  `experience_level` varchar(50) DEFAULT NULL,
  `fitness_goals` text,
  `preferred_routines` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `height` varchar(20) DEFAULT NULL,
  `weight` decimal(5,2) DEFAULT NULL,
  `date_of_birth` date DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `locked_until` datetime DEFAULT NULL,
  `failed_attempts` int DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  KEY `idx_locked_until` (`locked_until`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `name`, `email`, `password`, `role`, `experience_level`, `fitness_goals`, `preferred_routines`, `created_at`, `height`, `weight`, `date_of_birth`, `profile_image`, `phone`, `locked_until`, `failed_attempts`) VALUES
(2, 'Shurface123', 'lovelace.baidoo@st.rmu.edu.gh', '$2y$10$..oSQUGvE65s/wVVCXeu/eJQyLo0x0p2I8L9i6lkuRZ7RwbrtoLuu', 'Admin', 'System Administrator', 'General Administrator of the system', 'System Administration', '2025-04-07 23:33:01', NULL, NULL, NULL, NULL, NULL, NULL, 0),
(4, 'Joyce Eli', 'joyce.eli@st.rmu.edu.gh', '$2y$10$T0/HLqJ2ML.UePqXq5sJheTKD.4FNgctjNMcSNtuDCoUC9xsQ0i9G', 'Trainer', 'Yoga', 'A bachelor degree holder for Yoga training, with a working experience of about 10years in.', 'In my opinion, Yoga employs progressive isometric contractions coupled with regulated respiratory patterns to improve proprioception, flexibility, and core stabilization.', '2025-04-12 03:45:49', NULL, NULL, NULL, NULL, NULL, NULL, 0),
(5, 'Lovelace John Kwaku Baidoo', 'lovelacejohnkwakubaidoo@gmail.com', '$2y$10$j1BvO4sJSZPSgR7gJz0keODRl1i05Fi6.hyAY/KRmaBpbc1XXk6JO', 'Member', 'Advanced', 'Excessive weight gain, Complete a Tough Mudder/Spartan Race, Recover from injury/surgery', 'Monday: Full-Body HIIT (30 mins) + 20-min steady-state cardio\r\n\r\nTuesday: Lower Body Strength (Squats, Lunges, Deadlifts)\r\n\r\nWednesday: Cycling or Swimming (45 mins)\r\n\r\nThursday: Upper Body Strength (Push-Ups, Rows, Shoulder Press)\r\n\r\nFriday: Core + Tabata (20 mins)\r\n\r\nSaturday: Active Recovery (Yoga/Walking)', '2025-04-14 01:43:58', NULL, NULL, NULL, NULL, NULL, NULL, 0),
(8, 'Harry Johnson', 'harry-johnson.agyemang@rmu.edu.gh', '$2y$10$shTVocCQ18j2mL0iPDmhD.hPXifySKlZ0.TEvg1nU9Q10jhOpgMGi', 'EquipmentManager', 'Strength Machines', 'Ten years experience as an equipment manager', 'Degree Bachelor holder of the prestigious University of Ghana', '2025-04-18 14:24:35', NULL, NULL, NULL, NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_log`
--

DROP TABLE IF EXISTS `user_activity_log`;
CREATE TABLE IF NOT EXISTS `user_activity_log` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `activity_type` varchar(50) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_activity_log_ibfk_1` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_activity_logs`
--

DROP TABLE IF EXISTS `user_activity_logs`;
CREATE TABLE IF NOT EXISTS `user_activity_logs` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `action` varchar(50) NOT NULL,
  `description` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_activity_user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_notifications`
--

DROP TABLE IF EXISTS `user_notifications`;
CREATE TABLE IF NOT EXISTS `user_notifications` (
  `user_id` int NOT NULL,
  `email_notifications` tinyint(1) DEFAULT '1',
  `maintenance_reminders` tinyint(1) DEFAULT '1',
  `inventory_alerts` tinyint(1) DEFAULT '1',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Dumping data for table `user_notifications`
--

INSERT INTO `user_notifications` (`user_id`, `email_notifications`, `maintenance_reminders`, `inventory_alerts`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2025-04-28 05:26:03', '2025-04-28 05:26:03');

-- --------------------------------------------------------

--
-- Table structure for table `user_preferences`
--

DROP TABLE IF EXISTS `user_preferences`;
CREATE TABLE IF NOT EXISTS `user_preferences` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `preference_key` varchar(50) NOT NULL,
  `preference_value` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_user_preferences_unique` (`user_id`,`preference_key`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_tokens`
--

DROP TABLE IF EXISTS `user_tokens`;
CREATE TABLE IF NOT EXISTS `user_tokens` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `token` varchar(100) NOT NULL,
  `expires` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `water_intake`
--

DROP TABLE IF EXISTS `water_intake`;
CREATE TABLE IF NOT EXISTS `water_intake` (
  `id` int NOT NULL AUTO_INCREMENT,
  `user_id` int NOT NULL,
  `intake_date` date NOT NULL,
  `amount` int NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_id` (`user_id`,`intake_date`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workouts`
--

DROP TABLE IF EXISTS `workouts`;
CREATE TABLE IF NOT EXISTS `workouts` (
  `id` int NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `trainer_id` int NOT NULL,
  `member_id` int DEFAULT NULL,
  `duration` int DEFAULT NULL,
  `difficulty` enum('beginner','intermediate','advanced') DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workout_completion`
--

DROP TABLE IF EXISTS `workout_completion`;
CREATE TABLE IF NOT EXISTS `workout_completion` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `workout_plan_id` int NOT NULL,
  `completion_date` date NOT NULL,
  `duration_minutes` int DEFAULT NULL,
  `difficulty_rating` int DEFAULT NULL,
  `notes` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `workout_plan_id` (`workout_plan_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workout_exercises`
--

DROP TABLE IF EXISTS `workout_exercises`;
CREATE TABLE IF NOT EXISTS `workout_exercises` (
  `id` int NOT NULL AUTO_INCREMENT,
  `workout_id` int NOT NULL,
  `exercise_id` int NOT NULL,
  `sets` int DEFAULT NULL,
  `reps` varchar(50) DEFAULT NULL,
  `rest_time` int DEFAULT NULL,
  `notes` text,
  `order_num` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workout_id` (`workout_id`),
  KEY `exercise_id` (`exercise_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `workout_plans`
--

DROP TABLE IF EXISTS `workout_plans`;
CREATE TABLE IF NOT EXISTS `workout_plans` (
  `id` int NOT NULL AUTO_INCREMENT,
  `member_id` int NOT NULL,
  `trainer_id` int NOT NULL,
  `title` varchar(100) NOT NULL,
  `description` text,
  `intensity_level` varchar(50) DEFAULT NULL,
  `status` enum('Pending','Accepted','Requested Modification') DEFAULT 'Pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `difficulty` varchar(20) DEFAULT 'Medium',
  `is_template` tinyint(1) DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `member_id` (`member_id`),
  KEY `trainer_id` (`trainer_id`)
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Structure for view `equipment_usage_summary`
--
DROP TABLE IF EXISTS `equipment_usage_summary`;

DROP VIEW IF EXISTS `equipment_usage_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `equipment_usage_summary`  AS SELECT `e`.`id` AS `id`, `e`.`name` AS `name`, `e`.`type` AS `type`, count(`eu`.`id`) AS `usage_count`, sum(`eu`.`duration`) AS `total_usage_minutes`, avg(`eu`.`duration`) AS `avg_usage_minutes`, max(`eu`.`usage_date`) AS `last_used_date` FROM (`equipment` `e` left join `equipment_usage` `eu` on((`e`.`id` = `eu`.`equipment_id`))) GROUP BY `e`.`id`, `e`.`name`, `e`.`type` ;

-- --------------------------------------------------------

--
-- Structure for view `equipment_with_maintenance`
--
DROP TABLE IF EXISTS `equipment_with_maintenance`;

DROP VIEW IF EXISTS `equipment_with_maintenance`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `equipment_with_maintenance`  AS SELECT `e`.`id` AS `id`, `e`.`name` AS `name`, `e`.`type` AS `type`, `e`.`status` AS `status`, `e`.`last_maintenance_date` AS `last_maintenance_date`, `e`.`created_at` AS `created_at`, `e`.`updated_by` AS `updated_by`, `e`.`location` AS `location`, (select count(0) from `maintenance` `m` where ((`m`.`equipment_id` = `e`.`id`) and (`m`.`status` = 'pending'))) AS `pending_maintenance`, (select count(0) from `maintenance` `m` where (`m`.`equipment_id` = `e`.`id`)) AS `total_maintenance`, (select sum(`m`.`cost`) from `maintenance` `m` where (`m`.`equipment_id` = `e`.`id`)) AS `total_maintenance_cost` FROM `equipment` AS `e` ;

-- --------------------------------------------------------

--
-- Structure for view `maintenance_summary`
--
DROP TABLE IF EXISTS `maintenance_summary`;

DROP VIEW IF EXISTS `maintenance_summary`;
CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `maintenance_summary`  AS SELECT `e`.`id` AS `equipment_id`, `e`.`name` AS `equipment_name`, `e`.`type` AS `equipment_type`, count(`m`.`id`) AS `maintenance_count`, sum((case when (`m`.`status` = 'pending') then 1 else 0 end)) AS `pending_count`, sum((case when (`m`.`status` = 'completed') then 1 else 0 end)) AS `completed_count`, sum(`m`.`cost`) AS `total_cost`, max(`m`.`maintenance_date`) AS `last_maintenance_date` FROM (`equipment` `e` left join `maintenance` `m` on((`e`.`id` = `m`.`equipment_id`))) GROUP BY `e`.`id`, `e`.`name`, `e`.`type` ;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `password_history`
--
ALTER TABLE `password_history`
  ADD CONSTRAINT `password_history_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `password_reset`
--
ALTER TABLE `password_reset`
  ADD CONSTRAINT `password_reset_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
