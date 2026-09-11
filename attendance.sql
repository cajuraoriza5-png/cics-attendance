-- =====================================================
-- CICS Attendance System - Complete Database Schema
-- Database Name: attendance
-- Generated from codebase reverse-engineering
-- =====================================================

-- Create and use the database
CREATE DATABASE IF NOT EXISTS `attendance`;
USE `attendance`;

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- =====================================================
-- TABLE: admin
-- Used by: ADMIN_LOGIN.PHP
-- Stores admin accounts for the admin login portal
-- =====================================================
CREATE TABLE IF NOT EXISTS `admin` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(20) DEFAULT 'admin',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: users
-- Used by: REGISTER.PHP, STUDENT_LOGIN.PHP, ADMIN_REGISTER.php,
--          student_dashboard.php, admin_dashboard.php, reports.php,
--          scan_attendance.php, mobile_scan.php, etc.
-- Stores student and admin user accounts
-- =====================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `student_id` VARCHAR(20) DEFAULT NULL UNIQUE,
    `first_name` VARCHAR(50) NOT NULL,
    `last_name` VARCHAR(50) NOT NULL,
    `middle_name` VARCHAR(50) DEFAULT NULL,
    `age` INT DEFAULT NULL,
    `gender` VARCHAR(10) DEFAULT 'Other',
    `email` VARCHAR(100) DEFAULT NULL UNIQUE,
    `phone` VARCHAR(15) DEFAULT NULL,
    `course` VARCHAR(50) DEFAULT NULL,
    `year_level` VARCHAR(20) DEFAULT NULL,
    `section` VARCHAR(20) DEFAULT NULL,
    `face_registered` TINYINT(1) DEFAULT 0,
    `total_penalty` DECIMAL(10,2) DEFAULT 0.00,
    `role` ENUM('admin','student') DEFAULT 'student',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: events
-- Used by: create_event_new.php, edit_event.php, manage_events.php,
--          admin_dashboard.php, student_dashboard.php, scan_attendance.php,
--          mobile_scan.php, reports.php, etc.
-- Stores event/schedule information
-- =====================================================
CREATE TABLE IF NOT EXISTS `events` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_name` VARCHAR(100) NOT NULL,
    `event_description` TEXT DEFAULT NULL,
    `venue` VARCHAR(255) DEFAULT NULL,
    `event_banner` VARCHAR(255) DEFAULT NULL,
    `event_type` ENUM('Regular','Special','Exam','Meeting','Activity','Morning Only','Afternoon Only') DEFAULT 'Regular',
    `start_date` DATE DEFAULT NULL,
    `end_date` DATE DEFAULT NULL,
    `event_date` DATE NOT NULL,
    `course` VARCHAR(255) DEFAULT 'ALL',
    `qr_code` VARCHAR(255) DEFAULT NULL,
    `mobile_scan_link` VARCHAR(255) DEFAULT NULL,
    `event_history` TEXT DEFAULT NULL,
    `late_penalty` DECIMAL(10,2) DEFAULT 25.00,
    `absent_penalty` DECIMAL(10,2) DEFAULT 50.00,
    `morning_login_start` TIME DEFAULT '07:00:00',
    `morning_login_end` TIME DEFAULT '07:30:00',
    `morning_logout_start` TIME DEFAULT '11:30:00',
    `morning_logout_end` TIME DEFAULT '12:00:00',
    `afternoon_login_start` TIME DEFAULT '13:00:00',
    `afternoon_login_end` TIME DEFAULT '13:30:00',
    `afternoon_logout_start` TIME DEFAULT '16:30:00',
    `afternoon_logout_end` TIME DEFAULT '17:00:00',
    `morning_start` TIME DEFAULT '08:00:00',
    `morning_end` TIME DEFAULT '08:30:00',
    `afternoon_start` TIME DEFAULT '13:00:00',
    `afternoon_end` TIME DEFAULT '13:30:00',
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: attendance
-- Used by: scan_attendance.php, mobile_scan.php, admin_dashboard.php,
--          student_dashboard.php, reports.php, auto_absent.php, etc.
-- Stores daily attendance records per student per event
-- =====================================================
CREATE TABLE IF NOT EXISTS `attendance` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `event_id` INT NOT NULL,
    `date` DATE NOT NULL,
    `morning_in` TIME DEFAULT NULL,
    `morning_out` TIME DEFAULT NULL,
    `morning_status` ENUM('Present','Late','Absent') DEFAULT 'Absent',
    `afternoon_in` TIME DEFAULT NULL,
    `afternoon_out` TIME DEFAULT NULL,
    `afternoon_status` ENUM('Present','Late','Absent') DEFAULT 'Absent',
    `penalty` DECIMAL(10,2) DEFAULT 0.00,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_attendance` (`student_id`, `event_id`, `date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: face_data
-- Used by: face_enroll.php, save_face.php, student_dashboard.php,
--          admin_dashboard.php, database_migration.php
-- Stores face registration images for students
-- =====================================================
CREATE TABLE IF NOT EXISTS `face_data` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `face_image` VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: payments
-- Used by: student_payment.php, admin_payments.php,
--          update_payment_system.php
-- Stores payment submissions and admin confirmations
-- =====================================================
CREATE TABLE IF NOT EXISTS `payments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `student_id` INT NOT NULL,
    `amount` DECIMAL(10,2) NOT NULL,
    `payment_method` ENUM('GCash','Cash') NOT NULL,
    `gcash_reference` VARCHAR(50) DEFAULT NULL,
    `receipt_image` VARCHAR(255) DEFAULT NULL,
    `payment_date` DATE NOT NULL,
    `status` ENUM('Pending','Confirmed','Rejected') DEFAULT 'Pending',
    `admin_notes` TEXT DEFAULT NULL,
    `confirmed_by` INT DEFAULT NULL,
    `confirmed_at` TIMESTAMP NULL DEFAULT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`student_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`confirmed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: event_history_log
-- Used by: edit_event.php, create_event_new.php,
--          update_events_table.php
-- Tracks all changes made to events (audit log)
-- =====================================================
CREATE TABLE IF NOT EXISTS `event_history_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `admin_id` INT NOT NULL,
    `action_type` VARCHAR(50) NOT NULL,
    `old_values` TEXT DEFAULT NULL,
    `new_values` TEXT DEFAULT NULL,
    `change_description` TEXT DEFAULT NULL,
    `change_time` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`admin_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    INDEX `idx_event_id` (`event_id`),
    INDEX `idx_admin_id` (`admin_id`),
    INDEX `idx_change_time` (`change_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- =====================================================
-- TABLE: officer_access_log
-- Used by: mobile_scan.php, create_officer_log_table.php
-- Tracks officer access to mobile scanning events
-- =====================================================
CREATE TABLE IF NOT EXISTS `officer_access_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `event_id` INT NOT NULL,
    `officer_id` VARCHAR(255) NOT NULL,
    `access_time` DATETIME NOT NULL,
    `device_info` TEXT DEFAULT NULL,
    `scans_count` INT DEFAULT 1,
    `last_scan_time` DATETIME DEFAULT NULL,
    FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE,
    UNIQUE KEY `unique_officer_event` (`event_id`, `officer_id`),
    INDEX `idx_event_id` (`event_id`),
    INDEX `idx_officer_id` (`officer_id`),
    INDEX `idx_access_time` (`access_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================
-- DEFAULT DATA: Admin Account
-- =====================================================

-- Default admin in `admin` table (used by ADMIN_LOGIN.PHP)
INSERT INTO `admin` (`username`, `password`, `full_name`, `role`) VALUES
('admin', '$2y$10$UTL4dw86wTG9X1/aV/Vc2exutp6yOtFSnOjhN8Npb7nngUjlDBLpC', 'System Administrator', 'admin');

-- Default admin in `users` table (used by ADMIN_REGISTER.php flow)
INSERT INTO `users` (`username`, `password`, `student_id`, `first_name`, `last_name`, `age`, `gender`, `email`, `course`, `year_level`, `section`, `role`) VALUES
('admin', '$2y$10$UTL4dw86wTG9X1/aV/Vc2exutp6yOtFSnOjhN8Npb7nngUjlDBLpC', 'ADMIN-0001', 'System', 'Administrator', 30, 'Other', 'admin@cics.edu', 'N/A', 'N/A', 'N/A', 'admin');

-- =====================================================
-- DEFAULT ADMIN CREDENTIALS:
--   Username: admin
--   Password: admin123
--
-- You can also create new admin accounts via
-- ADMIN_REGISTER.php (secret key: CICS2026)
-- =====================================================
