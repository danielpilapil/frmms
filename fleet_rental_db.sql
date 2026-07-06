-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Mar 18, 2026 at 03:10 PM
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
-- Database: `fleet_rental_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `admin_notification_reads`
--

CREATE TABLE `admin_notification_reads` (
  `admin_id` int(11) NOT NULL,
  `notification_id` int(11) NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin_notification_reads`
--

INSERT INTO `admin_notification_reads` (`admin_id`, `notification_id`, `read_at`) VALUES
(1, 1, '2026-03-18 21:19:57'),
(1, 2, '2026-03-18 21:19:57'),
(1, 3, '2026-03-18 21:19:57'),
(1, 4, '2026-03-18 21:19:57'),
(1, 5, '2026-03-18 21:19:57'),
(1, 6, '2026-03-18 21:19:57'),
(1, 7, '2026-03-18 21:19:57'),
(1, 8, '2026-03-18 21:19:57'),
(1, 9, '2026-03-18 21:19:57'),
(1, 10, '2026-03-18 21:19:57'),
(1, 11, '2026-03-18 21:19:57'),
(1, 12, '2026-03-18 21:19:57'),
(1, 13, '2026-03-18 21:19:57'),
(1, 14, '2026-03-18 21:19:57'),
(1, 15, '2026-03-18 21:19:57');

-- --------------------------------------------------------

--
-- Table structure for table `fuel_charge_rates`
--

CREATE TABLE `fuel_charge_rates` (
  `id` int(11) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `empty_rate` decimal(10,2) DEFAULT 2000.00,
  `quarter_rate` decimal(10,2) DEFAULT 1500.00,
  `half_rate` decimal(10,2) DEFAULT 1000.00,
  `three_quarter_rate` decimal(10,2) DEFAULT 500.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance`
--

CREATE TABLE `maintenance` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `reported_date` datetime DEFAULT NULL,
  `maintenance_category` enum('preventive','corrective','emergency','cleaning') NOT NULL,
  `cost` decimal(10,2) DEFAULT 0.00,
  `parts_used` text DEFAULT NULL,
  `labor_hours` decimal(5,2) DEFAULT 0.00,
  `schedule_date` date NOT NULL,
  `started_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `next_due_odometer` decimal(10,1) DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `description` text DEFAULT NULL,
  `priority_level` enum('low','medium','high','critical') DEFAULT 'medium',
  `source_type` enum('manual','rental_return','inspection','auto_km_rule','auto_time_rule') DEFAULT 'manual',
  `source_reference_id` int(11) DEFAULT NULL,
  `status` enum('reported','scheduled','approved','in_progress','completed','cancelled','failed_inspection') DEFAULT 'reported',
  `completed_date` date DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `estimated_cost` decimal(10,2) DEFAULT NULL,
  `service_center` varchar(100) DEFAULT NULL,
  `assigned_to` varchar(100) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `washing_id` int(11) DEFAULT NULL,
  `washing_cost` decimal(10,2) DEFAULT 0.00
) ;

--
-- Dumping data for table `maintenance`
--

INSERT INTO `maintenance` (`id`, `vehicle_id`, `reported_date`, `maintenance_category`, `cost`, `parts_used`, `labor_hours`, `schedule_date`, `started_at`, `completed_at`, `next_due_odometer`, `next_due_date`, `description`, `priority_level`, `source_type`, `source_reference_id`, `status`, `completed_date`, `notes`, `estimated_cost`, `service_center`, `assigned_to`, `created_at`, `washing_id`, `washing_cost`) VALUES
(2, 84, NULL, 'corrective', 140.00, NULL, 0.00, '2025-10-19', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-19', '0', 140.00, '0', NULL, '2025-10-19 05:29:41', 39, 0.00),
(3, 82, NULL, 'corrective', 180.00, NULL, 0.00, '2025-10-19', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-19', 'Auto-scheduled after dirty return', 180.00, NULL, NULL, '2025-10-19 05:38:56', 27, 0.00),
(4, 70, NULL, 'corrective', 500.00, NULL, 0.00, '2025-10-19', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-19', 'Auto-scheduled after dirty return', 500.00, NULL, NULL, '2025-10-19 08:14:10', 18, 0.00),
(5, 84, NULL, 'preventive', 200000.00, NULL, 0.00, '2025-10-19', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-19', 'Checklist: Engine, Tires, Transmission, Electrical', NULL, '0', NULL, '2025-10-19 08:15:15', 39, 140.00),
(6, 79, NULL, 'corrective', 480.00, NULL, 0.00, '2025-10-22', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-23', 'Auto-scheduled after dirty return', 480.00, NULL, NULL, '2025-10-22 07:24:02', 36, 0.00),
(7, 84, NULL, 'preventive', 15000.00, NULL, 0.00, '2025-10-23', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'cancelled', NULL, 'Checklist: Tires\n[Cancelled: Budget Cut]', NULL, '0', NULL, '2025-10-23 15:29:51', 39, 140.00),
(8, 82, NULL, 'preventive', NULL, NULL, 0.00, '2025-10-23', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-24', '', NULL, '0', NULL, '2025-10-23 16:32:50', 0, 0.00),
(9, 84, NULL, 'corrective', 140.00, NULL, 0.00, '2025-10-24', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'cancelled', NULL, 'Auto-scheduled after dirty return\n[Cancelled: Budget Cut]', 140.00, NULL, NULL, '2025-10-23 16:45:24', 39, 0.00),
(10, 84, NULL, 'corrective', 140.00, NULL, 0.00, '2025-10-23', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'cancelled', NULL, '0\n[Cancelled: Budget Cut]', 140.00, '0', NULL, '2025-10-23 16:45:24', 39, 0.00),
(11, 82, NULL, 'preventive', NULL, NULL, 0.00, '2025-10-23', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-24', '', NULL, '0', NULL, '2025-10-23 16:51:19', 0, 0.00),
(12, 67, NULL, 'preventive', NULL, NULL, 0.00, '2025-10-23', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2025-10-24', '', 0.00, '0', NULL, '2025-10-23 16:54:25', 15, 400.00),
(13, 80, NULL, 'corrective', 480.00, NULL, 0.00, '2025-11-05', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'completed', '2026-02-21', 'Auto-scheduled after dirty return', 480.00, NULL, NULL, '2025-11-05 12:00:04', 36, 0.00),
(14, 75, NULL, 'preventive', 0.00, NULL, 0.00, '2026-03-16', NULL, NULL, NULL, NULL, NULL, 'medium', 'manual', NULL, 'in_progress', NULL, 'wasd', 1222.00, 'wads', NULL, '2026-03-16 17:34:46', NULL, 0.00);

--
-- Triggers `maintenance`
--
DELIMITER $$
CREATE TRIGGER `trg_maintenance_insert_vehicle_status` AFTER INSERT ON `maintenance` FOR EACH ROW BEGIN
  IF NEW.status IN ('reported','scheduled','approved') THEN
    UPDATE vehicles
    SET current_status = 'scheduled_maintenance'
    WHERE id = NEW.vehicle_id
      AND current_status <> 'rented';
  ELSEIF NEW.status = 'in_progress' THEN
    UPDATE vehicles
    SET current_status = 'maintenance'
    WHERE id = NEW.vehicle_id;
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_maintenance_status_history` AFTER UPDATE ON `maintenance` FOR EACH ROW BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO maintenance_status_history (maintenance_id, old_status, new_status, changed_by, remarks)
    VALUES (NEW.id, OLD.status, NEW.status, NULL, NEW.notes);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_maintenance_status_log` AFTER UPDATE ON `maintenance` FOR EACH ROW BEGIN
        IF NEW.status <> OLD.status THEN
            INSERT INTO system_logs (actor_user_id, action, role, entity, details, created_at)
            VALUES (
                NULL,
                'update',
                'system',
                'maintenance',
                CONCAT('Maintenance status changed from ', OLD.status, ' to ', NEW.status, ' for vehicle ID ', NEW.vehicle_id),
                NOW()
            );
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_maintenance_update_vehicle_status` AFTER UPDATE ON `maintenance` FOR EACH ROW BEGIN
  IF NEW.status = 'in_progress' THEN
    UPDATE vehicles
    SET current_status = 'maintenance'
    WHERE id = NEW.vehicle_id;
  ELSEIF NEW.status = 'completed' THEN
    UPDATE vehicles
    SET current_status = 'available'
    WHERE id = NEW.vehicle_id
      AND NOT EXISTS (
        SELECT 1
        FROM maintenance m
        WHERE m.vehicle_id = NEW.vehicle_id
          AND m.status IN ('reported','scheduled','approved','in_progress')
          AND m.id <> NEW.id
      );
  ELSEIF NEW.status = 'scheduled' THEN
    UPDATE vehicles
    SET current_status = 'scheduled_maintenance'
    WHERE id = NEW.vehicle_id
      AND current_status <> 'rented';
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_issues`
--

CREATE TABLE `maintenance_issues` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `rental_id` int(11) DEFAULT NULL,
  `inspection_id` int(11) DEFAULT NULL,
  `issue_source` enum('manual','return_inspection','rental_return') DEFAULT 'manual',
  `issue_type` enum('mechanical','electrical','body_damage','cleaning','tire','engine','other') DEFAULT 'other',
  `severity` enum('low','medium','high','critical') DEFAULT 'medium',
  `issue_description` text NOT NULL,
  `reported_by` int(11) DEFAULT NULL,
  `reported_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('open','converted','resolved','cancelled') DEFAULT 'open'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_rules`
--

CREATE TABLE `maintenance_rules` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `km_interval` int(11) DEFAULT NULL,
  `months_interval` int(11) DEFAULT NULL,
  `last_ref_odometer` int(11) DEFAULT 0,
  `last_ref_date` date DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `maintenance_rules`
--

INSERT INTO `maintenance_rules` (`id`, `vehicle_id`, `km_interval`, `months_interval`, `last_ref_odometer`, `last_ref_date`, `next_due_date`, `notes`) VALUES
(1, 82, 5000, 3, 260, '2025-10-24', '2026-01-24', 'Auto-created from first preventive job'),
(2, 67, 5000, 3, 237, '2025-10-24', '2026-01-24', 'Auto-created from first preventive job');

-- --------------------------------------------------------

--
-- Table structure for table `maintenance_status_history`
--

CREATE TABLE `maintenance_status_history` (
  `id` int(11) NOT NULL,
  `maintenance_id` int(11) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `is_read` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `vehicle_id`, `message`, `created_at`, `is_read`) VALUES
(1, 1, 67, '🎉 Welcome to FleetGo! Please complete your profile to start renting vehicles.', '2026-02-21 16:53:10', 1),
(2, 2, 67, '🎉 Welcome to FleetGo! Please complete your profile to start renting vehicles.', '2026-02-21 16:53:57', 1),
(3, 3, 67, '🎉 Welcome to FleetGo! Please complete your profile to start renting vehicles.', '2026-02-21 17:40:34', 1),
(4, 3, 80, '📝 Your booking for <b>Honda Mobilio</b> has been submitted and is pending admin approval.', '2026-02-22 14:29:05', 1),
(5, 3, 81, '📝 Your booking for <b>Suzuki Raider 150 FI</b> has been submitted and is pending admin approval.', '2026-02-22 14:47:40', 1),
(6, 3, 81, 'Booking for <b>Suzuki Raider 150 FI</b> updated to <b>ongoing</b>.', '2026-02-22 15:01:20', 1),
(7, 1, 80, '🚨 Maintenance OVERDUE for <b>Honda Mobilio (CDO-1704)</b> — exceeded 5000 km interval.', '2026-02-22 15:04:29', 1),
(8, 3, 80, 'Booking for <b>Honda Mobilio</b> updated to <b>ongoing</b>.', '2026-02-22 15:17:46', 1),
(9, 3, 67, '📝 Your booking for <b>Nissan Almera 1.5 EL</b> has been submitted and is pending admin approval.', '2026-02-22 15:27:42', 1),
(10, 3, 67, 'Booking for <b>Nissan Almera 1.5 EL</b> updated to <b>ongoing</b>.', '2026-02-22 15:28:35', 1),
(11, 3, 79, '📝 Your booking for <b>Mitsubishi Xpander GLS</b> has been submitted and is pending admin approval.', '2026-02-22 15:32:08', 1),
(12, 3, 79, 'Booking for <b>Mitsubishi Xpander GLS</b> updated to <b>ongoing</b>.', '2026-02-22 15:32:40', 1),
(13, 1, 80, '🚨 Maintenance OVERDUE for <b>Honda Mobilio (CDO-1704)</b> — exceeded 5000 km interval.', '2026-03-16 02:15:42', 1),
(14, 3, 78, '📝 Your booking for <b>Other Foton Transvan HR</b> has been submitted and is pending admin approval.', '2026-03-18 02:40:34', 1),
(15, 3, 78, 'Booking for <b>Other Foton Transvan HR</b> updated to <b>ongoing</b>.', '2026-03-18 02:41:41', 1),
(16, 3, 68, '📝 Your booking was received and is pending admin approval.', '2026-03-18 22:02:51', 0),
(17, 3, 69, '✅ Your profile is approved. You can now rent vehicles.', '2026-03-18 22:02:51', 0),
(18, 3, 70, '🎉 Promo available: Book 3+ days to get a discount.', '2026-03-18 22:02:51', 0);

-- --------------------------------------------------------

--
-- Table structure for table `promotions`
--

CREATE TABLE `promotions` (
  `id` int(11) NOT NULL,
  `promo_name` varchar(100) NOT NULL,
  `promo_type` enum('First-Time','Loyalty','Long-Term','Seasonal') NOT NULL,
  `discount_percent` decimal(5,2) NOT NULL,
  `min_days` int(11) DEFAULT 0,
  `min_bookings` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `rentals`
--

CREATE TABLE `rentals` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `start_odometer` decimal(10,1) DEFAULT NULL,
  `return_odometer` decimal(10,1) DEFAULT NULL,
  `distance_traveled` decimal(10,1) DEFAULT NULL,
  `customer_id` int(11) NOT NULL,
  `start_date` date NOT NULL,
  `start_time` time DEFAULT NULL,
  `end_date` date NOT NULL,
  `end_time` time DEFAULT NULL,
  `penalty_per_hour` decimal(8,2) DEFAULT 50.00,
  `return_condition` enum('Excellent','Good','Fair','Poor') DEFAULT 'Good',
  `daily_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `applied_rate` decimal(10,2) DEFAULT 0.00,
  `rate_type` enum('CDO','Outside-CDO') DEFAULT 'CDO',
  `promo_applied` varchar(100) DEFAULT NULL,
  `promo_discount` decimal(10,2) DEFAULT 0.00,
  `total_cost` decimal(12,2) DEFAULT 0.00,
  `downpayment` decimal(10,2) DEFAULT 0.00,
  `balance_due` decimal(10,2) DEFAULT 0.00,
  `fuel_penalty` decimal(10,2) DEFAULT 0.00,
  `status` enum('pending','reserved','ongoing','completed','cancelled','conflict_pending') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `total_days` int(11) GENERATED ALWAYS AS (to_days(`end_date`) - to_days(`start_date`) + 1) VIRTUAL,
  `base_amount` decimal(10,2) GENERATED ALWAYS AS ((to_days(`end_date`) - to_days(`start_date`) + 1) * `daily_rate`) VIRTUAL,
  `total_hours` int(11) GENERATED ALWAYS AS (timestampdiff(HOUR,concat(`start_date`,' ',coalesce(`start_time`,'00:00:00')),concat(`end_date`,' ',coalesce(`end_time`,'00:00:00')))) VIRTUAL,
  `washing_id` int(11) DEFAULT NULL,
  `washing_cost` decimal(10,2) DEFAULT 0.00,
  `rental_year` int(11) GENERATED ALWAYS AS (year(`start_date`)) STORED,
  `rental_month_num` int(11) GENERATED ALWAYS AS (month(`start_date`)) STORED
) ;

--
-- Dumping data for table `rentals`
--

INSERT INTO `rentals` (`id`, `vehicle_id`, `start_odometer`, `return_odometer`, `distance_traveled`, `customer_id`, `start_date`, `start_time`, `end_date`, `end_time`, `penalty_per_hour`, `return_condition`, `daily_rate`, `applied_rate`, `rate_type`, `promo_applied`, `promo_discount`, `total_cost`, `downpayment`, `balance_due`, `fuel_penalty`, `status`, `created_at`, `washing_id`, `washing_cost`) VALUES
(1, 80, 0.0, NULL, NULL, 3, '2026-02-22', '08:00:00', '2026-03-03', '18:00:00', 50.00, 'Good', 2600.00, 2470.00, 'CDO', 'Welcome Discount', 1222.00, 23218.00, 11609.00, 11609.00, 0.00, 'ongoing', '2026-02-22 06:29:05', NULL, 0.00),
(2, 81, NULL, NULL, NULL, 3, '2026-02-22', '08:00:00', '2026-03-13', '18:00:00', 50.00, 'Good', 850.00, 807.50, 'CDO', 'Welcome Discount', 824.50, 15665.50, 7832.75, 7832.75, 0.00, 'ongoing', '2026-02-22 06:47:40', NULL, 0.00),
(3, 67, 0.0, NULL, NULL, 3, '2026-02-22', '08:00:00', '2026-02-25', '18:00:00', 50.00, 'Good', 1150.00, 1092.50, 'CDO', 'Welcome Discount', 195.50, 3714.50, 1857.25, 1857.25, 0.00, 'ongoing', '2026-02-22 07:27:42', NULL, 0.00),
(4, 79, 764.0, NULL, NULL, 3, '2026-02-22', '08:00:00', '2026-03-12', '18:00:00', 50.00, 'Good', 2700.00, 2565.00, 'CDO', 'Welcome Discount', 2484.00, 47196.00, 23598.00, 24278.00, 500.00, 'ongoing', '2026-02-22 07:32:08', 36, 480.00),
(5, 78, 2600.0, NULL, NULL, 3, '2026-03-17', '08:00:00', '2026-03-19', '18:00:00', 50.00, 'Good', 2800.00, 2660.00, 'CDO', 'Welcome Discount', 336.00, 6384.00, 3192.00, 3192.00, 0.00, 'ongoing', '2026-03-17 18:40:34', NULL, 0.00),
(6, 68, NULL, NULL, NULL, 3, '2026-03-18', NULL, '2026-03-20', NULL, 50.00, 'Good', 1050.00, 1050.00, 'CDO', NULL, 0.00, 3150.00, 1000.00, 2150.00, 0.00, 'completed', '2026-03-18 14:02:43', NULL, 0.00),
(7, 69, NULL, NULL, NULL, 2, '2026-03-18', NULL, '2026-03-19', NULL, 50.00, 'Good', 3800.00, 3800.00, 'CDO', NULL, 0.00, 7600.00, 2000.00, 5600.00, 0.00, 'completed', '2026-03-18 14:02:43', NULL, 0.00),
(8, 69, NULL, NULL, NULL, 3, '2026-03-08', NULL, '2026-03-10', NULL, 50.00, 'Good', 3800.00, 3800.00, 'CDO', NULL, 0.00, 11400.00, 3000.00, 8400.00, 0.00, 'completed', '2026-03-18 14:02:43', NULL, 0.00),
(9, 70, NULL, NULL, NULL, 1, '2026-03-11', NULL, '2026-03-12', NULL, 50.00, 'Good', 4600.00, 4600.00, 'CDO', NULL, 0.00, 9200.00, 4000.00, 5200.00, 0.00, 'completed', '2026-03-18 14:02:43', NULL, 0.00);

--
-- Triggers `rentals`
--
DELIMITER $$
CREATE TRIGGER `trg_auto_odometer_update` BEFORE UPDATE ON `rentals` FOR EACH ROW BEGIN
    IF NEW.return_odometer IS NOT NULL
       AND OLD.return_odometer IS NULL THEN

        SET NEW.distance_traveled =
            NEW.return_odometer - NEW.start_odometer;

        UPDATE vehicles
        SET current_odometer = NEW.return_odometer
        WHERE vehicles.id = NEW.vehicle_id;

    END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_rentals_status_history_au` AFTER UPDATE ON `rentals` FOR EACH ROW BEGIN
  IF NEW.status <> OLD.status THEN
    INSERT INTO rental_status_history (rental_id, old_status, new_status, changed_by)
    VALUES (OLD.id, OLD.status, NEW.status, NULL);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_set_start_odometer` BEFORE UPDATE ON `rentals` FOR EACH ROW BEGIN
    IF NEW.status = 'ongoing'
       AND OLD.status <> 'ongoing'
       AND NEW.start_odometer IS NULL THEN

        SET NEW.start_odometer = (
            SELECT current_odometer
            FROM vehicles
            WHERE vehicles.id = NEW.vehicle_id
            LIMIT 1
        );

    END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `rental_payments`
--

CREATE TABLE `rental_payments` (
  `payment_id` int(11) NOT NULL,
  `rental_id` int(11) NOT NULL,
  `payment_type` enum('DOWNPAYMENT','FINAL') NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('POSTED','VOIDED') DEFAULT 'POSTED',
  `paid_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rental_payments`
--

INSERT INTO `rental_payments` (`payment_id`, `rental_id`, `payment_type`, `amount`, `status`, `paid_at`) VALUES
(1, 1, 'DOWNPAYMENT', 1000.00, 'POSTED', '2026-02-22 14:55:52'),
(2, 1, 'DOWNPAYMENT', 1000.00, 'POSTED', '2026-02-22 14:55:59'),
(3, 1, 'DOWNPAYMENT', 11609.00, 'POSTED', '2026-02-22 14:56:07'),
(4, 2, 'DOWNPAYMENT', 1000.00, 'POSTED', '2026-02-22 15:01:03'),
(5, 2, 'DOWNPAYMENT', 7832.00, 'POSTED', '2026-02-22 15:01:16'),
(6, 3, 'DOWNPAYMENT', 1857.00, 'POSTED', '2026-02-22 15:28:23'),
(7, 3, 'DOWNPAYMENT', 0.25, 'POSTED', '2026-02-22 15:28:33'),
(8, 4, 'DOWNPAYMENT', 23598.00, 'POSTED', '2026-02-22 15:32:38'),
(9, 5, 'DOWNPAYMENT', 3192.00, 'POSTED', '2026-03-18 02:41:07'),
(10, 5, 'DOWNPAYMENT', 3192.00, 'POSTED', '2026-03-18 02:41:14'),
(11, 5, 'DOWNPAYMENT', 3192.00, 'POSTED', '2026-03-18 02:41:19'),
(12, 5, 'DOWNPAYMENT', 3193.00, 'POSTED', '2026-03-18 02:41:26'),
(13, 5, 'DOWNPAYMENT', 3192.00, 'POSTED', '2026-03-18 02:41:31');

-- --------------------------------------------------------

--
-- Table structure for table `rental_returns`
--

CREATE TABLE `rental_returns` (
  `id` int(11) NOT NULL,
  `rental_id` int(11) NOT NULL,
  `actual_return_date` date NOT NULL,
  `actual_return_time` time DEFAULT NULL,
  `return_condition` enum('Excellent','Good','Fair','Poor') DEFAULT 'Good',
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `final_cost` decimal(12,2) DEFAULT 0.00,
  `odometer_return` int(11) NOT NULL,
  `fuel_level` enum('empty','1/4','1/2','3/4','full') DEFAULT 'full',
  `issues` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rental_returns`
--

INSERT INTO `rental_returns` (`id`, `rental_id`, `actual_return_date`, `actual_return_time`, `return_condition`, `penalty_amount`, `final_cost`, `odometer_return`, `fuel_level`, `issues`, `created_at`) VALUES
(1, 4, '2026-03-12', '18:00:00', 'Good', 0.00, 47876.00, 1234, '1/4', '', '2026-02-22 07:33:22');

--
-- Triggers `rental_returns`
--
DELIMITER $$
CREATE TRIGGER `trg_returns_after_ins` AFTER INSERT ON `rental_returns` FOR EACH ROW BEGIN
  DECLARE start_odo INT DEFAULT NULL;
  DECLARE v_vehicle_id INT;
  
  -- Get vehicle ID
  SELECT vehicle_id INTO v_vehicle_id FROM rentals WHERE id = NEW.rental_id;
  
  -- Get current odometer
  SELECT odometer INTO start_odo FROM vehicles WHERE id = v_vehicle_id;
  
  -- Validate odometer
  IF start_odo IS NOT NULL AND NEW.odometer_return < start_odo THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Odometer return cannot be less than current vehicle odometer';
  END IF;
  
  -- Update vehicle odometer and status
  UPDATE vehicles 
  SET odometer = GREATEST(odometer, NEW.odometer_return),
      current_status = CASE
        WHEN EXISTS (SELECT 1 FROM maintenance m
                    WHERE m.vehicle_id = v_vehicle_id
                      AND m.status IN ('scheduled','in_progress')
                      AND m.schedule_date = CURDATE())
        THEN 'maintenance'
        ELSE 'available'
      END
  WHERE id = v_vehicle_id;
  
  -- Update rental status to completed
  UPDATE rentals 
  SET status = 'completed',
      balance_due = NEW.final_cost
  WHERE id = NEW.rental_id AND status <> 'completed';
  
  -- Auto-schedule maintenance if needed
  IF EXISTS (
    SELECT 1 FROM maintenance_rules mr
    WHERE mr.vehicle_id = v_vehicle_id
      AND (mr.km_interval IS NOT NULL)
      AND (NEW.odometer_return - COALESCE(mr.last_ref_odometer,0) >= mr.km_interval)
  ) THEN
    INSERT INTO maintenance (vehicle_id, maintenance_type, type, schedule_date, status, notes)
    SELECT v_vehicle_id, 'Preventive', 'Preventive Service', DATE_ADD(CURDATE(), INTERVAL 1 DAY), 'scheduled',
           CONCAT('Auto-scheduled after ', mr.km_interval, ' km interval reached')
    FROM maintenance_rules mr
    WHERE mr.vehicle_id = v_vehicle_id
      AND (mr.km_interval IS NOT NULL)
      AND (NEW.odometer_return - COALESCE(mr.last_ref_odometer,0) >= mr.km_interval);
    
    -- Update maintenance rules
    UPDATE maintenance_rules mr
    SET mr.last_ref_odometer = NEW.odometer_return,
        mr.last_ref_date = CURDATE()
    WHERE mr.vehicle_id = v_vehicle_id;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `rental_status_history`
--

CREATE TABLE `rental_status_history` (
  `id` int(11) NOT NULL,
  `rental_id` int(11) NOT NULL,
  `old_status` enum('reserved','ongoing','completed','cancelled') DEFAULT NULL,
  `new_status` enum('reserved','ongoing','completed','cancelled') NOT NULL,
  `changed_by` int(11) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `rental_status_history`
--

INSERT INTO `rental_status_history` (`id`, `rental_id`, `old_status`, `new_status`, `changed_by`, `changed_at`) VALUES
(1, 2, '', 'ongoing', NULL, '2026-02-22 07:01:20'),
(2, 1, '', 'ongoing', NULL, '2026-02-22 07:17:46'),
(3, 3, '', 'ongoing', NULL, '2026-02-22 07:28:35'),
(4, 4, '', 'ongoing', NULL, '2026-02-22 07:32:40'),
(5, 4, 'ongoing', 'completed', NULL, '2026-02-22 07:33:22'),
(6, 4, 'completed', 'ongoing', NULL, '2026-02-22 07:33:22'),
(7, 5, '', 'ongoing', NULL, '2026-03-17 18:41:41');

-- --------------------------------------------------------

--
-- Table structure for table `return_inspections`
--

CREATE TABLE `return_inspections` (
  `id` int(11) NOT NULL,
  `rental_id` int(11) NOT NULL,
  `penalty_amount` decimal(10,2) DEFAULT 0.00,
  `final_cost` decimal(12,2) DEFAULT 0.00,
  `odometer_return` int(11) DEFAULT NULL,
  `fuel_level` enum('full','3/4','half','1/4','empty') DEFAULT 'full',
  `cleanliness` enum('clean','dirty','very_dirty') DEFAULT 'clean',
  `damage_report` text DEFAULT NULL,
  `carwash_fee` decimal(10,2) DEFAULT 0.00,
  `fuel_charge` decimal(10,2) DEFAULT 0.00,
  `damage_fee` decimal(10,2) DEFAULT 0.00,
  `additional_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `return_inspections`
--

INSERT INTO `return_inspections` (`id`, `rental_id`, `penalty_amount`, `final_cost`, `odometer_return`, `fuel_level`, `cleanliness`, `damage_report`, `carwash_fee`, `fuel_charge`, `damage_fee`, `additional_notes`, `created_at`) VALUES
(1, 4, 0.00, 47876.00, NULL, '1/4', 'dirty', '', 0.00, 0.00, 200.00, '', '2026-02-22 07:33:22');

--
-- Triggers `return_inspections`
--
DELIMITER $$
CREATE TRIGGER `trg_auto_carwash_after_inspection` AFTER INSERT ON `return_inspections` FOR EACH ROW BEGIN
  DECLARE v_vehicle_id INT;
  DECLARE v_vehicle_type VARCHAR(50);
  DECLARE v_washing_id INT DEFAULT NULL;
  DECLARE v_washing_cost DECIMAL(10,2) DEFAULT 0;
  DECLARE v_cost DECIMAL(10,2) DEFAULT 0;
  
  SELECT vehicle_id INTO v_vehicle_id FROM rentals WHERE id = NEW.rental_id;
  
  -- If car is very dirty → auto-schedule a carwash maintenance
  IF NEW.cleanliness = 'very_dirty' THEN
    -- Get vehicle type
    SELECT vehicle_type INTO v_vehicle_type FROM vehicles WHERE id = v_vehicle_id;
    
    -- Get the highest washing rate for this vehicle type
    SELECT id, washing_rate INTO v_washing_id, v_washing_cost
    FROM washing_types 
    WHERE vehicle_type = v_vehicle_type 
    ORDER BY washing_rate DESC 
    LIMIT 1;
    
    -- Set cost to washing cost, but set washing_cost to 0 to avoid double counting
    SET v_cost = v_washing_cost;
    SET v_washing_cost = 0;
    
    -- Insert maintenance record with proper cost handling
    INSERT INTO maintenance (vehicle_id, maintenance_type, type, schedule_date, status, notes, washing_id, washing_cost, cost, estimated_cost)
    VALUES (v_vehicle_id, 'Carwash', 'Carwash', CURDATE(), 'scheduled', 'Auto-scheduled after dirty return', v_washing_id, v_washing_cost, v_cost, v_cost);
  END IF;
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_auto_fuel_charge` AFTER INSERT ON `return_inspections` FOR EACH ROW BEGIN
        DECLARE fuel_charge_amount DECIMAL(10,2) DEFAULT 0;
        DECLARE vehicle_type_val VARCHAR(50);
        
        -- Get vehicle type from the rental
        SELECT v.vehicle_type INTO vehicle_type_val
        FROM rentals r
        JOIN vehicles v ON v.id = r.vehicle_id
        WHERE r.id = NEW.rental_id;
        
        -- Calculate fuel charge based on fuel level and vehicle type
        IF NEW.fuel_level != 'Full' THEN
            CASE vehicle_type_val
                WHEN 'Sedan' THEN SET fuel_charge_amount = 500;
                WHEN 'SUV' THEN SET fuel_charge_amount = 800;
                WHEN 'Van' THEN SET fuel_charge_amount = 1000;
                WHEN 'Truck' THEN SET fuel_charge_amount = 1200;
                ELSE SET fuel_charge_amount = 500;
            END CASE;
            
            -- Update the rental record with fuel charge
            UPDATE rentals 
            SET fuel_penalty = fuel_charge_amount,
                balance_due = COALESCE(balance_due, 0) + fuel_charge_amount
            WHERE id = NEW.rental_id;
        END IF;
    END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_create_issue_from_return_inspection` AFTER INSERT ON `return_inspections` FOR EACH ROW BEGIN
  DECLARE v_vehicle_id INT;

  SELECT vehicle_id INTO v_vehicle_id
  FROM rentals
  WHERE id = NEW.rental_id;

  IF NEW.damage_report IS NOT NULL AND TRIM(NEW.damage_report) <> '' THEN
    INSERT INTO maintenance_issues (
      vehicle_id,
      rental_id,
      inspection_id,
      issue_source,
      issue_type,
      severity,
      issue_description,
      status
    )
    VALUES (
      v_vehicle_id,
      NEW.rental_id,
      NEW.id,
      'return_inspection',
      'body_damage',
      'high',
      NEW.damage_report,
      'open'
    );

    UPDATE vehicles
    SET current_status = 'inspection'
    WHERE id = v_vehicle_id;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `system_logs`
--

CREATE TABLE `system_logs` (
  `id` int(11) NOT NULL,
  `actor_user_id` int(11) DEFAULT NULL,
  `action` enum('create','update','delete','login','logout') NOT NULL,
  `role` varchar(50) DEFAULT NULL,
  `log_time` datetime DEFAULT current_timestamp(),
  `entity` varchar(80) DEFAULT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_logs`
--

INSERT INTO `system_logs` (`id`, `actor_user_id`, `action`, `role`, `log_time`, `entity`, `entity_id`, `details`, `created_at`) VALUES
(1, 1, '', 'user', '2026-02-21 16:53:10', 'users', NULL, 'New user registered: Christian Danielle Pilapil', '2026-02-21 08:53:10'),
(2, 1, 'create', 'user', '2026-02-21 16:53:10', 'users', NULL, 'New user \'Christian Danielle Pilapil\' registered with verification flow.', '2026-02-21 08:53:10'),
(3, 2, '', 'user', '2026-02-21 16:53:57', 'users', NULL, 'New user registered: ampol the nigger', '2026-02-21 08:53:57'),
(4, 2, 'create', 'user', '2026-02-21 16:53:57', 'users', NULL, 'New user \'ampol the nigger\' registered with verification flow.', '2026-02-21 08:53:57'),
(5, 3, '', 'user', '2026-02-21 17:40:34', 'users', NULL, 'New user registered: gerrard way', '2026-02-21 09:40:34'),
(6, 3, 'create', 'user', '2026-02-21 17:40:34', 'users', NULL, 'New user \'gerrard way\' registered with verification flow.', '2026-02-21 09:40:34'),
(7, 1, 'login', 'admin', '2026-02-22 01:04:59', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-02-21 17:04:59'),
(8, 3, 'login', 'user', '2026-02-22 01:12:35', 'users', NULL, 'User gerrard way logged in.', '2026-02-21 17:12:35'),
(9, 1, 'login', 'admin', '2026-02-22 14:28:03', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-02-22 06:28:03'),
(10, 1, 'login', 'admin', '2026-02-22 14:28:03', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-02-22 06:28:03'),
(11, 3, 'login', 'user', '2026-02-22 14:28:58', 'users', NULL, 'User gerrard way logged in.', '2026-02-22 06:28:58'),
(12, 3, 'login', 'user', '2026-02-22 14:28:58', 'users', NULL, 'User gerrard way logged in.', '2026-02-22 06:28:58'),
(13, 1, 'login', 'admin', '2026-03-16 02:14:07', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-15 18:14:07'),
(14, 1, 'login', 'admin', '2026-03-16 02:14:07', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-15 18:14:07'),
(15, 1, 'login', 'admin', '2026-03-17 01:04:00', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-16 17:04:00'),
(16, 1, 'login', 'admin', '2026-03-17 01:04:00', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-16 17:04:00'),
(17, 1, 'login', 'admin', '2026-03-17 01:21:16', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-16 17:21:16'),
(18, 1, 'login', 'admin', '2026-03-17 01:21:16', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-16 17:21:16'),
(19, NULL, 'update', 'system', '2026-03-17 01:34:51', 'maintenance', NULL, 'Maintenance status changed from scheduled to in_progress for vehicle ID 75', '2026-03-16 17:34:51'),
(20, 1, 'login', 'admin', '2026-03-18 01:27:54', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-17 17:27:54'),
(21, 1, 'login', 'admin', '2026-03-18 01:27:54', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-17 17:27:54'),
(22, 3, 'login', 'user', '2026-03-18 02:40:18', 'users', NULL, 'User gerrard way logged in.', '2026-03-17 18:40:18'),
(23, 3, 'login', 'user', '2026-03-18 02:40:18', 'users', NULL, 'User gerrard way logged in.', '2026-03-17 18:40:18'),
(24, 1, 'login', 'admin', '2026-03-18 21:10:48', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-18 13:10:48'),
(25, 1, 'login', 'admin', '2026-03-18 21:10:48', 'users', NULL, 'User Christian Danielle Pilapil logged in.', '2026-03-18 13:10:48'),
(26, 3, 'login', 'user', '2026-03-18 21:17:03', 'users', NULL, 'User gerrard way logged in.', '2026-03-18 13:17:03'),
(27, 3, 'login', 'user', '2026-03-18 21:17:03', 'users', NULL, 'User gerrard way logged in.', '2026-03-18 13:17:03');

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  `key` varchar(100) NOT NULL,
  `value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `system_settings`
--

INSERT INTO `system_settings` (`key`, `value`, `updated_at`) VALUES
('site_name', 'FleetGo', '2026-03-18 13:31:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `last_login` datetime DEFAULT NULL,
  `role` enum('admin','staff','user') DEFAULT 'user',
  `status` enum('active','inactive','blacklisted') DEFAULT 'active',
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `license_photo` varchar(255) DEFAULT NULL,
  `valid_id_photo` varchar(255) DEFAULT NULL,
  `profile_status` enum('incomplete','pending_approval','approved','rejected') DEFAULT 'incomplete',
  `photo` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `loyalty_points` int(11) DEFAULT 0,
  `verification_status` varchar(20) NOT NULL DEFAULT 'unverified',
  `rejection_reason` text DEFAULT NULL,
  `submitted_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `driver_license_no` varchar(80) DEFAULT NULL,
  `driver_license_expiry` date DEFAULT NULL,
  `id_type` varchar(40) DEFAULT NULL,
  `id_number` varchar(80) DEFAULT NULL,
  `emergency_name` varchar(120) DEFAULT NULL,
  `emergency_phone` varchar(40) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `blacklist_reason` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `full_name`, `password`, `last_login`, `role`, `status`, `email`, `phone`, `city`, `contact_no`, `address`, `profile_photo`, `license_photo`, `valid_id_photo`, `profile_status`, `photo`, `created_at`, `updated_at`, `loyalty_points`, `verification_status`, `rejection_reason`, `submitted_at`, `verified_at`, `driver_license_no`, `driver_license_expiry`, `id_type`, `id_number`, `emergency_name`, `emergency_phone`, `notes`, `blacklist_reason`) VALUES
(1, 'Christian Danielle Pilapil', '$2y$10$8v2Eqmy79V6PoXfZ8w.X8uON4kAw4JLIgsRa1/jXsAkKfw3HkJyCu', '2026-03-18 21:10:48', 'admin', 'active', 'xtiandanielpilapil@gmail.com', NULL, NULL, '09536785093', NULL, NULL, NULL, NULL, 'incomplete', NULL, '2026-02-21 16:53:10', '2026-03-18 21:10:48', 0, 'unverified', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 'ampol the nigger', '$2y$10$C49Dqad3u9W1xEMWsYE0V.vqgnZ1ABC/APxUbW5/0a.1aXvdbgHV6', NULL, 'user', 'blacklisted', 'ampol@gmail.com', NULL, 'Cagayan de Oro City', '09768232828', 'Kauswagan Pasil Kauswagan CDOC', 'uploads/profiles/profile_photo_2_1771664168.png', NULL, NULL, 'incomplete', NULL, '2026-02-21 16:53:57', '2026-02-21 17:40:16', 0, 'unverified', NULL, NULL, NULL, '123 321 123 231', '2027-02-03', 'National ID', '123 321 232 233', 'Christian Danielle Pilapil', '0978 123 1722', '', 'wala lang'),
(3, 'gerrard way', '$2y$10$U3NAdwgYUXgtS7pDRXaQtun7Rsimm3AbC7RYdi9i2iYxhw/zO0eDC', '2026-03-18 21:17:03', 'user', 'active', 'mcr@gmail.com', NULL, 'CDO', '09765678652', 'Kauswagan CDO', 'uploads/profiles/profile_photo_3_1771667181.jpg', NULL, NULL, 'approved', NULL, '2026-02-21 17:40:34', '2026-03-18 21:17:03', 0, 'verified', NULL, '2026-02-21 20:20:02', '2026-02-22 01:12:44', '123 321 232 223', '2029-02-03', 'National ID', '123 321 2321', 'Christian Danielle Pilapil', '0965 651 2372', '', NULL);

--
-- Triggers `users`
--
DELIMITER $$
CREATE TRIGGER `after_user_insert` AFTER INSERT ON `users` FOR EACH ROW BEGIN
  INSERT INTO system_logs (actor_user_id, action, role, entity, details)
  VALUES (
    NEW.id,
    'register',
    NEW.role,
    'users',
    CONCAT('New user registered: ', NEW.full_name)
  );
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_users_login_log` AFTER UPDATE ON `users` FOR EACH ROW BEGIN
  IF NEW.last_login <> OLD.last_login THEN
    INSERT INTO system_logs (actor_user_id, action, role, entity, details)
    VALUES (
      NEW.id,
      'login',
      NEW.role,
      'users',
      CONCAT('User ', NEW.full_name, ' logged in.')
    );
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `user_documents`
--

CREATE TABLE `user_documents` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_documents`
--

INSERT INTO `user_documents` (`id`, `user_id`, `doc_type`, `file_path`, `uploaded_at`) VALUES
(5, 2, 'License Front', 'uploads/profiles/license_photo_front_2_1771664210.png', '2026-02-21 16:56:50'),
(6, 2, 'License Back', 'uploads/profiles/license_photo_back_2_1771664210.png', '2026-02-21 16:56:50'),
(7, 2, 'ID Front', 'uploads/profiles/valid_id_photo_front_2_1771664210.png', '2026-02-21 16:56:50'),
(8, 2, 'ID Back', 'uploads/profiles/valid_id_photo_back_2_1771664210.png', '2026-02-21 16:56:50'),
(9, 2, 'License Front', 'uploads/profiles/license_photo_front_2_1771664298.png', '2026-02-21 16:58:18'),
(10, 2, 'ID Front', 'uploads/profiles/valid_id_photo_front_2_1771664298.png', '2026-02-21 16:58:18'),
(11, 3, 'License Front', 'uploads/profiles/license_photo_front_3_1771667181.jpg', '2026-02-21 17:46:21'),
(12, 3, 'License Back', 'uploads/profiles/license_photo_back_3_1771667181.jpg', '2026-02-21 17:46:21'),
(13, 3, 'ID Front', 'uploads/profiles/valid_id_photo_front_3_1771667181.jpg', '2026-02-21 17:46:21'),
(14, 3, 'ID Back', 'uploads/profiles/valid_id_photo_back_3_1771667181.jpg', '2026-02-21 17:46:21');

-- --------------------------------------------------------

--
-- Table structure for table `user_favorites`
--

CREATE TABLE `user_favorites` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_favorites`
--

INSERT INTO `user_favorites` (`id`, `user_id`, `vehicle_id`, `created_at`) VALUES
(1, 3, 68, '2026-03-18 14:03:21'),
(2, 3, 69, '2026-03-18 14:03:21');

-- --------------------------------------------------------

--
-- Table structure for table `user_recent_views`
--

CREATE TABLE `user_recent_views` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `vehicle_id` int(11) DEFAULT NULL,
  `viewed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_recent_views`
--

INSERT INTO `user_recent_views` (`id`, `user_id`, `vehicle_id`, `viewed_at`) VALUES
(1, 3, 68, '2026-03-18 14:03:26'),
(2, 3, 70, '2026-03-18 14:03:26'),
(3, 1, 80, '2026-03-18 14:09:56');

-- --------------------------------------------------------

--
-- Stand-in structure for view `user_rental_summary`
-- (See below for the actual view)
--
CREATE TABLE `user_rental_summary` (
`customer_id` int(11)
,`total_rentals` bigint(21)
,`total_spent` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(11) NOT NULL,
  `plate_no` varchar(20) NOT NULL,
  `maker` varchar(50) DEFAULT NULL,
  `model` varchar(50) DEFAULT NULL,
  `ownership_type` enum('Personal','Company') DEFAULT 'Personal',
  `chassis_number` varchar(50) DEFAULT NULL,
  `engine_number` varchar(50) DEFAULT NULL,
  `make_model` varchar(100) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `category` varchar(50) DEFAULT NULL,
  `seats` int(11) DEFAULT NULL,
  `transmission` enum('MT','AT','CVT') DEFAULT 'MT',
  `comfort_level` varchar(20) DEFAULT 'Standard',
  `fuel_type` varchar(20) DEFAULT NULL,
  `year` year(4) DEFAULT NULL,
  `odometer` decimal(10,1) DEFAULT 0.0,
  `daily_rate` decimal(10,2) DEFAULT 0.00,
  `daily_rate_cdo` decimal(10,2) DEFAULT 0.00,
  `daily_rate_outside_cdo` decimal(10,2) DEFAULT 0.00,
  `vehicle_condition` varchar(50) DEFAULT 'GOOD',
  `current_status` enum('available','reserved','rented','inspection','scheduled_maintenance','maintenance','unavailable') DEFAULT 'available',
  `availability_date` datetime DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `current_odometer` decimal(10,1) NOT NULL DEFAULT 0.0
) ;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `plate_no`, `maker`, `model`, `ownership_type`, `chassis_number`, `engine_number`, `make_model`, `vehicle_type`, `category`, `seats`, `transmission`, `comfort_level`, `fuel_type`, `year`, `odometer`, `daily_rate`, `daily_rate_cdo`, `daily_rate_outside_cdo`, `vehicle_condition`, `current_status`, `availability_date`, `photo`, `created_at`, `current_odometer`) VALUES
(67, 'CDO-1103', 'Nissan', 'Almera 1.5 EL', 'Personal', 'NAL15A21M469', 'HR15-112233', 'Nissan Almera 1.5 EL', 'Sedan', NULL, 5, 'AT', 'Premium', 'Gasoline', '2021', 237.0, 1150.00, 1150.00, 1450.00, 'GOOD', 'rented', '2025-10-17 00:00:00', 'veh_68f1c6ea41c644.44510760.jpg', '2025-10-17 03:59:24', 237.0),
(68, 'CDO-1104', 'Other', 'Hyundai Accent GL', 'Personal', 'HAG19M470', 'U2-223344', 'Other Hyundai Accent GL', 'Sedan', NULL, 5, 'MT', 'Comfort', 'Gasoline', '2019', 230.0, 1050.00, 1050.00, 1300.00, 'GOOD', 'available', NULL, 'veh_68f1c60fbfd992.04632745.jpeg', '2025-10-17 03:59:24', 230.0),
(69, 'CDO-1203', 'Other', 'Isuzu mu-X RZ4E LS', 'Personal', 'IMX4E21A471', 'RZ4E-445566', 'Other Isuzu mu-X RZ4E LS', 'SUV', NULL, 7, 'AT', 'Luxury', 'Gasoline', '2021', 690.0, 3800.00, 3800.00, 4400.00, 'GOOD', 'available', NULL, 'veh_68f1c67424ac12.58600133.png', '2025-10-17 03:59:24', 690.0),
(70, 'CDO-1204', 'Nissan', 'Terra VL', 'Personal', 'NTVL23A472', 'YS23-778899', 'Nissan Terra VL', 'SUV', NULL, 7, 'AT', 'Luxury', 'Gasoline', '2023', 732.0, 4600.00, 4600.00, 5200.00, 'GOOD', 'available', NULL, 'veh_68f1c7221c8ce3.80934247.png', '2025-10-17 03:59:24', 732.0),
(71, 'CDO-1303', 'Suzuki', 'Swift GLX', 'Personal', 'SSGLX23A473', 'K12M-334455', 'Suzuki Swift GLX', 'Hatchback', NULL, 5, 'AT', 'Comfort', 'Gasoline', '2023', 523.0, 1200.00, 1200.00, 1500.00, 'GOOD', 'available', NULL, 'veh_68f1c776cafca4.85342379.jpeg', '2025-10-17 03:59:24', 523.0),
(72, 'CDO-1304', 'Other', 'Kia Picanto EX', 'Personal', 'KPE22A474', 'G3LA-556677', 'Other Kia Picanto EX', 'Hatchback', NULL, 5, 'AT', 'Comfort', 'Gasoline', '2022', 795.0, 1100.00, 1100.00, 1400.00, 'GOOD', 'available', NULL, 'veh_68f1c68e0081f0.36232943.jpeg', '2025-10-17 03:59:24', 795.0),
(73, 'CDO-1403', 'Toyota', 'Raize 1.0 Turbo', 'Personal', 'TRT10A475', '1KR-009988', 'Toyota Raize 1.0 Turbo', 'Crossover', NULL, 5, 'AT', 'Comfort', 'Gasoline', '2023', 723.0, 2700.00, 2700.00, 3100.00, 'GOOD', 'available', NULL, 'veh_68f1c75bc4e4a2.17405797.jpg', '2025-10-17 03:59:24', 723.0),
(74, 'CDO-1404', 'Other', 'MG ZS Alpha', 'Personal', 'MGZA22A476', '15S4G-778899', 'Other MG ZS Alpha', 'Crossover', NULL, 5, 'AT', 'Premium', 'Gasoline', '2022', 547.0, 2600.00, 2600.00, 3000.00, 'GOOD', 'available', NULL, 'veh_68f1c6aed31906.20255637.png', '2025-10-17 03:59:24', 547.0),
(75, 'CDO-1503', 'Nissan', 'Navara PRO-4X', 'Personal', 'NNP4X23A477', 'YS23-889900', 'Nissan Navara PRO-4X', 'Pickup Truck', NULL, 5, 'AT', 'Luxury', 'Gasoline', '2023', 542.0, 4200.00, 4200.00, 4700.00, 'GOOD', 'maintenance', NULL, 'veh_68f1c7077d1472.98967491.png', '2025-10-17 03:59:24', 542.0),
(76, 'CDO-1504', 'Other', 'Isuzu D-Max LS-A', 'Personal', 'IDL21M478', '4JJ1-667788', 'Other Isuzu D-Max LS-A', 'Pickup Truck', NULL, 5, 'AT', 'Luxury', 'Gasoline', '2021', 430.0, 3900.00, 3900.00, 4400.00, 'GOOD', 'available', NULL, 'veh_68f1c658850178.26042199.png', '2025-10-17 03:59:24', 430.0),
(77, 'CDO-1603', 'Other', 'Hyundai Starex GL', 'Personal', 'HSG21A479', 'D4CB-445566', 'Other Hyundai Starex GL', 'Van', NULL, 10, 'MT', 'Comfort', 'Diesel', '2021', 320.0, 3000.00, 3000.00, 3500.00, 'GOOD', 'available', NULL, 'veh_68f1c62da710c9.35734973.jpg', '2025-10-17 03:59:24', 320.0),
(78, 'CDO-1604', 'Other', 'Foton Transvan HR', 'Personal', 'FTHR21M480', '4JB1-556677', 'Other Foton Transvan HR', 'Van', NULL, 15, 'MT', 'Premium', 'Diesel', '2021', 2600.0, 2800.00, 2800.00, 3200.00, 'GOOD', 'rented', NULL, 'veh_68f1c5969d2701.60522844.jpg', '2025-10-17 03:59:24', 2600.0),
(79, 'CDO-1703', 'Mitsubishi', 'Xpander GLS', 'Personal', 'MXG23A481', '4A91-223344', 'Mitsubishi Xpander GLS', 'Minivan', NULL, 7, 'AT', 'Luxury', 'Gasoline', '2023', 1234.0, 2700.00, 2700.00, 3100.00, 'GOOD', 'available', NULL, 'veh_68f1c6cca398d2.90873171.jpeg', '2025-10-17 03:59:24', 764.0),
(80, 'CDO-1704', 'Honda', 'Mobilio', 'Personal', 'HME22A482', 'L15Z-334455', 'Honda Mobilio', 'Minivan', NULL, 7, 'AT', 'Comfort', 'Gasoline', '2022', 25000.0, 2600.00, 2600.00, 3000.00, 'GOOD', 'available', '2025-10-21 00:00:00', 'veh_68f1c5e4ec0d15.15406311.jpeg', '2025-10-17 03:59:24', 25000.0),
(81, 'CDO-1803', 'Suzuki', 'Raider 150 FI', 'Personal', 'SRR23M483', 'FZ16E-112233', 'Suzuki Raider 150 FI', 'Motorcycle', NULL, 2, 'MT', 'Economy', 'Gasoline', '2023', 873.0, 850.00, 850.00, 1050.00, 'GOOD', 'rented', NULL, 'veh_68f1c73a2cb839.58797259.jpeg', '2025-10-17 03:59:24', 873.0),
(82, 'CDO-1804', 'Honda', 'Beat', 'Personal', 'HBS21A484', 'JF91E-778899', 'Honda Beat', 'Motorcycle', NULL, 2, 'AT', 'Economy', 'Gasoline', '2021', 260.0, 700.00, 700.00, 900.00, 'GOOD', 'available', NULL, 'veh_68f1c5c21befa2.08951560.jpeg', '2025-10-17 03:59:24', 260.0),
(83, 'CDO-1903', 'Other', 'SYM Jet X 150', 'Personal', 'SJX23A485', 'E15X-445566', 'Other SYM Jet X 150', 'Scooter', NULL, 2, 'AT', 'Standard', 'Gasoline', '2023', 723.0, 950.00, 950.00, 1150.00, 'GOOD', 'available', NULL, 'veh_68f1c78c2dae38.07030625.jpg', '2025-10-17 03:59:24', 723.0),
(84, 'CDO-1904', 'Honda', 'ADV 160', 'Personal', 'HADV23A486', 'E16A-556677', 'Honda ADV 160', 'Scooter', NULL, 2, 'AT', 'Comfort', 'Gasoline', '2023', 50.0, 1100.00, 1100.00, 1300.00, 'GOOD', 'available', NULL, 'veh_699926025c17f3.38441342.png', '2025-10-17 03:59:24', 50.0);

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_maintenance_rules`
--

CREATE TABLE `vehicle_maintenance_rules` (
  `id` int(11) NOT NULL,
  `vehicle_id` int(11) NOT NULL,
  `rule_name` varchar(100) NOT NULL,
  `maintenance_category` enum('preventive','corrective','emergency','cleaning') DEFAULT 'preventive',
  `km_interval` int(11) DEFAULT NULL,
  `days_interval` int(11) DEFAULT NULL,
  `last_service_date` date DEFAULT NULL,
  `last_service_odometer` decimal(10,1) DEFAULT NULL,
  `next_due_date` date DEFAULT NULL,
  `next_due_odometer` decimal(10,1) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_active_maintenance`
-- (See below for the actual view)
--
CREATE TABLE `view_active_maintenance` (
`id` int(11)
,`vehicle_id` int(11)
,`plate_no` varchar(20)
,`make_model` varchar(100)
,`maintenance_category` enum('preventive','corrective','emergency','cleaning')
,`priority_level` enum('low','medium','high','critical')
,`schedule_date` date
,`status` enum('reported','scheduled','approved','in_progress','completed','cancelled','failed_inspection')
,`service_center` varchar(100)
,`assigned_to` varchar(100)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_available_vehicles_today`
-- (See below for the actual view)
--
CREATE TABLE `view_available_vehicles_today` (
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_booking_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_booking_summary` (
`booking_date` date
,`total_bookings` bigint(21)
,`total_income` decimal(34,2)
,`avg_booking_value` decimal(16,6)
,`completed_bookings` bigint(21)
,`cancelled_bookings` bigint(21)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_current_rentals`
-- (See below for the actual view)
--
CREATE TABLE `view_current_rentals` (
`rental_id` int(11)
,`customer_name` varchar(100)
,`vehicle` varchar(100)
,`start_date` date
,`end_date` date
,`status` enum('pending','reserved','ongoing','completed','cancelled','conflict_pending')
,`plate_no` varchar(20)
,`total_days` int(7)
,`total_cost` decimal(16,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_due_maintenance`
-- (See below for the actual view)
--
CREATE TABLE `view_due_maintenance` (
`vehicle_id` int(11)
,`plate_no` varchar(20)
,`make_model` varchar(100)
,`rule_name` varchar(100)
,`next_due_date` date
,`next_due_odometer` decimal(10,1)
,`current_odometer` decimal(10,1)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_maintenance_schedule`
-- (See below for the actual view)
--
CREATE TABLE `view_maintenance_schedule` (
`maintenance_id` int(11)
,`make_model` varchar(100)
,`plate_no` varchar(20)
,`schedule_date` date
,`status` enum('reported','scheduled','approved','in_progress','completed','cancelled','failed_inspection')
,`description` text
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_maintenance_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_maintenance_summary` (
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_open_maintenance_issues`
-- (See below for the actual view)
--
CREATE TABLE `view_open_maintenance_issues` (
`id` int(11)
,`vehicle_id` int(11)
,`plate_no` varchar(20)
,`make_model` varchar(100)
,`issue_type` enum('mechanical','electrical','body_damage','cleaning','tire','engine','other')
,`severity` enum('low','medium','high','critical')
,`issue_description` text
,`reported_at` timestamp
,`status` enum('open','converted','resolved','cancelled')
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_promo_summary`
-- (See below for the actual view)
--
CREATE TABLE `view_promo_summary` (
`promo_name` varchar(100)
,`promo_type` varchar(10)
,`times_used` bigint(21)
,`total_discount_given` decimal(32,2)
,`avg_discount_per_use` decimal(14,6)
,`total_revenue_with_promo` decimal(34,2)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `view_vehicle_maintenance_history`
-- (See below for the actual view)
--
CREATE TABLE `view_vehicle_maintenance_history` (
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_rental_payment_summary`
-- (See below for the actual view)
--
CREATE TABLE `vw_rental_payment_summary` (
`rental_id` int(11)
,`paid_amount` decimal(32,2)
);

-- --------------------------------------------------------

--
-- Table structure for table `washing_types`
--

CREATE TABLE `washing_types` (
  `id` int(11) NOT NULL,
  `vehicle_type` varchar(50) NOT NULL,
  `washing_name` varchar(100) NOT NULL,
  `washing_rate` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `washing_types`
--

INSERT INTO `washing_types` (`id`, `vehicle_type`, `washing_name`, `washing_rate`) VALUES
(13, 'Sedan', 'Basic Wash', 150.00),
(14, 'Sedan', 'Premium Wash', 250.00),
(15, 'Sedan', 'Full Detail', 400.00),
(16, 'SUV', 'Basic Wash', 200.00),
(17, 'SUV', 'Premium Wash', 350.00),
(18, 'SUV', 'Full Detail', 500.00),
(19, 'Pickup Truck', 'Basic Wash', 180.00),
(20, 'Pickup Truck', 'Premium Wash', 300.00),
(21, 'Pickup Truck', 'Full Detail', 450.00),
(22, 'Van', 'Basic Wash', 170.00),
(23, 'Van', 'Premium Wash', 280.00),
(24, 'Van', 'Full Detail', 420.00),
(25, 'Motorcycle', 'Basic Wash', 80.00),
(26, 'Motorcycle', 'Premium Wash', 120.00),
(27, 'Motorcycle', 'Full Detail', 180.00),
(28, 'Hatchback', 'Basic Wash', 120.00),
(29, 'Hatchback', 'Premium Wash', 200.00),
(30, 'Hatchback', 'Full Detail', 320.00),
(31, 'Crossover', 'Basic Wash', 180.00),
(32, 'Crossover', 'Premium Wash', 300.00),
(33, 'Crossover', 'Full Detail', 450.00),
(34, 'Minivan', 'Basic Wash', 190.00),
(35, 'Minivan', 'Premium Wash', 320.00),
(36, 'Minivan', 'Full Detail', 480.00),
(37, 'Scooter', 'Basic Wash', 60.00),
(38, 'Scooter', 'Premium Wash', 90.00),
(39, 'Scooter', 'Full Detail', 140.00);

-- --------------------------------------------------------

--
-- Structure for view `user_rental_summary`
--
DROP TABLE IF EXISTS `user_rental_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `user_rental_summary`  AS SELECT `rentals`.`customer_id` AS `customer_id`, count(0) AS `total_rentals`, sum(`rentals`.`total_cost`) AS `total_spent` FROM `rentals` GROUP BY `rentals`.`customer_id` ;

-- --------------------------------------------------------

--
-- Structure for view `view_active_maintenance`
--
DROP TABLE IF EXISTS `view_active_maintenance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_active_maintenance`  AS SELECT `m`.`id` AS `id`, `m`.`vehicle_id` AS `vehicle_id`, `v`.`plate_no` AS `plate_no`, `v`.`make_model` AS `make_model`, `m`.`maintenance_category` AS `maintenance_category`, `m`.`priority_level` AS `priority_level`, `m`.`schedule_date` AS `schedule_date`, `m`.`status` AS `status`, `m`.`service_center` AS `service_center`, `m`.`assigned_to` AS `assigned_to` FROM (`maintenance` `m` join `vehicles` `v` on(`v`.`id` = `m`.`vehicle_id`)) WHERE `m`.`status` in ('reported','scheduled','approved','in_progress') ;

-- --------------------------------------------------------

--
-- Structure for view `view_available_vehicles_today`
--
DROP TABLE IF EXISTS `view_available_vehicles_today`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_available_vehicles_today`  AS SELECT `v`.`id` AS `id`, `v`.`plate_no` AS `plate_no`, `v`.`make_model` AS `make_model`, `v`.`vehicle_type` AS `vehicle_type`, `v`.`seats` AS `seats`, `v`.`transmission` AS `transmission`, `v`.`comfort_level` AS `comfort_level`, `v`.`year` AS `year`, `v`.`odometer` AS `odometer`, `v`.`daily_rate` AS `daily_rate`, `v`.`condition_status` AS `condition_status`, `v`.`current_status` AS `current_status`, `v`.`photo` AS `photo`, `v`.`created_at` AS `created_at` FROM `vehicles` AS `v` WHERE `v`.`current_status` = 'available' AND !(`v`.`id` in (select `r`.`vehicle_id` from `rentals` `r` where `r`.`status` in ('ongoing','reserved') AND curdate() between `r`.`start_date` and `r`.`end_date`)) ;

-- --------------------------------------------------------

--
-- Structure for view `view_booking_summary`
--
DROP TABLE IF EXISTS `view_booking_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_booking_summary`  AS SELECT cast(`rentals`.`created_at` as date) AS `booking_date`, count(0) AS `total_bookings`, sum(`rentals`.`total_cost`) AS `total_income`, avg(`rentals`.`total_cost`) AS `avg_booking_value`, count(case when `rentals`.`status` = 'completed' then 1 end) AS `completed_bookings`, count(case when `rentals`.`status` = 'cancelled' then 1 end) AS `cancelled_bookings` FROM `rentals` WHERE `rentals`.`created_at` >= current_timestamp() - interval 1 year GROUP BY cast(`rentals`.`created_at` as date) ORDER BY cast(`rentals`.`created_at` as date) DESC ;

-- --------------------------------------------------------

--
-- Structure for view `view_current_rentals`
--
DROP TABLE IF EXISTS `view_current_rentals`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_current_rentals`  AS SELECT `r`.`id` AS `rental_id`, `u`.`full_name` AS `customer_name`, `v`.`make_model` AS `vehicle`, `r`.`start_date` AS `start_date`, `r`.`end_date` AS `end_date`, `r`.`status` AS `status`, `v`.`plate_no` AS `plate_no`, to_days(`r`.`end_date`) - to_days(`r`.`start_date`) AS `total_days`, (to_days(`r`.`end_date`) - to_days(`r`.`start_date`)) * `r`.`daily_rate` AS `total_cost` FROM ((`rentals` `r` join `vehicles` `v` on(`v`.`id` = `r`.`vehicle_id`)) join `users` `u` on(`u`.`id` = `r`.`customer_id`)) WHERE `r`.`status` in ('reserved','ongoing') ;

-- --------------------------------------------------------

--
-- Structure for view `view_due_maintenance`
--
DROP TABLE IF EXISTS `view_due_maintenance`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_due_maintenance`  AS SELECT `v`.`id` AS `vehicle_id`, `v`.`plate_no` AS `plate_no`, `v`.`make_model` AS `make_model`, `r`.`rule_name` AS `rule_name`, `r`.`next_due_date` AS `next_due_date`, `r`.`next_due_odometer` AS `next_due_odometer`, `v`.`current_odometer` AS `current_odometer` FROM (`vehicles` `v` join `vehicle_maintenance_rules` `r` on(`r`.`vehicle_id` = `v`.`id`)) WHERE `r`.`is_active` = 1 AND (`r`.`next_due_date` is not null AND `r`.`next_due_date` <= curdate() OR `r`.`next_due_odometer` is not null AND `v`.`current_odometer` >= `r`.`next_due_odometer`) ;

-- --------------------------------------------------------

--
-- Structure for view `view_maintenance_schedule`
--
DROP TABLE IF EXISTS `view_maintenance_schedule`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_maintenance_schedule`  AS SELECT `m`.`id` AS `maintenance_id`, `v`.`make_model` AS `make_model`, `v`.`plate_no` AS `plate_no`, `m`.`schedule_date` AS `schedule_date`, `m`.`status` AS `status`, `m`.`notes` AS `description` FROM (`maintenance` `m` join `vehicles` `v` on(`v`.`id` = `m`.`vehicle_id`)) WHERE `m`.`status` in ('scheduled','in_progress') ;

-- --------------------------------------------------------

--
-- Structure for view `view_maintenance_summary`
--
DROP TABLE IF EXISTS `view_maintenance_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_maintenance_summary`  AS SELECT `v`.`id` AS `vehicle_id`, `v`.`plate_no` AS `plate_no`, `v`.`make_model` AS `make_model`, count(`m`.`id`) AS `total_maintenance`, sum(coalesce(`m`.`cost`,`m`.`estimated_cost`,0)) AS `total_cost`, avg(coalesce(`m`.`cost`,`m`.`estimated_cost`,0)) AS `avg_cost`, count(case when coalesce(`m`.`maintenance_type`,`m`.`type`) = 'Preventive' then 1 end) AS `preventive_count`, count(case when coalesce(`m`.`maintenance_type`,`m`.`type`) = 'Corrective' then 1 end) AS `corrective_count`, count(case when coalesce(`m`.`maintenance_type`,`m`.`type`) = 'Emergency' then 1 end) AS `emergency_count` FROM (`vehicles` `v` left join `maintenance` `m` on(`v`.`id` = `m`.`vehicle_id`)) GROUP BY `v`.`id`, `v`.`plate_no`, `v`.`make_model` ;

-- --------------------------------------------------------

--
-- Structure for view `view_open_maintenance_issues`
--
DROP TABLE IF EXISTS `view_open_maintenance_issues`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_open_maintenance_issues`  AS SELECT `mi`.`id` AS `id`, `mi`.`vehicle_id` AS `vehicle_id`, `v`.`plate_no` AS `plate_no`, `v`.`make_model` AS `make_model`, `mi`.`issue_type` AS `issue_type`, `mi`.`severity` AS `severity`, `mi`.`issue_description` AS `issue_description`, `mi`.`reported_at` AS `reported_at`, `mi`.`status` AS `status` FROM (`maintenance_issues` `mi` join `vehicles` `v` on(`v`.`id` = `mi`.`vehicle_id`)) WHERE `mi`.`status` = 'open' ;

-- --------------------------------------------------------

--
-- Structure for view `view_promo_summary`
--
DROP TABLE IF EXISTS `view_promo_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_promo_summary`  AS SELECT `r`.`promo_applied` AS `promo_name`, CASE WHEN `r`.`promo_applied` = 'Welcome Discount' THEN 'First-Time' WHEN `r`.`promo_applied` = 'Loyalty Reward' THEN 'Loyalty' WHEN `r`.`promo_applied` = 'Long-Term Booking' THEN 'Long-Term' WHEN `r`.`promo_applied` = 'Seasonal Special' THEN 'Seasonal' ELSE 'Other' END AS `promo_type`, count(`r`.`id`) AS `times_used`, sum(`r`.`promo_discount`) AS `total_discount_given`, avg(`r`.`promo_discount`) AS `avg_discount_per_use`, sum(`r`.`total_cost`) AS `total_revenue_with_promo` FROM `rentals` AS `r` WHERE `r`.`promo_applied` is not null GROUP BY `r`.`promo_applied` ;

-- --------------------------------------------------------

--
-- Structure for view `view_vehicle_maintenance_history`
--
DROP TABLE IF EXISTS `view_vehicle_maintenance_history`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `view_vehicle_maintenance_history`  AS SELECT `v`.`id` AS `vehicle_id`, `v`.`make_model` AS `make_model`, `v`.`plate_no` AS `plate_no`, `m`.`id` AS `maintenance_id`, `m`.`type` AS `type`, `m`.`schedule_date` AS `schedule_date`, `m`.`status` AS `status`, `m`.`completed_date` AS `completed_date`, `m`.`estimated_cost` AS `estimated_cost`, `m`.`service_center` AS `service_center`, `m`.`notes` AS `notes` FROM (`maintenance` `m` join `vehicles` `v` on(`v`.`id` = `m`.`vehicle_id`)) ORDER BY `m`.`schedule_date` DESC ;

-- --------------------------------------------------------

--
-- Structure for view `vw_rental_payment_summary`
--
DROP TABLE IF EXISTS `vw_rental_payment_summary`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_rental_payment_summary`  AS SELECT `rental_payments`.`rental_id` AS `rental_id`, sum(`rental_payments`.`amount`) AS `paid_amount` FROM `rental_payments` WHERE `rental_payments`.`status` = 'POSTED' GROUP BY `rental_payments`.`rental_id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `admin_notification_reads`
--
ALTER TABLE `admin_notification_reads`
  ADD PRIMARY KEY (`admin_id`,`notification_id`),
  ADD KEY `notification_id` (`notification_id`);

--
-- Indexes for table `fuel_charge_rates`
--
ALTER TABLE `fuel_charge_rates`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `maintenance`
--
ALTER TABLE `maintenance`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_maint_vehicle` (`vehicle_id`),
  ADD KEY `idx_maintenance_type` (`maintenance_category`),
  ADD KEY `idx_maintenance_cost` (`cost`);

--
-- Indexes for table `maintenance_issues`
--
ALTER TABLE `maintenance_issues`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_issue_vehicle` (`vehicle_id`);

--
-- Indexes for table `maintenance_rules`
--
ALTER TABLE `maintenance_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_mr_vehicle` (`vehicle_id`);

--
-- Indexes for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_msh_maintenance` (`maintenance_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `vehicle_id` (`vehicle_id`),
  ADD KEY `idx_user_notifications` (`user_id`,`is_read`);

--
-- Indexes for table `promotions`
--
ALTER TABLE `promotions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `rentals`
--
ALTER TABLE `rentals`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rental_vehicle` (`vehicle_id`),
  ADD KEY `fk_rental_user` (`customer_id`),
  ADD KEY `idx_rentals_rate_type` (`rate_type`),
  ADD KEY `idx_rentals_promo_applied` (`promo_applied`),
  ADD KEY `idx_rentals_total_cost` (`total_cost`),
  ADD KEY `idx_start_date` (`start_date`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_vehicle` (`vehicle_id`),
  ADD KEY `idx_duration` (`total_days`);

--
-- Indexes for table `rental_payments`
--
ALTER TABLE `rental_payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD KEY `rental_id` (`rental_id`);

--
-- Indexes for table `rental_returns`
--
ALTER TABLE `rental_returns`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_rental_returns_rental` (`rental_id`),
  ADD KEY `idx_rental_id` (`rental_id`),
  ADD KEY `idx_return_date` (`actual_return_date`);

--
-- Indexes for table `rental_status_history`
--
ALTER TABLE `rental_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rsh_rental` (`rental_id`),
  ADD KEY `fk_rsh_user_fix` (`changed_by`);

--
-- Indexes for table `return_inspections`
--
ALTER TABLE `return_inspections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rental_id` (`rental_id`),
  ADD KEY `idx_created_at` (`created_at`);

--
-- Indexes for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_user_id` (`actor_user_id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `user_favorites`
--
ALTER TABLE `user_favorites`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uniq_user_vehicle` (`user_id`,`vehicle_id`);

--
-- Indexes for table `user_recent_views`
--
ALTER TABLE `user_recent_views`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_user_vehicle` (`user_id`,`vehicle_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `plate_no` (`plate_no`),
  ADD UNIQUE KEY `unique_chassis` (`chassis_number`),
  ADD UNIQUE KEY `unique_engine` (`engine_number`),
  ADD KEY `idx_vehicles_chassis` (`chassis_number`),
  ADD KEY `idx_vehicles_engine` (`engine_number`),
  ADD KEY `idx_vehicles_maker` (`maker`),
  ADD KEY `idx_vehicles_classification` (`ownership_type`),
  ADD KEY `idx_vehicles_availability_date` (`availability_date`),
  ADD KEY `idx_vehicle_type` (`vehicle_type`),
  ADD KEY `idx_status` (`current_status`);

--
-- Indexes for table `vehicle_maintenance_rules`
--
ALTER TABLE `vehicle_maintenance_rules`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_rule_vehicle` (`vehicle_id`);

--
-- Indexes for table `washing_types`
--
ALTER TABLE `washing_types`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `fuel_charge_rates`
--
ALTER TABLE `fuel_charge_rates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance`
--
ALTER TABLE `maintenance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_issues`
--
ALTER TABLE `maintenance_issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `maintenance_rules`
--
ALTER TABLE `maintenance_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `promotions`
--
ALTER TABLE `promotions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rentals`
--
ALTER TABLE `rentals`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rental_payments`
--
ALTER TABLE `rental_payments`
  MODIFY `payment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `rental_returns`
--
ALTER TABLE `rental_returns`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `rental_status_history`
--
ALTER TABLE `rental_status_history`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `return_inspections`
--
ALTER TABLE `return_inspections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `system_logs`
--
ALTER TABLE `system_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `user_documents`
--
ALTER TABLE `user_documents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=15;

--
-- AUTO_INCREMENT for table `user_favorites`
--
ALTER TABLE `user_favorites`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_recent_views`
--
ALTER TABLE `user_recent_views`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_maintenance_rules`
--
ALTER TABLE `vehicle_maintenance_rules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `washing_types`
--
ALTER TABLE `washing_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `maintenance`
--
ALTER TABLE `maintenance`
  ADD CONSTRAINT `fk_maint_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_issues`
--
ALTER TABLE `maintenance_issues`
  ADD CONSTRAINT `fk_issue_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `maintenance_rules`
--
ALTER TABLE `maintenance_rules`
  ADD CONSTRAINT `fk_mr_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `maintenance_status_history`
--
ALTER TABLE `maintenance_status_history`
  ADD CONSTRAINT `fk_msh_maintenance` FOREIGN KEY (`maintenance_id`) REFERENCES `maintenance` (`id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `notifications_ibfk_2` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);

--
-- Constraints for table `rentals`
--
ALTER TABLE `rentals`
  ADD CONSTRAINT `fk_rental_user` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rental_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rental_returns`
--
ALTER TABLE `rental_returns`
  ADD CONSTRAINT `fk_returns_rental` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `rental_status_history`
--
ALTER TABLE `rental_status_history`
  ADD CONSTRAINT `fk_rsh_rental` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rsh_user_fix` FOREIGN KEY (`changed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `return_inspections`
--
ALTER TABLE `return_inspections`
  ADD CONSTRAINT `return_inspections_ibfk_1` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`id`);

--
-- Constraints for table `system_logs`
--
ALTER TABLE `system_logs`
  ADD CONSTRAINT `system_logs_ibfk_1` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `user_documents`
--
ALTER TABLE `user_documents`
  ADD CONSTRAINT `fk_user_docs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `vehicle_maintenance_rules`
--
ALTER TABLE `vehicle_maintenance_rules`
  ADD CONSTRAINT `fk_rule_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
