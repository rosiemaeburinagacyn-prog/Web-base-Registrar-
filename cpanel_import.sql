-- Web-Based Registrar System cPanel/phpMyAdmin MySQL import
-- Import this file into an empty MySQL/MariaDB database using phpMyAdmin.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

DROP TABLE IF EXISTS `failed_jobs`;
DROP TABLE IF EXISTS `job_batches`;
DROP TABLE IF EXISTS `jobs`;
DROP TABLE IF EXISTS `cache_locks`;
DROP TABLE IF EXISTS `cache`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `document_request_files`;
DROP TABLE IF EXISTS `document_request_items`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `document_requests`;
DROP TABLE IF EXISTS `students`;
DROP TABLE IF EXISTS `sessions`;
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `academic_years`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `migrations`;

CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `student_number` varchar(50) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `course` varchar(255) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `school_email` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'student',
  `account_status` varchar(30) NOT NULL DEFAULT 'active',
  `verification_status` varchar(30) NOT NULL DEFAULT 'unsubmitted',
  `school_id_path` varchar(255) DEFAULT NULL,
  `selfie_id_path` varchar(255) DEFAULT NULL,
  `verification_submitted_at` timestamp NULL DEFAULT NULL,
  `verification_reviewed_at` timestamp NULL DEFAULT NULL,
  `verification_reviewed_by` bigint unsigned DEFAULT NULL,
  `verification_note` text DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `password_reset_otp_hash` varchar(255) DEFAULT NULL,
  `password_reset_otp_expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  UNIQUE KEY `users_student_number_unique` (`student_number`),
  UNIQUE KEY `users_school_email_unique` (`school_email`),
  KEY `users_role_index` (`role`),
  KEY `users_account_status_index` (`account_status`),
  KEY `users_verification_status_index` (`verification_status`),
  KEY `users_verification_reviewed_by_foreign` (`verification_reviewed_by`),
  CONSTRAINT `users_verification_reviewed_by_foreign` FOREIGN KEY (`verification_reviewed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `students` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `student_number` varchar(50) NOT NULL,
  `full_name` varchar(255) NOT NULL,
  `course` varchar(255) DEFAULT NULL,
  `year_level` varchar(30) DEFAULT NULL,
  `school_email` varchar(255) NOT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `account_status` varchar(30) NOT NULL DEFAULT 'active',
  `verification_status` varchar(30) NOT NULL DEFAULT 'unsubmitted',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `students_user_id_unique` (`user_id`),
  UNIQUE KEY `students_student_number_unique` (`student_number`),
  UNIQUE KEY `students_school_email_unique` (`school_email`),
  KEY `students_account_status_index` (`account_status`),
  KEY `students_verification_status_index` (`verification_status`),
  CONSTRAINT `students_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` int NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` tinyint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` text NOT NULL,
  `queue` text NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `academic_years` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `academic_years_name_unique` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned DEFAULT NULL,
  `request_reference` varchar(255) DEFAULT NULL,
  `student_name` varchar(255) NOT NULL,
  `student_id` varchar(50) NOT NULL,
  `document_type` varchar(255) NOT NULL,
  `amount` decimal(10,2) DEFAULT NULL,
  `academic_year_id` bigint unsigned DEFAULT NULL,
  `academic_year` varchar(255) DEFAULT NULL,
  `semester` varchar(30) DEFAULT NULL,
  `payment_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `request_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `uploaded_file` varchar(255) DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `document_requests_request_reference_unique` (`request_reference`),
  KEY `document_requests_user_id_foreign` (`user_id`),
  KEY `document_requests_academic_year_id_foreign` (`academic_year_id`),
  KEY `document_requests_student_id_index` (`student_id`),
  KEY `document_requests_payment_status_index` (`payment_status`),
  KEY `document_requests_request_status_index` (`request_status`),
  CONSTRAINT `document_requests_academic_year_id_foreign` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE SET NULL,
  CONSTRAINT `document_requests_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_request_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_request_id` bigint unsigned NOT NULL,
  `document_type` varchar(255) NOT NULL,
  `quantity` int NOT NULL DEFAULT 1,
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_request_items_document_request_id_foreign` (`document_request_id`),
  CONSTRAINT `document_request_items_document_request_id_foreign` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `payments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `receipt_number` varchar(50) DEFAULT NULL,
  `request_id` bigint unsigned DEFAULT NULL,
  `student_id` bigint unsigned DEFAULT NULL,
  `document_request_id` bigint unsigned NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `reference` varchar(255) NOT NULL,
  `gateway_transaction_id` varchar(255) DEFAULT NULL,
  `checkout_session_id` varchar(255) DEFAULT NULL,
  `checkout_url` text DEFAULT NULL,
  `provider` varchar(255) NOT NULL DEFAULT 'paymongo',
  `amount` decimal(10,2) NOT NULL,
  `payment_method` varchar(255) NOT NULL DEFAULT 'GCash',
  `reference_number` varchar(255) DEFAULT NULL,
  `proof_of_payment` varchar(255) DEFAULT NULL,
  `payment_status` varchar(40) NOT NULL DEFAULT 'Pending',
  `verified_by` bigint unsigned DEFAULT NULL,
  `cashier_id` bigint unsigned DEFAULT NULL,
  `verified_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` text DEFAULT NULL,
  `official_receipt_path` varchar(255) DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `status` varchar(30) NOT NULL DEFAULT 'Pending',
  `paid_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `gateway_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `payments_reference_unique` (`reference`),
  UNIQUE KEY `payments_receipt_number_unique` (`receipt_number`),
  KEY `payments_request_id_foreign` (`request_id`),
  KEY `payments_student_id_foreign` (`student_id`),
  KEY `payments_document_request_id_foreign` (`document_request_id`),
  KEY `payments_user_id_foreign` (`user_id`),
  KEY `payments_verified_by_foreign` (`verified_by`),
  KEY `payments_cashier_id_foreign` (`cashier_id`),
  KEY `payments_gateway_transaction_id_index` (`gateway_transaction_id`),
  KEY `payments_checkout_session_id_index` (`checkout_session_id`),
  KEY `payments_payment_status_index` (`payment_status`),
  KEY `payments_status_index` (`status`),
  KEY `payments_paid_at_index` (`paid_at`),
  CONSTRAINT `payments_cashier_id_foreign` FOREIGN KEY (`cashier_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_document_request_id_foreign` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `payments_student_id_foreign` FOREIGN KEY (`student_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `payments_verified_by_foreign` FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `document_request_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `document_request_id` bigint unsigned NOT NULL,
  `uploaded_by` bigint unsigned DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint unsigned DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `document_request_files_document_request_id_foreign` (`document_request_id`),
  KEY `document_request_files_uploaded_by_foreign` (`uploaded_by`),
  CONSTRAINT `document_request_files_document_request_id_foreign` FOREIGN KEY (`document_request_id`) REFERENCES `document_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `document_request_files_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `notifications` (
  `id` char(36) NOT NULL,
  `type` varchar(255) NOT NULL,
  `notifiable_type` varchar(255) NOT NULL,
  `notifiable_id` bigint unsigned NOT NULL,
  `data` json NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `notifications_notifiable_type_notifiable_id_index` (`notifiable_type`, `notifiable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES
(1, '0001_01_01_000000_create_users_table', 1),
(2, '0001_01_01_000001_create_cache_table', 1),
(3, '0001_01_01_000002_create_jobs_table', 1),
(4, '2026_06_03_144056_create_document_requests_table', 1),
(5, '2026_06_03_153000_add_user_id_to_document_requests_table', 2),
(6, '2026_06_03_235000_repair_registrar_schema', 3),
(7, '2026_06_03_235100_create_payments_table', 3),
(8, '2026_06_04_001000_create_academic_years_table', 4),
(9, '2026_06_04_001100_add_academic_fields_to_document_requests_table', 4),
(10, '2026_06_04_001200_add_gcash_verification_fields_to_payments_table', 4),
(11, '2026_06_04_001300_create_notifications_table', 4),
(12, '2026_06_04_001400_backfill_manual_payment_workflow_data', 5),
(13, '2026_06_05_000000_add_receipt_and_quantity_support', 6),
(14, '2026_06_18_000000_backfill_document_request_items', 6),
(15, '2026_06_18_010000_create_document_request_files_table', 7),
(16, '2026_06_23_000000_secure_student_accounts_and_gateway_payments', 8);

-- Password for all demo users is: password
INSERT INTO `users` (`id`, `student_number`, `name`, `email`, `course`, `year_level`, `school_email`, `email_verified_at`, `password`, `role`, `account_status`, `verification_status`, `created_at`, `updated_at`) VALUES
(1, NULL, 'Registrar Admin', 'admin@example.com', NULL, NULL, NULL, NULL, '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'admin', 'active', 'approved', NOW(), NOW()),
(2, '23-1234', 'Sample Student', 'student@example.com', 'BS Information Technology', '3rd Year', 'student@isu.edu.ph', NULL, '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'student', 'active', 'approved', NOW(), NOW()),
(3, NULL, 'Registrar Staff', 'registrar@example.com', NULL, NULL, NULL, NULL, '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'registrar', 'active', 'approved', NOW(), NOW()),
(4, NULL, 'Cashier Staff', 'cashier@example.com', NULL, NULL, NULL, NULL, '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'cashier', 'active', 'approved', NOW(), NOW()),
(5, '23-5678', 'Mart Lanceley Babaran', 'martlanceley@gmail.com', 'BS Information Technology', '3rd Year', 'martlanceley@isu.edu.ph', NULL, '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'student', 'active', 'approved', NOW(), NOW());

INSERT INTO `students` (`id`, `user_id`, `student_number`, `full_name`, `course`, `year_level`, `school_email`, `password_hash`, `account_status`, `verification_status`, `created_at`, `updated_at`) VALUES
(1, 2, '23-1234', 'Sample Student', 'BS Information Technology', '3rd Year', 'student@isu.edu.ph', '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'active', 'approved', NOW(), NOW()),
(2, 5, '23-5678', 'Mart Lanceley Babaran', 'BS Information Technology', '3rd Year', 'martlanceley@isu.edu.ph', '$2y$12$jSuEE03jIJqHF4C8UnXUnOvhJI4dAY.jwznwEBtLZbTc6OI2lixia', 'active', 'approved', NOW(), NOW());

INSERT INTO `academic_years` (`id`, `name`, `is_active`, `created_at`, `updated_at`) VALUES
(1, '2023-2024', 1, NOW(), NOW()),
(2, '2024-2025', 1, NOW(), NOW()),
(3, '2025-2026', 1, NOW(), NOW());

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
