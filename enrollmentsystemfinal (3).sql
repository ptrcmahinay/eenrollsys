-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 16, 2026 at 04:12 AM
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
-- Database: `enrollmentsystemfinal`
--

-- --------------------------------------------------------

--
-- Table structure for table `academic_terms`
--

CREATE TABLE `academic_terms` (
  `id` int(11) NOT NULL,
  `academic_year_id` int(11) NOT NULL,
  `semester` enum('1','2','mid') NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `enrollment_open` tinyint(1) NOT NULL DEFAULT 0,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `grade_deadline` date DEFAULT NULL,
  `registrar_deadline` date DEFAULT NULL,
  `chair_deadline` date DEFAULT NULL,
  `adviser_deadline` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `academic_terms`
--

INSERT INTO `academic_terms` (`id`, `academic_year_id`, `semester`, `is_active`, `enrollment_open`, `start_date`, `end_date`, `grade_deadline`, `registrar_deadline`, `chair_deadline`, `adviser_deadline`, `created_at`, `updated_at`, `status`) VALUES
(1, 1, '1', 0, 0, '2025-08-27', '2025-12-20', NULL, NULL, NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01', 'active'),
(2, 1, '2', 1, 1, '2026-01-15', '2026-05-30', NULL, NULL, NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `academic_years`
--

CREATE TABLE `academic_years` (
  `id` int(11) NOT NULL,
  `year_label` varchar(20) NOT NULL,
  `start_year` int(11) NOT NULL,
  `end_year` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `academic_years`
--

INSERT INTO `academic_years` (`id`, `year_label`, `start_year`, `end_year`, `is_active`, `created_at`, `status`) VALUES
(1, '2025-2026', 2025, 2026, 1, '2026-05-06 04:49:01', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `add_drop_requests`
--

CREATE TABLE `add_drop_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL DEFAULT 0,
  `action_type` enum('add','drop') NOT NULL DEFAULT 'add',
  `offering_id` int(11) DEFAULT NULL,
  `subject_id` int(11) NOT NULL,
  `section_id` int(11) DEFAULT NULL,
  `curriculum_id` int(11) DEFAULT NULL,
  `units` decimal(4,1) NOT NULL DEFAULT 0.0,
  `workflow_status` enum('submitted','adviser_approved','chair_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'submitted',
  `adviser_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `chair_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `registrar_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `adviser_remark` text DEFAULT NULL,
  `chair_remark` text DEFAULT NULL,
  `registrar_remark` text DEFAULT NULL,
  `adviser_processed_at` timestamp NULL DEFAULT NULL,
  `chair_processed_at` timestamp NULL DEFAULT NULL,
  `registrar_processed_at` timestamp NULL DEFAULT NULL,
  `adviser_processed_by` int(11) DEFAULT NULL,
  `chair_processed_by` int(11) DEFAULT NULL,
  `registrar_processed_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `request_type` enum('add','drop') NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reason` text DEFAULT NULL,
  `requested_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `processed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `dept_id` int(11) NOT NULL,
  `department_code` varchar(20) NOT NULL,
  `department_name` varchar(100) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`dept_id`, `department_code`, `department_name`, `status`, `created_at`) VALUES
(1, 'ASD', 'Arts and Scicene Department', 'active', '2026-05-06 04:49:01'),
(2, 'ITD', 'Information Technology Department', 'active', '2026-05-06 04:49:01'),
(3, 'TED', 'Teacher Education Department', 'active', '2026-05-06 04:49:01'),
(4, 'MD', 'Management Department', 'active', '2026-05-06 04:49:01'),
(7, 'FASD', 'Fisheries and Aquatic Science Department', 'active', '2026-05-20 11:56:11');

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_audit_log`
--

CREATE TABLE `enrollment_audit_log` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `actor_id` int(11) NOT NULL,
  `actor_role` varchar(50) NOT NULL,
  `old_status` varchar(50) DEFAULT NULL,
  `new_status` varchar(50) DEFAULT NULL,
  `remark` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_audit_log`
--

INSERT INTO `enrollment_audit_log` (`id`, `request_id`, `action`, `actor_id`, `actor_role`, `old_status`, `new_status`, `remark`, `created_at`) VALUES
(1, 8, 'student_submit', 8, 'student', NULL, 'submitted', NULL, '2026-05-28 07:44:52'),
(2, 9, 'student_submit', 9, 'student', NULL, 'submitted', NULL, '2026-05-28 07:49:50'),
(3, 4, 'registrar_forward', 2, 'registrar', 'chair_approved', 'registrar_forwarded', NULL, '2026-05-28 07:52:45'),
(4, 4, 'cashier_approve', 7, 'cashier', 'registrar_forwarded', 'cashier_approved', NULL, '2026-05-28 07:53:33'),
(5, 4, 'registrar_finalize', 2, 'registrar', 'cashier_approved', 'registrar_approved', NULL, '2026-05-28 07:54:03'),
(6, 8, 'student_cancel', 8, 'student', 'submitted', 'cancelled', NULL, '2026-05-31 14:36:59'),
(7, 10, 'student_submit', 8, 'student', NULL, 'submitted', NULL, '2026-05-31 14:38:34');

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_requests`
--

CREATE TABLE `enrollment_requests` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `requested_section_id` int(11) NOT NULL,
  `requested_status` enum('regular','irregular') NOT NULL,
  `workflow_status` enum('draft','submitted','adviser_approved','chair_approved','registrar_forwarded','cashier_approved','registrar_approved','rejected','cancelled') NOT NULL DEFAULT 'draft',
  `adviser_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `chair_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `registrar_status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `adviser_remark` text DEFAULT NULL,
  `chair_remark` text DEFAULT NULL,
  `registrar_remark` text DEFAULT NULL,
  `registrar_section_id` int(11) DEFAULT NULL,
  `ra10931_status` enum('free','extension_tuition','tuition') NOT NULL DEFAULT 'free',
  `payment_status` enum('unpaid','partial','paid','waived') NOT NULL DEFAULT 'unpaid',
  `total_units` decimal(6,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `adviser_processed_at` timestamp NULL DEFAULT NULL,
  `chair_processed_at` timestamp NULL DEFAULT NULL,
  `registrar_processed_at` timestamp NULL DEFAULT NULL,
  `adviser_processed_by` int(11) DEFAULT NULL,
  `chair_processed_by` int(11) DEFAULT NULL,
  `registrar_processed_by` int(11) DEFAULT NULL,
  `cashier_processed_at` timestamp NULL DEFAULT NULL,
  `cashier_processed_by` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_requests`
--

INSERT INTO `enrollment_requests` (`id`, `student_id`, `term_id`, `requested_section_id`, `requested_status`, `workflow_status`, `adviser_status`, `chair_status`, `registrar_status`, `adviser_remark`, `chair_remark`, `registrar_remark`, `registrar_section_id`, `ra10931_status`, `payment_status`, `total_units`, `total_amount`, `created_at`, `updated_at`, `adviser_processed_at`, `chair_processed_at`, `registrar_processed_at`, `adviser_processed_by`, `chair_processed_by`, `registrar_processed_by`, `cashier_processed_at`, `cashier_processed_by`) VALUES
(1, 3, 2, 1, 'regular', 'registrar_approved', 'approved', 'approved', 'approved', 'Cleared by adviser.', 'Approved by chair.', 'Finalized by registrar.', 1, 'free', 'unpaid', 23.00, 0.00, '2026-05-06 04:49:01', '2026-05-06 04:49:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 4, 2, 1, 'regular', 'submitted', 'pending', 'pending', 'pending', '', '', '', NULL, 'free', 'unpaid', 23.00, 0.00, '2026-05-06 04:49:01', '2026-05-06 04:49:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 5, 2, 1, 'regular', 'adviser_approved', 'approved', 'pending', 'pending', 'Eligible to proceed.', '', '', NULL, 'free', 'unpaid', 23.00, 0.00, '2026-05-06 04:49:01', '2026-05-06 04:49:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 6, 2, 1, 'regular', 'registrar_approved', 'approved', 'approved', 'approved', 'Eligible to proceed.', 'Department approved.', '', 1, 'free', 'unpaid', 23.00, 0.00, '2026-05-06 04:49:01', '2026-05-28 07:54:03', NULL, NULL, '2026-05-28 07:54:03', NULL, NULL, 2, '2026-05-28 07:53:33', 7),
(5, 7, 2, 1, 'regular', 'registrar_approved', 'approved', 'approved', 'approved', 'Eligible.', 'Approved.', 'Extension tuition student.', 1, 'extension_tuition', 'unpaid', 23.00, 12650.00, '2026-05-06 04:49:01', '2026-05-06 04:49:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(8, 1, 2, 1, 'regular', 'cancelled', 'pending', 'pending', 'pending', '', '', '', NULL, 'free', 'unpaid', 23.00, 600.00, '2026-05-28 07:44:52', '2026-05-31 14:36:59', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(9, 2, 2, 1, 'irregular', 'submitted', 'pending', 'pending', 'pending', '', '', '', NULL, 'free', 'unpaid', 11.00, 0.00, '2026-05-28 07:49:50', '2026-05-28 07:49:50', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(10, 1, 2, 1, 'regular', 'submitted', 'pending', 'pending', 'pending', '', '', '', NULL, 'free', 'unpaid', 23.00, 600.00, '2026-05-31 14:38:34', '2026-05-31 14:38:34', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_request_items`
--

CREATE TABLE `enrollment_request_items` (
  `id` int(11) NOT NULL,
  `request_id` int(11) NOT NULL,
  `offering_id` int(11) NOT NULL,
  `action_type` enum('add','drop') NOT NULL DEFAULT 'add'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_request_items`
--

INSERT INTO `enrollment_request_items` (`id`, `request_id`, `offering_id`, `action_type`) VALUES
(1, 1, 17, 'add'),
(2, 1, 18, 'add'),
(3, 1, 19, 'add'),
(4, 1, 20, 'add'),
(5, 1, 21, 'add'),
(6, 1, 22, 'add'),
(7, 1, 23, 'add'),
(8, 1, 24, 'add'),
(9, 2, 17, 'add'),
(10, 2, 18, 'add'),
(11, 2, 19, 'add'),
(12, 2, 20, 'add'),
(13, 2, 21, 'add'),
(14, 2, 22, 'add'),
(15, 2, 23, 'add'),
(16, 2, 24, 'add'),
(17, 3, 17, 'add'),
(18, 3, 18, 'add'),
(19, 3, 19, 'add'),
(20, 3, 20, 'add'),
(21, 3, 21, 'add'),
(22, 3, 22, 'add'),
(23, 3, 23, 'add'),
(24, 3, 24, 'add'),
(25, 4, 17, 'add'),
(26, 4, 18, 'add'),
(27, 4, 19, 'add'),
(28, 4, 20, 'add'),
(29, 4, 21, 'add'),
(30, 4, 22, 'add'),
(31, 4, 23, 'add'),
(32, 4, 24, 'add'),
(33, 5, 17, 'add'),
(34, 5, 18, 'add'),
(35, 5, 19, 'add'),
(36, 5, 20, 'add'),
(37, 5, 21, 'add'),
(38, 5, 22, 'add'),
(39, 5, 23, 'add'),
(40, 5, 24, 'add'),
(57, 8, 21, 'add'),
(58, 8, 23, 'add'),
(59, 8, 17, 'add'),
(60, 8, 20, 'add'),
(61, 8, 18, 'add'),
(62, 8, 19, 'add'),
(63, 8, 22, 'add'),
(64, 8, 24, 'add'),
(65, 9, 23, 'add'),
(66, 9, 17, 'add'),
(67, 9, 20, 'add'),
(68, 9, 18, 'add'),
(69, 10, 21, 'add'),
(70, 10, 23, 'add'),
(71, 10, 17, 'add'),
(72, 10, 20, 'add'),
(73, 10, 18, 'add'),
(74, 10, 19, 'add'),
(75, 10, 22, 'add'),
(76, 10, 24, 'add');

-- --------------------------------------------------------

--
-- Table structure for table `enrollment_schedules`
--

CREATE TABLE `enrollment_schedules` (
  `id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `open_date` date NOT NULL,
  `close_date` date NOT NULL,
  `open_time` time NOT NULL,
  `close_time` time NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `enrollment_schedules`
--

INSERT INTO `enrollment_schedules` (`id`, `term_id`, `year_level`, `open_date`, `close_date`, `open_time`, `close_time`, `created_at`, `updated_at`) VALUES
(1, 2, 1, '2026-05-28', '2026-06-04', '07:00:00', '19:00:00', '2026-05-07 05:00:34', '2026-05-28 07:44:16');

-- --------------------------------------------------------

--
-- Table structure for table `fee_items`
--

CREATE TABLE `fee_items` (
  `id` int(11) NOT NULL,
  `category` enum('laboratory','other','assessment') NOT NULL,
  `fee_name` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `program_id` int(11) DEFAULT NULL,
  `year_level` int(11) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  `is_mandatory` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `fee_items`
--

INSERT INTO `fee_items` (`id`, `category`, `fee_name`, `amount`, `program_id`, `year_level`, `semester`, `is_mandatory`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'laboratory', 'Technology', 200.00, 1, NULL, NULL, 1, 1, '2026-05-20 11:42:59', '2026-05-20 11:43:52'),
(2, 'assessment', 'tuition', 110.00, NULL, NULL, NULL, 1, 1, '2026-05-20 11:44:57', '2026-05-20 11:44:57'),
(3, 'assessment', 'library', 300.00, NULL, NULL, NULL, 0, 1, '2026-05-20 11:45:15', '2026-05-20 11:46:16'),
(4, 'assessment', 'med / dental', 50.00, NULL, NULL, NULL, 0, 1, '2026-05-20 11:45:30', '2026-05-20 11:45:30'),
(5, 'assessment', 'publication', 50.00, NULL, NULL, NULL, 0, 1, '2026-05-20 11:45:51', '2026-05-20 11:45:51'),
(6, 'other', 'OJT', 100.00, NULL, 4, NULL, 0, 1, '2026-05-20 11:50:15', '2026-05-27 06:27:32'),
(7, 'assessment', 'registration fee', 100.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:21:34', '2026-05-27 06:21:34'),
(8, 'assessment', 'guidance fee', 75.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:21:57', '2026-05-27 06:21:57'),
(9, 'assessment', 'ID', 100.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:22:27', '2026-05-27 06:22:27'),
(10, 'assessment', 'SFDF', 1500.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:22:53', '2026-05-27 06:22:53'),
(11, 'assessment', 'SRF', 1625.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:23:12', '2026-05-27 06:23:12'),
(12, 'assessment', 'Athletic', 100.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:23:30', '2026-05-27 06:23:30'),
(13, 'assessment', 'SCUAA', 100.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:23:47', '2026-05-27 06:23:47'),
(14, 'assessment', 'Insurance', 25.00, NULL, NULL, NULL, 0, 1, '2026-05-27 06:24:17', '2026-05-27 06:24:17'),
(15, 'laboratory', 'fishery', 200.00, 5, NULL, NULL, 0, 1, '2026-05-27 06:27:00', '2026-05-28 05:11:43'),
(16, 'other', 'NSTP', 330.00, NULL, 1, NULL, 0, 1, '2026-05-27 08:18:33', '2026-05-27 08:18:33');

-- --------------------------------------------------------

--
-- Table structure for table `grades`
--

CREATE TABLE `grades` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `curriculum_id` int(11) NOT NULL,
  `offering_id` int(11) NOT NULL,
  `grade` varchar(10) DEFAULT NULL,
  `instructor_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `grades`
--

INSERT INTO `grades` (`id`, `student_id`, `curriculum_id`, `offering_id`, `grade`, `instructor_id`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(2, 1, 2, 2, '2.00', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(3, 1, 3, 3, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(4, 1, 4, 4, '2.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(5, 1, 5, 5, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(6, 1, 6, 6, '1.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(7, 1, 7, 7, '1.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(8, 1, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(9, 2, 1, 1, '2.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(10, 2, 2, 2, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(11, 2, 3, 3, 'INC', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(12, 2, 4, 4, '2.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(13, 2, 5, 5, '2.00', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(14, 2, 6, 6, '5.00', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(15, 2, 7, 7, '1.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(16, 2, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(17, 3, 1, 1, '2.00', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(18, 3, 2, 2, '2.00', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(19, 3, 3, 3, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(20, 3, 4, 4, '2.25', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(21, 3, 5, 5, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(22, 3, 6, 6, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(23, 3, 7, 7, '1.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(24, 3, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(25, 4, 1, 1, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(26, 4, 2, 2, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(27, 4, 3, 3, '2.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(28, 4, 4, 4, '2.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(29, 4, 5, 5, '2.00', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(30, 4, 6, 6, '2.00', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(31, 4, 7, 7, '1.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(32, 4, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(33, 5, 1, 1, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(34, 5, 2, 2, '2.00', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(35, 5, 3, 3, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(36, 5, 4, 4, '2.25', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(37, 5, 5, 5, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(38, 5, 6, 6, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(39, 5, 7, 7, '1.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(40, 5, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(41, 6, 1, 1, '2.00', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(42, 6, 2, 2, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(43, 6, 3, 3, '2.25', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(44, 6, 4, 4, '2.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(45, 6, 5, 5, '1.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(46, 6, 6, 6, '1.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(47, 6, 7, 7, '1.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(48, 6, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(49, 7, 1, 1, '2.75', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(50, 7, 2, 2, '2.50', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(51, 7, 3, 3, '2.75', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(52, 7, 4, 4, '2.75', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(53, 7, 5, 5, '2.50', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(54, 7, 6, 6, '2.25', 5, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(55, 7, 7, 7, '1.75', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(56, 7, 8, 8, 'P', 6, '2026-05-06 04:49:01', '2026-05-06 04:49:01');

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `token` varchar(64) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used` tinyint(1) NOT NULL DEFAULT 0,
  `used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `password_reset_tokens`
--

INSERT INTO `password_reset_tokens` (`id`, `user_id`, `token`, `expires_at`, `used`, `used_at`, `created_at`) VALUES
(1, 12, 'd5ef331c1382e462add2fb3bf2e3347804971abf4742d6db21cec3de7561b4f7', '2026-05-15 06:42:08', 0, NULL, '2026-05-15 11:42:08'),
(2, 12, '2029e4ab344d66a7635b409c4531cb042e29bab201dd33199e8eacf9acf777dd', '2026-05-15 06:42:20', 0, NULL, '2026-05-15 11:42:20'),
(3, 12, '8bd3d62ca4afadd2c657a510d1312fcfa9870a87e224733b83e6868cbf5dd875', '2026-05-15 06:43:20', 0, NULL, '2026-05-15 11:43:20'),
(4, 12, '0e362b917becb60a049d4dad26c82dd60df0f0d06fdc169819987ff451d45733', '2026-05-15 06:43:35', 0, NULL, '2026-05-15 11:43:35');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `request_id` int(11) DEFAULT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `payment_method` varchar(50) DEFAULT NULL,
  `reference_number` varchar(100) DEFAULT NULL,
  `remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `programs`
--

CREATE TABLE `programs` (
  `programs_id` int(11) NOT NULL,
  `department_id` int(11) NOT NULL,
  `program_code` varchar(20) NOT NULL,
  `program_name` varchar(120) NOT NULL,
  `program_major` varchar(255) DEFAULT NULL,
  `lab_fee_per_unit` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `programs`
--

INSERT INTO `programs` (`programs_id`, `department_id`, `program_code`, `program_name`, `program_major`, `lab_fee_per_unit`, `status`, `created_at`) VALUES
(1, 2, 'BSIT', 'Bachelor of Science in Information Technology', NULL, 0.00, 'active', '2026-05-06 04:49:01'),
(2, 2, 'BSCS', 'Bachelor of Science in Computer Science', NULL, 0.00, 'active', '2026-05-06 04:49:01'),
(3, 1, 'BEED', 'Bachelor of Elementary Education', NULL, 0.00, 'active', '2026-05-06 04:49:01'),
(4, 4, 'BSBA', 'Bachelor of Science in Business Administration', NULL, 0.00, 'active', '2026-05-06 04:49:01'),
(5, 7, 'BSFAS', 'Bachelor of Science in Fisheries and Aquatic Sciences', NULL, 0.00, 'active', '2026-05-20 12:13:22'),
(6, 1, 'BSED-E', 'Bachelor of Secondary Education', 'Major in English', 0.00, 'active', '2026-05-20 13:07:42'),
(7, 1, 'BSED-S', 'Bachelor of Secondary Education', 'Major in Science', 0.00, 'active', '2026-05-20 13:08:27');

-- --------------------------------------------------------

--
-- Table structure for table `program_curriculum`
--

CREATE TABLE `program_curriculum` (
  `curriculum_id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `semester` enum('1st','2nd','mid') NOT NULL,
  `prerequisite_subject_id` int(11) DEFAULT NULL,
  `prerequisite_subject_2_id` int(11) DEFAULT NULL,
  `prerequisite_subject_3_id` int(11) DEFAULT NULL,
  `standing` varchar(50) DEFAULT NULL,
  `curriculum_label` varchar(40) NOT NULL DEFAULT '2024',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `program_curriculum`
--

INSERT INTO `program_curriculum` (`curriculum_id`, `program_id`, `subject_id`, `year_level`, `semester`, `prerequisite_subject_id`, `prerequisite_subject_2_id`, `prerequisite_subject_3_id`, `standing`, `curriculum_label`, `created_at`, `status`) VALUES
(1, 1, 1, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(2, 1, 2, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(3, 1, 3, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(4, 1, 4, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(5, 1, 5, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(6, 1, 6, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(7, 1, 7, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(8, 1, 8, '1', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(9, 1, 9, '1', '2nd', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(10, 1, 10, '1', '2nd', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(11, 1, 11, '1', '2nd', 3, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(12, 1, 12, '1', '2nd', NULL, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(13, 1, 13, '1', '2nd', 6, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(14, 1, 14, '1', '2nd', 5, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(15, 1, 15, '1', '2nd', 7, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(16, 1, 16, '1', '2nd', 8, NULL, NULL, NULL, '2024', '2026-05-06 04:49:01', 'active'),
(17, 1, 17, '2', '1st', NULL, NULL, NULL, NULL, '2024', '2026-05-27 08:04:12', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `roles_id` int(11) NOT NULL,
  `role_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`roles_id`, `role_name`) VALUES
(5, 'admin'),
(6, 'adviser'),
(4, 'cashier'),
(7, 'department_chair'),
(2, 'instructor'),
(3, 'registrar'),
(1, 'student');

-- --------------------------------------------------------

--
-- Table structure for table `sections`
--

CREATE TABLE `sections` (
  `id` int(11) NOT NULL,
  `program_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL,
  `section_name` varchar(10) NOT NULL,
  `adviser_id` int(11) DEFAULT NULL,
  `max_slots` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `sections`
--

INSERT INTO `sections` (`id`, `program_id`, `year_level`, `section_name`, `adviser_id`, `max_slots`, `status`, `created_at`) VALUES
(1, 1, 1, 'A', 4, 40, 'active', '2026-05-06 04:49:01'),
(2, 1, 1, 'B', 4, 40, 'active', '2026-05-06 04:49:01'),
(3, 1, 2, 'A', 4, 35, 'active', '2026-05-06 04:49:01'),
(4, 2, 1, 'A', NULL, 35, 'active', '2026-05-06 04:49:01'),
(5, 1, 3, 'A', NULL, 50, 'active', '2026-05-07 05:09:31');

-- --------------------------------------------------------

--
-- Table structure for table `section_subject_offerings`
--

CREATE TABLE `section_subject_offerings` (
  `id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `curriculum_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `instructor_id` int(11) DEFAULT NULL,
  `room` varchar(50) DEFAULT NULL,
  `day_of_week` varchar(30) DEFAULT NULL,
  `time_range` varchar(60) DEFAULT NULL,
  `max_slots` int(11) DEFAULT NULL,
  `syllabus_path` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('active','inactive') NOT NULL DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `section_subject_offerings`
--

INSERT INTO `section_subject_offerings` (`id`, `term_id`, `section_id`, `curriculum_id`, `subject_id`, `instructor_id`, `room`, `day_of_week`, `time_range`, `max_slots`, `syllabus_path`, `created_at`, `status`) VALUES
(1, 1, 1, 1, 1, 6, 'Room 101', 'Mon', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(2, 1, 1, 2, 2, 6, 'Room 102', 'Mon', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(3, 1, 1, 3, 3, 6, 'Room 103', 'Tue', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(4, 1, 1, 4, 4, 5, 'Room 104', 'Tue', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(5, 1, 1, 5, 5, 5, 'Lab 201', 'Wed', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(6, 1, 1, 6, 6, 5, 'Lab 202', 'Wed', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(7, 1, 1, 7, 7, 6, 'Gym', 'Thu', '8:00 AM - 9:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(8, 1, 1, 8, 8, 6, 'Hall', 'Fri', '8:00 AM - 10:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(9, 1, 2, 1, 1, 6, 'Room 101', 'Mon', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(10, 1, 2, 2, 2, 6, 'Room 102', 'Mon', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(11, 1, 2, 3, 3, 6, 'Room 103', 'Tue', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(12, 1, 2, 4, 4, 5, 'Room 104', 'Tue', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(13, 1, 2, 5, 5, 5, 'Lab 201', 'Wed', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(14, 1, 2, 6, 6, 5, 'Lab 202', 'Wed', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(15, 1, 2, 7, 7, 6, 'Gym', 'Thu', '1:00 AM - 2:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(16, 1, 2, 8, 8, 6, 'Hall', 'Fri', '1:00 AM - 3:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(17, 2, 1, 9, 9, 6, 'Room 101', 'Mon', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(18, 2, 1, 10, 10, 6, 'Room 102', 'Mon', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(19, 2, 1, 11, 11, 6, 'Room 103', 'Tue', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(20, 2, 1, 12, 12, 6, 'Room 104', 'Tue', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(21, 2, 1, 13, 13, 5, 'Lab 201', 'Wed', '8:00 AM - 9:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(22, 2, 1, 14, 14, 5, 'Lab 202', 'Wed', '9:30 AM - 11:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(23, 2, 1, 15, 15, 6, 'Gym', 'Thu', '8:00 AM - 9:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(24, 2, 1, 16, 16, 6, 'Hall', 'Fri', '8:00 AM - 10:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(25, 2, 2, 9, 9, 6, 'Room 101', 'Mon', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(26, 2, 2, 10, 10, 6, 'Room 102', 'Mon', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(27, 2, 2, 11, 11, 6, 'Room 103', 'Tue', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(28, 2, 2, 12, 12, 6, 'Room 104', 'Tue', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(29, 2, 2, 13, 13, 5, 'Lab 201', 'Wed', '1:00 AM - 2:30 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(30, 2, 2, 14, 14, 5, 'Lab 202', 'Wed', '2:30 AM - 4:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(31, 2, 2, 15, 15, 6, 'Gym', 'Thu', '1:00 AM - 2:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active'),
(32, 2, 2, 16, 16, 6, 'Hall', 'Fri', '1:00 AM - 3:00 AM', NULL, NULL, '2026-05-06 04:49:01', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` int(11) NOT NULL,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `setting_key`, `setting_value`) VALUES
(1, 'portal_name', 'E-Enrollment System'),
(2, 'campus_name', 'Cavite State University Naic'),
(3, 'campus_address', 'Bucana, Naic, Cavite'),
(4, 'max_section_slots', '40'),
(5, 'tuition_per_unit', '550'),
(6, 'other_school_fees', '2500'),
(7, 'registrar_name', 'MAC JOHN T. POBLETE'),
(8, 'registrar_title', 'Campus Registrar'),
(9, 'cog_purpose', 'For scholarship purposes only.'),
(10, 'allow_online_enrollment', '1'),
(11, 'system_name', 'E-EnrollSystem'),
(19, 'institution_name', 'Your Institution'),
(20, 'smtp_host', 'sandbox.smtp.mailtrap.io'),
(21, 'smtp_port', '587'),
(22, 'smtp_username', '1874736b62e67c'),
(23, 'smtp_password', 'a376b8a1bedf64'),
(24, 'smtp_from_email', 'eenrollsys@gmail.com'),
(25, 'smtp_from_name', 'E-EnrollSys');

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `staff_id` int(11) NOT NULL,
  `users_id` int(11) NOT NULL,
  `employee_number` varchar(30) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `dept_id` int(11) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`staff_id`, `users_id`, `employee_number`, `full_name`, `email`, `dept_id`, `status`, `created_at`) VALUES
(1, 1, 'EMP-0001', 'System Administrator', 'admin1@example.com', NULL, 'active', '2026-05-06 04:49:01'),
(2, 2, 'EMP-0002', 'Registrar Staff', 'registrar1@example.com', NULL, 'active', '2026-05-06 04:49:01'),
(3, 3, 'EMP-0003', 'IT Department Chair', 'chair.itd@example.com', 2, 'active', '2026-05-06 04:49:01'),
(4, 4, 'EMP-0004', 'IT Adviser', 'adviser.itd@example.com', 2, 'active', '2026-05-06 04:49:01'),
(5, 5, 'EMP-0005', 'IT Instructor', 'instructor.itd@example.com', 2, 'active', '2026-05-06 04:49:01'),
(6, 6, 'EMP-0006', 'ASD Instructor', 'instructor.asd@example.com', 1, 'active', '2026-05-06 04:49:01'),
(7, 7, 'EMP-0007', 'Cashier Staff', 'cashier1@example.com', NULL, 'active', '2026-05-06 04:49:01');

-- --------------------------------------------------------

--
-- Table structure for table `staff_notifications`
--

CREATE TABLE `staff_notifications` (
  `id` int(11) NOT NULL,
  `staff_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `staff_notifications`
--

INSERT INTO `staff_notifications` (`id`, `staff_id`, `type`, `subject`, `body`, `is_read`, `dismissed`, `created_at`) VALUES
(1, 7, 'info', 'Enrollment Request Pending Fee Approval', 'An enrollment request has been forwarded by the Registrar for fee processing.', 0, 0, '2026-05-28 07:52:45'),
(2, 2, 'info', 'Enrollment Ready for Finalization', 'An enrollment request has been approved by the Cashier and is ready for registrar finalization.', 0, 0, '2026-05-28 07:53:33'),
(3, 7, 'info', 'New Student Enrolled — Payment Processing Required', 'A student has been finalized by the registrar and may require payment processing. Please check the cashier dashboard.', 0, 0, '2026-05-28 07:54:03');

-- --------------------------------------------------------

--
-- Table structure for table `students`
--

CREATE TABLE `students` (
  `id` int(11) NOT NULL,
  `student_number` varchar(20) NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `address` varchar(255) NOT NULL,
  `program_id` int(11) NOT NULL,
  `year_level` int(11) NOT NULL DEFAULT 1,
  `section_id` int(11) DEFAULT NULL,
  `entry_year` int(11) NOT NULL,
  `ra10931_override` enum('auto','free','extension_tuition','tuition') NOT NULL DEFAULT 'auto',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `students`
--

INSERT INTO `students` (`id`, `student_number`, `full_name`, `address`, `program_id`, `year_level`, `section_id`, `entry_year`, `ra10931_override`, `status`, `created_at`) VALUES
(1, '20250001', 'Alice Regular', 'Bancaan, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(2, '20250002', 'Ben Irregular', 'Bucana, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(3, '20250003', 'Carla Enrolled', 'Labac, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(4, '20250004', 'Diana Adviser Queue', 'Munting Mapino, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(5, '20250005', 'Evan Chair Queue', 'Muzon, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(6, '20250006', 'Fiona Registrar Queue', 'Bucana, Naic, Cavite', 1, 1, 1, 2025, 'auto', 'active', '2026-05-06 04:49:01'),
(7, '20200007', 'Greg Extension Tuition', 'Bagong Bayan, Naic, Cavite', 1, 1, 1, 2020, 'auto', 'active', '2026-05-06 04:49:01'),
(8, '202310658', 'Patricia Ann C. Mahinay', 'Bagong Kalsada, Naic, Cavite', 1, 3, 5, 2023, 'auto', 'active', '2026-05-15 08:54:40');

-- --------------------------------------------------------

--
-- Table structure for table `student_notifications`
--

CREATE TABLE `student_notifications` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'info',
  `subject` varchar(255) NOT NULL,
  `body` text NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `dismissed` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_notifications`
--

INSERT INTO `student_notifications` (`id`, `student_id`, `type`, `subject`, `body`, `is_read`, `dismissed`, `created_at`) VALUES
(1, 1, 'info', 'COG Requested', 'You requested a Certificate of Grades for: Scholarship purposes on May 7, 2026 7:05 AM', 1, 1, '2026-05-07 05:05:02'),
(2, 3, 'info', 'COG Requested', 'You requested a Certificate of Grades for: Scholarship purposes on May 28, 2026 8:07 AM', 1, 1, '2026-05-28 06:07:15'),
(3, 1, 'info', 'Enrollment Request Submitted', 'Your enrollment request for 2025-2026 Second Semester has been submitted successfully with 23 units. It is now pending adviser review.', 1, 1, '2026-05-28 07:44:52'),
(4, 2, 'info', 'Enrollment Request Submitted', 'Your enrollment request for 2025-2026 Second Semester has been submitted successfully with 11 units. It is now pending adviser review.', 0, 0, '2026-05-28 07:49:50'),
(5, 6, 'info', 'Enrollment Forwarded to Cashier', 'Your enrollment request has been forwarded to the Cashier for fee assessment.', 0, 1, '2026-05-28 07:52:45'),
(6, 6, 'info', 'Enrollment Approved by Cashier', 'Your enrollment has been approved by the Cashier. It is now ready for Registrar finalization.', 0, 1, '2026-05-28 07:53:33'),
(7, 6, 'info', 'Enrollment Approved — You Are Now Enrolled', 'Congratulations! Your enrollment request has been approved by the Registrar. You are now officially enrolled for this term. You may download your Registration Form from the enrollment status page.', 0, 1, '2026-05-28 07:54:03'),
(8, 1, 'info', 'Enrollment Request Submitted', 'Your enrollment request for 2025-2026 Second Semester has been submitted successfully with 23 units. It is now pending adviser review.', 0, 1, '2026-05-31 14:38:34');

-- --------------------------------------------------------

--
-- Table structure for table `student_subjects`
--

CREATE TABLE `student_subjects` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `term_id` int(11) NOT NULL,
  `offering_id` int(11) NOT NULL,
  `curriculum_id` int(11) NOT NULL,
  `subject_id` int(11) NOT NULL,
  `section_id` int(11) NOT NULL,
  `units` decimal(4,1) NOT NULL,
  `enrollment_status` enum('enrolled','completed','dropped') NOT NULL DEFAULT 'enrolled',
  `final_grade` varchar(10) DEFAULT NULL,
  `remarks` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student_subjects`
--

INSERT INTO `student_subjects` (`id`, `student_id`, `term_id`, `offering_id`, `curriculum_id`, `subject_id`, `section_id`, `units`, `enrollment_status`, `final_grade`, `remarks`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 1, 1, 1, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(2, 1, 1, 2, 2, 2, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(3, 1, 1, 3, 3, 3, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(4, 1, 1, 4, 4, 4, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(5, 1, 1, 5, 5, 5, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(6, 1, 1, 6, 6, 6, 1, 3.0, 'completed', '1.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(7, 1, 1, 7, 7, 7, 1, 2.0, 'completed', '1.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(8, 1, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(9, 2, 1, 1, 1, 1, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(10, 2, 1, 2, 2, 2, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(11, 2, 1, 3, 3, 3, 1, 3.0, 'completed', 'INC', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(12, 2, 1, 4, 4, 4, 1, 3.0, 'completed', '2.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(13, 2, 1, 5, 5, 5, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(14, 2, 1, 6, 6, 6, 1, 3.0, 'completed', '5.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(15, 2, 1, 7, 7, 7, 1, 2.0, 'completed', '1.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(16, 2, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(17, 3, 1, 1, 1, 1, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(18, 3, 1, 2, 2, 2, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(19, 3, 1, 3, 3, 3, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(20, 3, 1, 4, 4, 4, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(21, 3, 1, 5, 5, 5, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(22, 3, 1, 6, 6, 6, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(23, 3, 1, 7, 7, 7, 1, 2.0, 'completed', '1.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(24, 3, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(25, 4, 1, 1, 1, 1, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(26, 4, 1, 2, 2, 2, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(27, 4, 1, 3, 3, 3, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(28, 4, 1, 4, 4, 4, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(29, 4, 1, 5, 5, 5, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(30, 4, 1, 6, 6, 6, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(31, 4, 1, 7, 7, 7, 1, 2.0, 'completed', '1.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(32, 4, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(33, 5, 1, 1, 1, 1, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(34, 5, 1, 2, 2, 2, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(35, 5, 1, 3, 3, 3, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(36, 5, 1, 4, 4, 4, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(37, 5, 1, 5, 5, 5, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(38, 5, 1, 6, 6, 6, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(39, 5, 1, 7, 7, 7, 1, 2.0, 'completed', '1.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(40, 5, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(41, 6, 1, 1, 1, 1, 1, 3.0, 'completed', '2.00', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(42, 6, 1, 2, 2, 2, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(43, 6, 1, 3, 3, 3, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(44, 6, 1, 4, 4, 4, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(45, 6, 1, 5, 5, 5, 1, 3.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(46, 6, 1, 6, 6, 6, 1, 3.0, 'completed', '1.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(47, 6, 1, 7, 7, 7, 1, 2.0, 'completed', '1.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(48, 6, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(49, 7, 1, 1, 1, 1, 1, 3.0, 'completed', '2.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(50, 7, 1, 2, 2, 2, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(51, 7, 1, 3, 3, 3, 1, 3.0, 'completed', '2.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(52, 7, 1, 4, 4, 4, 1, 3.0, 'completed', '2.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(53, 7, 1, 5, 5, 5, 1, 3.0, 'completed', '2.50', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(54, 7, 1, 6, 6, 6, 1, 3.0, 'completed', '2.25', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(55, 7, 1, 7, 7, 7, 1, 2.0, 'completed', '1.75', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(56, 7, 1, 8, 8, 8, 1, 3.0, 'completed', 'P', NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(57, 3, 2, 17, 9, 9, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(58, 3, 2, 18, 10, 10, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(59, 3, 2, 19, 11, 11, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(60, 3, 2, 20, 12, 12, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(61, 3, 2, 21, 13, 13, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(62, 3, 2, 22, 14, 14, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(63, 3, 2, 23, 15, 15, 1, 2.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(64, 3, 2, 24, 16, 16, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(65, 7, 2, 17, 9, 9, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(66, 7, 2, 18, 10, 10, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(67, 7, 2, 19, 11, 11, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(68, 7, 2, 20, 12, 12, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(69, 7, 2, 21, 13, 13, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(70, 7, 2, 22, 14, 14, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(71, 7, 2, 23, 15, 15, 1, 2.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(72, 7, 2, 24, 16, 16, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-06 04:49:01', '2026-05-06 04:49:01'),
(73, 6, 2, 21, 13, 13, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(74, 6, 2, 23, 15, 15, 1, 2.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(75, 6, 2, 17, 9, 9, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(76, 6, 2, 20, 12, 12, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(77, 6, 2, 18, 10, 10, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(78, 6, 2, 19, 11, 11, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(79, 6, 2, 22, 14, 14, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03'),
(80, 6, 2, 24, 16, 16, 1, 3.0, 'enrolled', NULL, NULL, '2026-05-28 07:54:03', '2026-05-28 07:54:03');

-- --------------------------------------------------------

--
-- Table structure for table `subjects`
--

CREATE TABLE `subjects` (
  `subject_id` int(11) NOT NULL,
  `subject_code` varchar(20) NOT NULL,
  `subject_description` varchar(150) NOT NULL,
  `lab_hours` decimal(4,1) NOT NULL DEFAULT 0.0,
  `lec_hours` decimal(4,1) NOT NULL DEFAULT 0.0,
  `lab_credit` decimal(4,1) NOT NULL DEFAULT 0.0,
  `lec_credit` decimal(4,1) NOT NULL DEFAULT 0.0,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `subjects`
--

INSERT INTO `subjects` (`subject_id`, `subject_code`, `subject_description`, `lab_hours`, `lec_hours`, `lab_credit`, `lec_credit`, `status`, `created_at`) VALUES
(1, 'GNED 02', 'Ethics', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(2, 'GNED 05', 'Purposive Communication', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(3, 'GNED 11', 'Kontekswalisadong Komunikasyon sa Filipino', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(4, 'COSC 50', 'Discrete Structures I', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(5, 'DCIT 21', 'Introduction to Computing', 3.0, 2.0, 1.0, 2.0, 'active', '2026-05-06 04:49:01'),
(6, 'DCIT 22', 'Computer Programming 1', 6.0, 1.0, 2.0, 1.0, 'active', '2026-05-06 04:49:01'),
(7, 'FITT 1', 'Movement Enhancement', 0.0, 2.0, 0.0, 2.0, 'active', '2026-05-06 04:49:01'),
(8, 'NSTP 1', 'National Service Training Program 1', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(9, 'GNED 01', 'Arts Appreciation', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(10, 'GNED 06', 'Science, Technology, and Society', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(11, 'GNED 12', 'Dalumat Ng/Sa Filipino', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(12, 'GNED 03', 'Mathematics in the Modern World', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(13, 'DCIT 23', 'Computer Programming 2', 6.0, 1.0, 2.0, 1.0, 'active', '2026-05-06 04:49:01'),
(14, 'ITEC 50', 'Web Systems and Technologies 1', 3.0, 2.0, 1.0, 2.0, 'active', '2026-05-06 04:49:01'),
(15, 'FITT 2', 'Fitness Exercises', 0.0, 2.0, 0.0, 2.0, 'active', '2026-05-06 04:49:01'),
(16, 'NSTP 2', 'National Service Training Program 2', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-06 04:49:01'),
(17, 'GNED 04', 'Mga Babasahin Hinggil sa Kasaysayan ng Pilipinas', 0.0, 3.0, 0.0, 3.0, 'active', '2026-05-27 07:36:03');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `users_id` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `email` varchar(150) NOT NULL,
  `display_name` varchar(255) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `verification_token` varchar(255) DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `student_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`users_id`, `username`, `email`, `display_name`, `password`, `verification_token`, `verified_at`, `status`, `student_id`, `created_at`) VALUES
(1, 'admin1', 'admin1@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-15 11:29:04', 'active', NULL, '2026-05-06 04:49:01'),
(2, 'registrar1', 'registrar1@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-15 12:19:17', 'active', NULL, '2026-05-06 04:49:01'),
(3, 'chair.itd', 'chair.itd@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-15 12:10:39', 'active', NULL, '2026-05-06 04:49:01'),
(4, 'adviser.itd', 'adviser.itd@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-28 05:29:01', 'active', NULL, '2026-05-06 04:49:01'),
(5, 'instructor.itd', 'instructor.itd@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-28 08:02:41', 'active', NULL, '2026-05-06 04:49:01'),
(6, 'instructor.asd', 'instructor.asd@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, NULL, 'active', NULL, '2026-05-06 04:49:01'),
(7, 'cashier1', 'cashier1@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-15 14:26:06', 'active', NULL, '2026-05-06 04:49:01'),
(8, 'alice.student', 'alice.student@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-15 11:51:17', 'active', 1, '2026-05-06 04:49:01'),
(9, 'ben.student', 'ben.student@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-18 02:22:35', 'active', 2, '2026-05-06 04:49:01'),
(10, 'carla.student', 'carla.student@example.com', NULL, '$2y$12$GXWvXBSzPj2yCN4XK7DORO9OTfX5NydsWfEXkV4mUuQvBaRMs31IC', NULL, '2026-05-28 05:12:59', 'active', 3, '2026-05-06 04:49:01'),
(12, 'patriciaannmahinay', 'patriciaannmahinay101@gmail.com', NULL, '$2y$10$HNK2/dUUEOOB/vmxNrp8WOtCPalaBsNhur0gAUnEPSSWUJeKmwjRW', NULL, '2026-05-15 11:37:21', 'active', 8, '2026-05-15 11:30:48'),
(13, 'fiona', 'ptrcmahinay@gmail.com', NULL, '$2y$10$QiWybeParLlhwqwbxZDQHOYkxUhptEcffsIbPYj0tzbn/Br0efG1u', NULL, '2026-05-28 08:00:20', 'active', 6, '2026-05-28 07:58:47');

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` int(11) NOT NULL,
  `role_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`) VALUES
(1, 5),
(2, 3),
(3, 7),
(4, 6),
(5, 2),
(6, 2),
(7, 4),
(8, 1),
(9, 1),
(10, 1),
(12, 1),
(13, 1);

-- --------------------------------------------------------

--
-- Stand-in structure for view `vw_student_grades`
-- (See below for the actual view)
--
CREATE TABLE `vw_student_grades` (
`student_number` varchar(20)
,`full_name` varchar(120)
,`subject_code` varchar(20)
,`final_grade` varchar(10)
,`year_label` varchar(20)
,`semester` enum('1','2','mid')
);

-- --------------------------------------------------------

--
-- Structure for view `vw_student_grades`
--
DROP TABLE IF EXISTS `vw_student_grades`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `vw_student_grades`  AS SELECT `s`.`student_number` AS `student_number`, `s`.`full_name` AS `full_name`, `sub`.`subject_code` AS `subject_code`, `ss`.`final_grade` AS `final_grade`, `ay`.`year_label` AS `year_label`, `t`.`semester` AS `semester` FROM ((((`student_subjects` `ss` join `students` `s` on(`s`.`id` = `ss`.`student_id`)) join `subjects` `sub` on(`sub`.`subject_id` = `ss`.`subject_id`)) join `academic_terms` `t` on(`t`.`id` = `ss`.`term_id`)) join `academic_years` `ay` on(`ay`.`id` = `t`.`academic_year_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `academic_terms`
--
ALTER TABLE `academic_terms`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_terms_year` (`academic_year_id`);

--
-- Indexes for table `academic_years`
--
ALTER TABLE `academic_years`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `add_drop_requests`
--
ALTER TABLE `add_drop_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_add_drop_student` (`student_id`),
  ADD KEY `fk_add_drop_subject` (`subject_id`);

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`dept_id`),
  ADD UNIQUE KEY `department_code` (`department_code`);

--
-- Indexes for table `enrollment_audit_log`
--
ALTER TABLE `enrollment_audit_log`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_audit_request` (`request_id`);

--
-- Indexes for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_request_student` (`student_id`),
  ADD KEY `fk_request_term` (`term_id`),
  ADD KEY `fk_request_section` (`requested_section_id`),
  ADD KEY `fk_request_registrar_section` (`registrar_section_id`);

--
-- Indexes for table `enrollment_request_items`
--
ALTER TABLE `enrollment_request_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_request_items_request` (`request_id`),
  ADD KEY `fk_request_items_offering` (`offering_id`);

--
-- Indexes for table `enrollment_schedules`
--
ALTER TABLE `enrollment_schedules`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_schedule` (`term_id`,`year_level`);

--
-- Indexes for table `fee_items`
--
ALTER TABLE `fee_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fee_program` (`program_id`);

--
-- Indexes for table `grades`
--
ALTER TABLE `grades`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_grades_student` (`student_id`),
  ADD KEY `fk_grades_curriculum` (`curriculum_id`),
  ADD KEY `fk_grades_offering` (`offering_id`),
  ADD KEY `fk_grades_instructor` (`instructor_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token` (`token`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_payments_student` (`student_id`);

--
-- Indexes for table `programs`
--
ALTER TABLE `programs`
  ADD PRIMARY KEY (`programs_id`),
  ADD UNIQUE KEY `program_code` (`program_code`),
  ADD KEY `fk_program_department` (`department_id`);

--
-- Indexes for table `program_curriculum`
--
ALTER TABLE `program_curriculum`
  ADD PRIMARY KEY (`curriculum_id`),
  ADD KEY `fk_curriculum_program` (`program_id`),
  ADD KEY `fk_curriculum_subject` (`subject_id`),
  ADD KEY `fk_curriculum_prereq` (`prerequisite_subject_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`roles_id`),
  ADD UNIQUE KEY `role_name` (`role_name`);

--
-- Indexes for table `sections`
--
ALTER TABLE `sections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_section` (`program_id`,`year_level`,`section_name`),
  ADD KEY `fk_section_adviser` (`adviser_id`);

--
-- Indexes for table `section_subject_offerings`
--
ALTER TABLE `section_subject_offerings`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_offering_term` (`term_id`),
  ADD KEY `fk_offering_section` (`section_id`),
  ADD KEY `fk_offering_curriculum` (`curriculum_id`),
  ADD KEY `fk_offering_subject` (`subject_id`),
  ADD KEY `fk_offering_instructor` (`instructor_id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `setting_key` (`setting_key`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`staff_id`),
  ADD UNIQUE KEY `employee_number` (`employee_number`),
  ADD KEY `fk_staff_user` (`users_id`),
  ADD KEY `fk_staff_department` (`dept_id`);

--
-- Indexes for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_staff_notif` (`staff_id`);

--
-- Indexes for table `students`
--
ALTER TABLE `students`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `student_number` (`student_number`),
  ADD KEY `idx_student_program` (`program_id`),
  ADD KEY `idx_student_section` (`section_id`);

--
-- Indexes for table `student_notifications`
--
ALTER TABLE `student_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_notif_student` (`student_id`);

--
-- Indexes for table `student_subjects`
--
ALTER TABLE `student_subjects`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_student_subjects_student` (`student_id`),
  ADD KEY `fk_student_subjects_term` (`term_id`),
  ADD KEY `fk_student_subjects_offering` (`offering_id`),
  ADD KEY `fk_student_subjects_curriculum` (`curriculum_id`),
  ADD KEY `fk_student_subjects_subject` (`subject_id`),
  ADD KEY `fk_student_subjects_section` (`section_id`);

--
-- Indexes for table `subjects`
--
ALTER TABLE `subjects`
  ADD PRIMARY KEY (`subject_id`),
  ADD UNIQUE KEY `subject_code` (`subject_code`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`users_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `fk_users_student` (`student_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_user_roles_role` (`role_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `academic_terms`
--
ALTER TABLE `academic_terms`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `academic_years`
--
ALTER TABLE `academic_years`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `add_drop_requests`
--
ALTER TABLE `add_drop_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `dept_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `enrollment_audit_log`
--
ALTER TABLE `enrollment_audit_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=11;

--
-- AUTO_INCREMENT for table `enrollment_request_items`
--
ALTER TABLE `enrollment_request_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=77;

--
-- AUTO_INCREMENT for table `enrollment_schedules`
--
ALTER TABLE `enrollment_schedules`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fee_items`
--
ALTER TABLE `fee_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `grades`
--
ALTER TABLE `grades`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=57;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `programs`
--
ALTER TABLE `programs`
  MODIFY `programs_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `program_curriculum`
--
ALTER TABLE `program_curriculum`
  MODIFY `curriculum_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `roles_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `sections`
--
ALTER TABLE `sections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `section_subject_offerings`
--
ALTER TABLE `section_subject_offerings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=33;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `staff_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `students`
--
ALTER TABLE `students`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `student_notifications`
--
ALTER TABLE `student_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `student_subjects`
--
ALTER TABLE `student_subjects`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=81;

--
-- AUTO_INCREMENT for table `subjects`
--
ALTER TABLE `subjects`
  MODIFY `subject_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `users_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `academic_terms`
--
ALTER TABLE `academic_terms`
  ADD CONSTRAINT `fk_terms_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `add_drop_requests`
--
ALTER TABLE `add_drop_requests`
  ADD CONSTRAINT `fk_add_drop_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_add_drop_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment_audit_log`
--
ALTER TABLE `enrollment_audit_log`
  ADD CONSTRAINT `fk_audit_request` FOREIGN KEY (`request_id`) REFERENCES `enrollment_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment_requests`
--
ALTER TABLE `enrollment_requests`
  ADD CONSTRAINT `fk_request_registrar_section` FOREIGN KEY (`registrar_section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_request_section` FOREIGN KEY (`requested_section_id`) REFERENCES `sections` (`id`),
  ADD CONSTRAINT `fk_request_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_request_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment_request_items`
--
ALTER TABLE `enrollment_request_items`
  ADD CONSTRAINT `fk_request_items_offering` FOREIGN KEY (`offering_id`) REFERENCES `section_subject_offerings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_request_items_request` FOREIGN KEY (`request_id`) REFERENCES `enrollment_requests` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `enrollment_schedules`
--
ALTER TABLE `enrollment_schedules`
  ADD CONSTRAINT `fk_schedule_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fee_items`
--
ALTER TABLE `fee_items`
  ADD CONSTRAINT `fk_fee_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`programs_id`) ON DELETE CASCADE;

--
-- Constraints for table `grades`
--
ALTER TABLE `grades`
  ADD CONSTRAINT `fk_grades_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `program_curriculum` (`curriculum_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_grades_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_grades_offering` FOREIGN KEY (`offering_id`) REFERENCES `section_subject_offerings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_grades_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `programs`
--
ALTER TABLE `programs`
  ADD CONSTRAINT `fk_program_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`dept_id`);

--
-- Constraints for table `program_curriculum`
--
ALTER TABLE `program_curriculum`
  ADD CONSTRAINT `fk_curriculum_prereq` FOREIGN KEY (`prerequisite_subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_curriculum_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`programs_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_curriculum_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE;

--
-- Constraints for table `sections`
--
ALTER TABLE `sections`
  ADD CONSTRAINT `fk_section_adviser` FOREIGN KEY (`adviser_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_section_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`programs_id`) ON DELETE CASCADE;

--
-- Constraints for table `section_subject_offerings`
--
ALTER TABLE `section_subject_offerings`
  ADD CONSTRAINT `fk_offering_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `program_curriculum` (`curriculum_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offering_instructor` FOREIGN KEY (`instructor_id`) REFERENCES `staff` (`staff_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_offering_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offering_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_offering_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `staff`
--
ALTER TABLE `staff`
  ADD CONSTRAINT `fk_staff_department` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_staff_user` FOREIGN KEY (`users_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE;

--
-- Constraints for table `staff_notifications`
--
ALTER TABLE `staff_notifications`
  ADD CONSTRAINT `fk_staff_notif` FOREIGN KEY (`staff_id`) REFERENCES `staff` (`staff_id`) ON DELETE CASCADE;

--
-- Constraints for table `students`
--
ALTER TABLE `students`
  ADD CONSTRAINT `fk_students_program` FOREIGN KEY (`program_id`) REFERENCES `programs` (`programs_id`),
  ADD CONSTRAINT `fk_students_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `student_notifications`
--
ALTER TABLE `student_notifications`
  ADD CONSTRAINT `fk_notif_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `student_subjects`
--
ALTER TABLE `student_subjects`
  ADD CONSTRAINT `fk_student_subjects_curriculum` FOREIGN KEY (`curriculum_id`) REFERENCES `program_curriculum` (`curriculum_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_subjects_offering` FOREIGN KEY (`offering_id`) REFERENCES `section_subject_offerings` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_subjects_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_subjects_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_subjects_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_student_subjects_term` FOREIGN KEY (`term_id`) REFERENCES `academic_terms` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_user_roles_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`roles_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_user_roles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`users_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
