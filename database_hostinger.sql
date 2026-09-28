-- =====================================================================
-- Script de Base de Datos para Hostinger (phpMyAdmin)
-- Proyecto: Sistema Gestión Escolar (SAAS ORMS RESULT)
-- Dominio: sistemagestionescolar.ceie.website
-- Base de Datos Destino: u612988177_sisgescolar
-- Usuario MySQL Destino: u612988177_ns5gescolar
-- =====================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";

-- ---------------------------------------------------------------------
-- 1. Tabla: plans
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `plans`;
CREATE TABLE `plans` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(80) NOT NULL,
  `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `max_students` INT NOT NULL DEFAULT 0,
  `max_teachers` INT NOT NULL DEFAULT 0,
  `max_branches` INT NOT NULL DEFAULT 1,
  `features` TEXT DEFAULT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_plan_name` (`name`),
  INDEX `idx_plan_sort` (`sort_order`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2. Tabla: schools
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `schools`;
CREATE TABLE `schools` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(150) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `address` TEXT DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `status` ENUM('Active','Suspended','Trial','Cancelled') NOT NULL DEFAULT 'Active',
  `plan_id` INT DEFAULT NULL,
  `billing_currency` VARCHAR(10) DEFAULT NULL,
  `owner_user_id` INT DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_code` (`code`),
  INDEX `idx_school_status` (`status`),
  CONSTRAINT `fk_schools_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 3. Tabla: branches
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `branches`;
CREATE TABLE `branches` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `address` VARCHAR(255) DEFAULT NULL,
  `city` VARCHAR(80) DEFAULT NULL,
  `phone` VARCHAR(40) DEFAULT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `head_user_id` INT DEFAULT NULL,
  `opened_on` DATE DEFAULT NULL,
  `capacity` INT NOT NULL DEFAULT 0,
  `notes` VARCHAR(255) DEFAULT NULL,
  `is_main` TINYINT(1) NOT NULL DEFAULT 0,
  `status` ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_branch_code` (`school_id`, `code`),
  INDEX `idx_branch_school` (`school_id`, `is_main`),
  CONSTRAINT `fk_branches_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 4. Tabla: plan_prices
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `plan_prices`;
CREATE TABLE `plan_prices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `plan_id` INT NOT NULL,
  `currency` VARCHAR(10) NOT NULL,
  `price_monthly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `price_yearly` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_plan_ccy` (`plan_id`, `currency`),
  CONSTRAINT `fk_planprice_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 5. Tabla: school_subscriptions
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `school_subscriptions`;
CREATE TABLE `school_subscriptions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL,
  `plan_id` INT DEFAULT NULL,
  `starts_at` DATE NOT NULL,
  `ends_at` DATE NOT NULL,
  `status` ENUM('Active','Expired','Cancelled') NOT NULL DEFAULT 'Active',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_sub_school` (`school_id`, `ends_at`),
  CONSTRAINT `fk_sub_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sub_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 6. Tabla: subscription_payments
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `subscription_payments`;
CREATE TABLE `subscription_payments` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL,
  `subscription_id` INT DEFAULT NULL,
  `invoice_id` INT DEFAULT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `paid_on` DATE NOT NULL,
  `method` VARCHAR(40) DEFAULT NULL,
  `reference` VARCHAR(100) DEFAULT NULL,
  `note` VARCHAR(255) DEFAULT NULL,
  `recorded_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_pay_school` (`school_id`, `paid_on`),
  CONSTRAINT `fk_pay_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pay_sub` FOREIGN KEY (`subscription_id`) REFERENCES `school_subscriptions` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 7. Tabla: users
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `full_name` VARCHAR(100) DEFAULT NULL,
  `password` VARCHAR(255) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `phone` VARCHAR(20) DEFAULT NULL,
  `email_verified` TINYINT(1) DEFAULT 1,
  `profile_image` VARCHAR(255) DEFAULT NULL,
  `theme_primary` VARCHAR(20) DEFAULT '#111827',
  `theme_secondary` VARCHAR(20) DEFAULT '#374151',
  `theme_accent` VARCHAR(20) DEFAULT '#34D399',
  `theme_mode` VARCHAR(10) DEFAULT 'light',
  `google_id` VARCHAR(255) DEFAULT NULL,
  `role` VARCHAR(50) NOT NULL DEFAULT 'Student',
  `school_id` INT NOT NULL DEFAULT 0,
  `branch_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1) NOT NULL DEFAULT 0,
  `last_login_at` DATETIME DEFAULT NULL,
  `last_login_ip` VARCHAR(45) DEFAULT NULL,
  `password_changed_at` DATETIME DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_school_username` (`school_id`, `username`),
  INDEX `idx_role` (`role`),
  INDEX `idx_users_school` (`school_id`, `role`),
  INDEX `idx_branch` (`branch_id`),
  INDEX `idx_google_id` (`google_id`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 8. Tabla: roles
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `school_id` INT NOT NULL DEFAULT 0,
  `role_key` VARCHAR(50) NOT NULL,
  `label` VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `color` VARCHAR(10) DEFAULT '#0074D9',
  `sort_order` INT(11) DEFAULT 0,
  `is_super` TINYINT(1) DEFAULT 0,
  `hidden_signup` TINYINT(1) DEFAULT 0,
  `permissions` LONGTEXT NOT NULL,
  `created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `updated_by` INT DEFAULT NULL,
  PRIMARY KEY (`school_id`, `role_key`),
  INDEX `idx_roles_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 9. Tabla: system_settings
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `system_settings`;
CREATE TABLE `system_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 1,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NOT NULL,
  `setting_group` VARCHAR(50) DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_school_setting` (`school_id`, `setting_key`),
  INDEX `idx_setting_key` (`setting_key`),
  INDEX `idx_system_settings_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 10. Tabla: activity_logs
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activity_logs`;
CREATE TABLE `activity_logs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 1,
  `branch_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL,
  `username` VARCHAR(50) NOT NULL,
  `role` VARCHAR(50) DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `entity_type` VARCHAR(50) DEFAULT NULL,
  `entity_id` INT DEFAULT NULL,
  `details` TEXT,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `timestamp` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_username` (`username`),
  INDEX `idx_action` (`action`),
  INDEX `idx_timestamp` (`timestamp`),
  INDEX `idx_activity_logs_school` (`school_id`),
  INDEX `idx_log_branch` (`school_id`, `branch_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 11. Tabla: email_verifications
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `email_verifications`;
CREATE TABLE `email_verifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 0,
  `user_id` INT NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_email_verifications_school` (`email`, `school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 12. Tabla: password_resets
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `password_resets`;
CREATE TABLE `password_resets` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 0,
  `email` VARCHAR(100) NOT NULL,
  `otp_code` VARCHAR(6) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `used` TINYINT(1) DEFAULT 0,
  PRIMARY KEY (`id`),
  INDEX `idx_email` (`email`),
  INDEX `idx_password_resets_school` (`email`, `school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 13. Tabla: remember_tokens
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE `remember_tokens` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  `last_used_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_token` (`token_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 14. Tabla: login_attempts
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 0,
  `username` VARCHAR(50) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` VARCHAR(255) DEFAULT NULL,
  `success` TINYINT(1) NOT NULL DEFAULT 0,
  `attempt_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_username` (`username`),
  INDEX `idx_attempt_time` (`attempt_time`),
  INDEX `idx_attempt_school` (`username`, `school_id`, `attempt_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 15. Tabla: user_sessions
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT NOT NULL,
  `session_id` VARCHAR(128) NOT NULL,
  `ip_address` VARCHAR(45) NOT NULL,
  `user_agent` TEXT,
  `last_activity` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `force_logout` TINYINT(1) DEFAULT 0,
  `logged_out_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_session_id` (`session_id`),
  INDEX `idx_user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 16. Tabla: notifications
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 1,
  `user_id` INT DEFAULT NULL,
  `title` VARCHAR(255) NOT NULL,
  `message` TEXT NOT NULL,
  `type` ENUM('info','success','warning','danger') DEFAULT 'info',
  `is_read` TINYINT(1) DEFAULT 0,
  `link` VARCHAR(255) DEFAULT NULL,
  `read_at` DATETIME DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_user_id` (`user_id`),
  INDEX `idx_user_read` (`user_id`, `is_read`),
  INDEX `idx_notifications_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 17. Tabla: push_subscriptions
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `push_subscriptions`;
CREATE TABLE `push_subscriptions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `user_id` INT DEFAULT NULL,
  `endpoint` VARCHAR(500) NOT NULL,
  `p256dh` VARCHAR(255) NOT NULL,
  `auth` VARCHAR(255) NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `failed_count` INT NOT NULL DEFAULT 0,
  `last_used_at` DATETIME DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_endpoint` (`endpoint`(191)),
  INDEX `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 18. Tabla: academic_years
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `academic_years`;
CREATE TABLE `academic_years` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `name` VARCHAR(20) NOT NULL,
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `is_current` TINYINT(1) NOT NULL DEFAULT 0,
  `is_locked` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_year` (`school_id`, `name`),
  INDEX `idx_year_current` (`is_current`),
  INDEX `idx_academic_years_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 19. Tabla: grading_sets
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `grading_sets`;
CREATE TABLE `grading_sets` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `is_default` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_gset` (`school_id`, `name`),
  INDEX `idx_grading_sets_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 20. Tabla: grading_scheme
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `grading_scheme`;
CREATE TABLE `grading_scheme` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `set_id` INT NOT NULL DEFAULT 1,
  `grade` VARCHAR(5) NOT NULL,
  `min_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `max_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `grade_point` DECIMAL(3,1) NOT NULL DEFAULT 0.0,
  `remarks` VARCHAR(100) DEFAULT NULL,
  `interpretation` VARCHAR(255) DEFAULT NULL,
  `color` VARCHAR(10) DEFAULT NULL,
  `is_fail` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  INDEX `idx_grade_sort` (`sort_order`),
  INDEX `idx_grading_scheme_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 21. Tabla: assessment_schemes
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `assessment_schemes`;
CREATE TABLE `assessment_schemes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `name` VARCHAR(80) NOT NULL,
  `description` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_ascheme` (`school_id`, `name`),
  INDEX `idx_assessment_schemes_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 22. Tabla: assessment_components
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `assessment_components`;
CREATE TABLE `assessment_components` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `scheme_id` INT NOT NULL,
  `name` VARCHAR(60) NOT NULL,
  `type` ENUM('CA','Exam') NOT NULL DEFAULT 'CA',
  `weightage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `max_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  `sort_order` INT NOT NULL DEFAULT 0,
  UNIQUE KEY `uniq_comp_name` (`scheme_id`, `name`),
  INDEX `idx_assessment_components_school` (`school_id`),
  CONSTRAINT `fk_acomp_scheme` FOREIGN KEY (`scheme_id`) REFERENCES `assessment_schemes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 23. Tabla: classes
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `classes`;
CREATE TABLE `classes` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `branch_id` INT DEFAULT NULL,
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `numeric_level` INT DEFAULT NULL,
  `next_class_id` INT DEFAULT NULL,
  `result_template` VARCHAR(20) DEFAULT NULL,
  `show_position` TINYINT(1) NOT NULL DEFAULT 1,
  `grading_set_id` INT DEFAULT NULL,
  `assessment_scheme_id` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_class` (`school_id`, `branch_id`, `name`),
  INDEX `idx_class_active` (`is_active`),
  INDEX `idx_classes_school` (`school_id`),
  INDEX `idx_classes_branch` (`branch_id`),
  CONSTRAINT `fk_classes_next` FOREIGN KEY (`next_class_id`) REFERENCES `classes` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 24. Tabla: subjects
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `subjects`;
CREATE TABLE `subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `name` VARCHAR(100) NOT NULL,
  `code` VARCHAR(20) NOT NULL,
  `short_name` VARCHAR(20) DEFAULT NULL,
  `subject_type` ENUM('Core','Elective','Optional') NOT NULL DEFAULT 'Core',
  `include_in_total` TINYINT(1) NOT NULL DEFAULT 1,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_subject` (`school_id`, `code`),
  INDEX `idx_subject_active` (`is_active`),
  INDEX `idx_subjects_school` (`school_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 25. Tabla: exam_terms
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `exam_terms`;
CREATE TABLE `exam_terms` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `academic_year_id` INT NOT NULL,
  `name` VARCHAR(50) NOT NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  `status` ENUM('Upcoming','Open','Closed') NOT NULL DEFAULT 'Upcoming',
  `start_date` DATE DEFAULT NULL,
  `end_date` DATE DEFAULT NULL,
  `result_date` DATE DEFAULT NULL,
  `weightage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_term` (`academic_year_id`, `name`),
  INDEX `idx_term_status` (`status`),
  INDEX `idx_exam_terms_school` (`school_id`),
  CONSTRAINT `fk_terms_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 26. Tabla: sections
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `sections`;
CREATE TABLE `sections` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `branch_id` INT DEFAULT NULL,
  `class_id` INT NOT NULL,
  `name` VARCHAR(20) NOT NULL,
  `capacity` INT DEFAULT NULL,
  `class_teacher_id` INT DEFAULT NULL,
  `room_no` VARCHAR(20) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_section` (`class_id`, `name`),
  INDEX `idx_section_active` (`is_active`),
  INDEX `idx_sections_school` (`school_id`),
  INDEX `idx_sections_branch` (`branch_id`),
  CONSTRAINT `fk_sections_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 27. Tabla: class_subjects
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `class_subjects`;
CREATE TABLE `class_subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `total_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  `passing_marks` DECIMAL(6,2) NOT NULL DEFAULT 33.00,
  `theory_marks` DECIMAL(6,2) DEFAULT NULL,
  `practical_marks` DECIMAL(6,2) DEFAULT NULL,
  `include_in_total` TINYINT(1) NOT NULL DEFAULT 1,
  `is_optional` TINYINT(1) NOT NULL DEFAULT 0,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_class_subject` (`class_id`, `subject_id`),
  INDEX `idx_class_subjects_school` (`school_id`),
  CONSTRAINT `fk_cs_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_cs_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 28. Tabla: teachers
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `teachers`;
CREATE TABLE `teachers` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `branch_id` INT DEFAULT NULL,
  `user_id` INT NOT NULL,
  `employee_no` VARCHAR(20) NOT NULL,
  `designation` VARCHAR(100) DEFAULT NULL,
  `qualification` VARCHAR(100) DEFAULT NULL,
  `national_id` VARCHAR(30) DEFAULT NULL,
  `emergency_contact` VARCHAR(20) DEFAULT NULL,
  `joining_date` DATE DEFAULT NULL,
  `leaving_date` DATE DEFAULT NULL,
  `status` ENUM('Active','Inactive','Resigned') NOT NULL DEFAULT 'Active',
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_employee` (`school_id`, `employee_no`),
  UNIQUE KEY `uniq_teacher_user` (`user_id`),
  INDEX `idx_teacher_status` (`status`),
  INDEX `idx_teachers_school` (`school_id`),
  INDEX `idx_teachers_branch` (`branch_id`),
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 29. Tabla: students
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `students`;
CREATE TABLE `students` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `branch_id` INT DEFAULT NULL,
  `user_id` INT NOT NULL,
  `admission_no` VARCHAR(20) NOT NULL,
  `roll_no` VARCHAR(20) DEFAULT NULL,
  `class_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `father_name` VARCHAR(100) DEFAULT NULL,
  `mother_name` VARCHAR(100) DEFAULT NULL,
  `guardian_name` VARCHAR(100) DEFAULT NULL,
  `guardian_email` VARCHAR(100) DEFAULT NULL,
  `guardian_phone` VARCHAR(20) DEFAULT NULL,
  `national_id` VARCHAR(30) DEFAULT NULL,
  `dob` DATE DEFAULT NULL,
  `gender` ENUM('Male','Female','Other') DEFAULT NULL,
  `blood_group` VARCHAR(5) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `previous_school` VARCHAR(150) DEFAULT NULL,
  `admission_date` DATE DEFAULT NULL,
  `date_of_leaving` DATE DEFAULT NULL,
  `leaving_reason` VARCHAR(255) DEFAULT NULL,
  `status` ENUM('Active','Inactive','Alumni','Suspended') NOT NULL DEFAULT 'Active',
  `fee_hold` TINYINT(1) NOT NULL DEFAULT 0,
  `fee_hold_note` VARCHAR(150) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `created_by` INT DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_school_admission` (`school_id`, `admission_no`),
  UNIQUE KEY `uniq_student_user` (`user_id`),
  INDEX `idx_student_class` (`class_id`, `section_id`),
  INDEX `idx_student_status` (`status`),
  INDEX `idx_students_school` (`school_id`),
  INDEX `idx_students_branch` (`branch_id`),
  CONSTRAINT `fk_students_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_students_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_students_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_students_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 30. Tabla: teacher_subjects
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `teacher_subjects`;
CREATE TABLE `teacher_subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `teacher_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `assigned_by` INT DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_assignment` (`teacher_id`, `class_id`, `section_id`, `subject_id`, `academic_year_id`),
  INDEX `idx_ts_teacher` (`teacher_id`),
  INDEX `idx_ts_section` (`section_id`, `subject_id`),
  INDEX `idx_teacher_subjects_school` (`school_id`),
  CONSTRAINT `fk_ts_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ts_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 31. Tabla: marks
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `marks`;
CREATE TABLE `marks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `assessment_scheme_id` INT DEFAULT NULL,
  `marks_obtained` DECIMAL(6,2) DEFAULT NULL,
  `theory_obtained` DECIMAL(6,2) DEFAULT NULL,
  `practical_obtained` DECIMAL(6,2) DEFAULT NULL,
  `ca_obtained` DECIMAL(6,2) DEFAULT NULL,
  `exam_obtained` DECIMAL(6,2) DEFAULT NULL,
  `total_marks` DECIMAL(6,2) NOT NULL DEFAULT 100.00,
  `passing_marks` DECIMAL(6,2) DEFAULT NULL,
  `is_absent` TINYINT(1) NOT NULL DEFAULT 0,
  `grade` VARCHAR(5) DEFAULT NULL,
  `remarks` VARCHAR(255) DEFAULT NULL,
  `entered_by` INT NOT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_mark_entry` (`student_id`, `subject_id`, `term_id`),
  INDEX `idx_marks_term_section` (`term_id`, `section_id`),
  INDEX `idx_marks_student` (`student_id`),
  INDEX `idx_marks_school` (`school_id`),
  CONSTRAINT `fk_marks_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_marks_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_marks_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 32. Tabla: result_publications
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `result_publications`;
CREATE TABLE `result_publications` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `term_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `is_published` TINYINT(1) NOT NULL DEFAULT 0,
  `approval_status` ENUM('Draft','Pending','Approved','Rejected') NOT NULL DEFAULT 'Draft',
  `submitted_by` INT DEFAULT NULL,
  `submitted_at` DATETIME DEFAULT NULL,
  `approved_by` INT DEFAULT NULL,
  `approved_at` DATETIME DEFAULT NULL,
  `published_by` INT DEFAULT NULL,
  `published_at` DATETIME DEFAULT NULL,
  `unpublished_by` INT DEFAULT NULL,
  `unpublished_at` DATETIME DEFAULT NULL,
  `unpublish_reason` VARCHAR(255) DEFAULT NULL,
  `review_note` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_publication` (`term_id`, `section_id`),
  INDEX `idx_pub_status` (`is_published`),
  INDEX `idx_result_publications_school` (`school_id`),
  CONSTRAINT `fk_pub_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_pub_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 33. Tabla: result_summaries
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `result_summaries`;
CREATE TABLE `result_summaries` (
  `id` INT AUTO_INCREMENT,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `class_id` INT DEFAULT NULL,
  `grading_set_id` INT DEFAULT NULL,
  `total_marks` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `obtained_marks` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `percentage` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  `grade` VARCHAR(5) DEFAULT NULL,
  `gpa` DECIMAL(3,1) DEFAULT 0.0,
  `position` INT DEFAULT NULL,
  `section_total` INT DEFAULT NULL,
  `subjects_count` INT DEFAULT NULL,
  `result_status` ENUM('Pass','Fail','Withheld') NOT NULL DEFAULT 'Pass',
  `is_withheld` TINYINT(1) NOT NULL DEFAULT 0,
  `withheld_reason` VARCHAR(150) DEFAULT NULL,
  `promoted_status` ENUM('Pending','Promoted','Detained') NOT NULL DEFAULT 'Pending',
  `days_present` DECIMAL(6,1) DEFAULT NULL,
  `days_total` DECIMAL(6,1) DEFAULT NULL,
  `teacher_remarks` VARCHAR(255) DEFAULT NULL,
  `principal_remarks` VARCHAR(255) DEFAULT NULL,
  `verify_token` VARCHAR(32) DEFAULT NULL,
  `generated_by` INT DEFAULT NULL,
  `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_summary` (`student_id`, `term_id`),
  INDEX `idx_sum_term_pos` (`term_id`, `position`),
  INDEX `idx_result_summaries_school` (`school_id`),
  INDEX `idx_sum_verify` (`verify_token`),
  CONSTRAINT `fk_sum_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sum_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 34. Tabla: student_subjects
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `student_subjects`;
CREATE TABLE `student_subjects` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `created_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_student_subject` (`student_id`, `subject_id`, `academic_year_id`),
  INDEX `idx_ss_student` (`student_id`),
  INDEX `idx_student_subjects_school` (`school_id`),
  CONSTRAINT `fk_ss_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ss_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 35. Tabla: mark_components
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `mark_components`;
CREATE TABLE `mark_components` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `component_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `marks_obtained` DECIMAL(6,2) DEFAULT NULL,
  `is_absent` TINYINT(1) NOT NULL DEFAULT 0,
  `entered_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_mark_comp` (`student_id`, `component_id`, `term_id`),
  INDEX `idx_mark_components_school` (`school_id`),
  CONSTRAINT `fk_mc_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mc_comp` FOREIGN KEY (`component_id`) REFERENCES `assessment_components` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_mc_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 36. Tabla: attendance_summary
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `attendance_summary`;
CREATE TABLE `attendance_summary` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `term_id` INT NOT NULL,
  `days_present` DECIMAL(6,1) NOT NULL DEFAULT 0.0,
  `days_absent` DECIMAL(6,1) NOT NULL DEFAULT 0.0,
  `days_total` DECIMAL(6,1) NOT NULL DEFAULT 0.0,
  `remarks` VARCHAR(150) DEFAULT NULL,
  `updated_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_att_summary` (`student_id`, `term_id`),
  INDEX `idx_attendance_summary_school` (`school_id`),
  CONSTRAINT `fk_att_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_att_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 37. Tabla: student_fees
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `student_fees`;
CREATE TABLE `student_fees` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `school_id` INT NOT NULL DEFAULT 1,
  `student_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `month` VARCHAR(15) NOT NULL,
  `fee_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `paid_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `status` ENUM('Paid','Partial','Unpaid') NOT NULL DEFAULT 'Unpaid',
  `due_date` DATE DEFAULT NULL,
  `paid_date` DATE DEFAULT NULL,
  `remarks` VARCHAR(150) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_student_fee_month` (`student_id`, `academic_year_id`, `month`),
  INDEX `idx_student_fees_school` (`school_id`),
  CONSTRAINT `fk_fee_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_fee_year` FOREIGN KEY (`academic_year_id`) REFERENCES `academic_years` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 38. Tabla: attendance_daily
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `attendance_daily`;
CREATE TABLE `attendance_daily` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `student_id` INT NOT NULL,
  `section_id` INT NOT NULL,
  `academic_year_id` INT NOT NULL,
  `att_date` DATE NOT NULL,
  `status` ENUM('P','A','L','LV') NOT NULL DEFAULT 'P',
  `remarks` VARCHAR(120) DEFAULT NULL,
  `marked_by` INT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_day` (`student_id`, `att_date`),
  INDEX `idx_ad_section_date` (`section_id`, `att_date`),
  INDEX `idx_ad_date_status` (`att_date`, `status`),
  CONSTRAINT `fk_ad_student` FOREIGN KEY (`student_id`) REFERENCES `students` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_ad_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 39. Tabla: fee_structures
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `fee_structures`;
CREATE TABLE `fee_structures` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `class_id` INT NOT NULL,
  `name` VARCHAR(80) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `frequency` ENUM('Monthly','One-Time') NOT NULL DEFAULT 'Monthly',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_fs` (`class_id`, `name`),
  CONSTRAINT `fk_fs_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 40. Tabla: timetable_slots
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `timetable_slots`;
CREATE TABLE `timetable_slots` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `section_id` INT NOT NULL,
  `day_of_week` TINYINT NOT NULL,
  `period_no` TINYINT NOT NULL,
  `subject_id` INT DEFAULT NULL,
  `teacher_id` INT DEFAULT NULL,
  `start_time` VARCHAR(5) DEFAULT NULL,
  `end_time` VARCHAR(5) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_slot` (`section_id`, `day_of_week`, `period_no`),
  INDEX `idx_tt_teacher` (`teacher_id`, `day_of_week`, `period_no`),
  CONSTRAINT `fk_tt_section` FOREIGN KEY (`section_id`) REFERENCES `sections` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tt_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_tt_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 41. Tabla: exam_schedule
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `exam_schedule`;
CREATE TABLE `exam_schedule` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `term_id` INT NOT NULL,
  `class_id` INT NOT NULL,
  `subject_id` INT NOT NULL,
  `exam_date` DATE DEFAULT NULL,
  `start_time` VARCHAR(5) DEFAULT NULL,
  `end_time` VARCHAR(5) DEFAULT NULL,
  `room` VARCHAR(40) DEFAULT NULL,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_exam` (`term_id`, `class_id`, `subject_id`),
  INDEX `idx_es_term` (`term_id`, `exam_date`),
  CONSTRAINT `fk_es_term` FOREIGN KEY (`term_id`) REFERENCES `exam_terms` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_es_class` FOREIGN KEY (`class_id`) REFERENCES `classes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_es_subject` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 42. Tabla: billing_invoices
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `billing_invoices`;
CREATE TABLE `billing_invoices` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_no` VARCHAR(40) NOT NULL,
  `school_id` INT NOT NULL,
  `plan_id` INT NOT NULL,
  `billing_cycle` ENUM('monthly','yearly') NOT NULL DEFAULT 'monthly',
  `amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'USD',
  `period_start` DATE NOT NULL,
  `period_end` DATE NOT NULL,
  `status` ENUM('Pending','Paid','Overdue','Void') NOT NULL DEFAULT 'Pending',
  `gateway` VARCHAR(30) DEFAULT NULL,
  `gateway_ref` VARCHAR(120) DEFAULT NULL,
  `notes` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_inv_no` (`invoice_no`),
  UNIQUE KEY `uniq_gw_ref` (`gateway_ref`),
  INDEX `idx_inv_school` (`school_id`, `status`),
  CONSTRAINT `fk_inv_school` FOREIGN KEY (`school_id`) REFERENCES `schools` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_inv_plan` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 43. Tabla: billing_events
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `billing_events`;
CREATE TABLE `billing_events` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT DEFAULT NULL,
  `gateway` VARCHAR(30) NOT NULL,
  `event_type` VARCHAR(60) NOT NULL,
  `event_id` VARCHAR(120) DEFAULT NULL,
  `payload` MEDIUMTEXT DEFAULT NULL,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY `uniq_gw_event` (`gateway`, `event_id`),
  INDEX `idx_ev_inv` (`invoice_id`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================================
-- DATOS INICIALES Y CONFIGURACIÓN (SEED DATA)
-- =====================================================================

-- 1. Planes
INSERT INTO `plans` (`id`, `name`, `price_monthly`, `price_yearly`, `max_students`, `max_teachers`, `max_branches`, `features`, `sort_order`, `is_active`) VALUES
(1, 'Trial', 0.00, 0.00, 50, 5, 1, 'Versión de prueba y evaluación', 0, 1),
(2, 'Starter', 25.00, 250.00, 300, 20, 1, 'Una sede, gestión completa de calificaciones', 1, 1),
(3, 'Standard', 50.00, 500.00, 1000, 60, 3, 'Hasta 3 sedes, asistencia y pensiones', 2, 1),
(4, 'Premium', 99.00, 990.00, 0, 0, 0, 'Ilimitado estudiantes, docentes y sedes', 3, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 2. Colegio Principal (Tenant #1)
INSERT INTO `schools` (`id`, `name`, `code`, `address`, `phone`, `email`, `status`, `plan_id`, `billing_currency`, `owner_user_id`, `notes`) VALUES
(1, 'Sistema Gestión Escolar', 'SCH01', 'Sede Central', '0000000', 'admin@ceie.website', 'Active', 4, 'USD', 3, 'Instalación Principal')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 3. Sede Principal
INSERT INTO `branches` (`id`, `school_id`, `name`, `code`, `address`, `city`, `phone`, `email`, `capacity`, `is_main`, `status`) VALUES
(1, 1, 'Sede Principal', 'MAIN', 'Calle Principal #1', 'Ciudad', '0000000', 'contacto@ceie.website', 500, 1, 'Active')
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 4. Suscripción del Colegio
INSERT INTO `school_subscriptions` (`id`, `school_id`, `plan_id`, `starts_at`, `ends_at`, `status`, `amount`, `currency`, `notes`) VALUES
(1, 1, 4, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 YEAR), 'Active', 0.00, 'USD', 'Licencia activa por 10 años')
ON DUPLICATE KEY UPDATE `status`=VALUES(`status`);

-- 5. Roles y Permisos (Plantilla School 0 y Escuela 1)
INSERT INTO `roles` (`school_id`, `role_key`, `label`, `description`, `color`, `sort_order`, `is_super`, `hidden_signup`, `permissions`) VALUES
(0, 'Super Admin', 'App Owner', 'Platform operator — owns the SaaS: schools, plans, gateways, revenue', '#0b1f3a', -2, 1, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 1, "a": 1, "e": 1, "d": 1}, "logs": {"v": 1, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "backup": {"v": 1, "a": 1, "e": 1, "d": 1}, "smtp_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "oauth_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 1, "a": 1, "e": 1, "d": 1}, "students": {"v": 0, "a": 0, "e": 0, "d": 0}, "teachers": {"v": 0, "a": 0, "e": 0, "d": 0}, "classes": {"v": 0, "a": 0, "e": 0, "d": 0}, "subjects": {"v": 0, "a": 0, "e": 0, "d": 0}, "marks_entry": {"v": 0, "a": 0, "e": 0, "d": 0}, "results": {"v": 0, "a": 0, "e": 0, "d": 0}, "my_results": {"v": 0, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 0, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 0, "a": 0, "e": 0, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 0, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}, "schools": {"v": 1, "a": 1, "e": 1, "d": 1}, "plans": {"v": 1, "a": 1, "e": 1, "d": 1}, "subscriptions": {"v": 1, "a": 1, "e": 1, "d": 1}, "gateways": {"v": 1, "a": 1, "e": 1, "d": 1}}'),
(0, 'School Owner', 'School Owner', 'Tenant owner — the school subscription, plan and invoices', '#0b6b3a', -1, 0, 1, '{"dashboard": {"v": 1, "a": 1, "e": 1, "d": 1}, "users": {"v": 1, "a": 1, "e": 1, "d": 1}, "logs": {"v": 1, "a": 1, "e": 1, "d": 1}, "sessions": {"v": 1, "a": 1, "e": 1, "d": 1}, "settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "oauth_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "roles": {"v": 1, "a": 1, "e": 1, "d": 1}, "branches": {"v": 1, "a": 1, "e": 1, "d": 1}, "students": {"v": 1, "a": 1, "e": 1, "d": 1}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 1}, "classes": {"v": 1, "a": 1, "e": 1, "d": 1}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 1}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 1}, "results": {"v": 1, "a": 1, "e": 1, "d": 1}, "my_results": {"v": 1, "a": 1, "e": 1, "d": 1}, "result_settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "broadsheet": {"v": 1, "a": 1, "e": 1, "d": 1}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 1}, "fees": {"v": 1, "a": 1, "e": 1, "d": 1}, "timetable": {"v": 1, "a": 1, "e": 1, "d": 1}, "billing": {"v": 1, "a": 1, "e": 1, "d": 1}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(0, 'Admin', 'Admin', 'School Administrator', '#155724', 0, 1, 1, '{"dashboard": {"v": 1, "a": 1, "e": 1, "d": 1}, "users": {"v": 1, "a": 1, "e": 1, "d": 1}, "logs": {"v": 1, "a": 1, "e": 1, "d": 1}, "sessions": {"v": 1, "a": 1, "e": 1, "d": 1}, "settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "oauth_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "roles": {"v": 1, "a": 1, "e": 1, "d": 1}, "branches": {"v": 1, "a": 1, "e": 1, "d": 1}, "students": {"v": 1, "a": 1, "e": 1, "d": 1}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 1}, "classes": {"v": 1, "a": 1, "e": 1, "d": 1}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 1}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 1}, "results": {"v": 1, "a": 1, "e": 1, "d": 1}, "my_results": {"v": 1, "a": 1, "e": 1, "d": 1}, "result_settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "broadsheet": {"v": 1, "a": 1, "e": 1, "d": 1}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 1}, "fees": {"v": 1, "a": 1, "e": 1, "d": 1}, "timetable": {"v": 1, "a": 1, "e": 1, "d": 1}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(0, 'Principal', 'Principal', 'Head Teacher — school-wide academic authority, no system administration', '#6f42c1', 1, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 1, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 1, "e": 1, "d": 0}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 0}, "classes": {"v": 1, "a": 1, "e": 1, "d": 0}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 0}, "marks_entry": {"v": 1, "a": 0, "e": 0, "d": 0}, "results": {"v": 1, "a": 1, "e": 1, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 1, "a": 0, "e": 1, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 0, "e": 0, "d": 0}, "fees": {"v": 1, "a": 0, "e": 1, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(0, 'Branch Admin', 'Branch Admin', 'Branch Administrator', '#b45309', 2, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 1, "e": 1, "d": 0}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 0}, "classes": {"v": 1, "a": 1, "e": 1, "d": 0}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 0}, "marks_entry": {"v": 1, "a": 0, "e": 0, "d": 0}, "results": {"v": 1, "a": 1, "e": 1, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 0, "e": 0, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(0, 'Teacher', 'Teacher', 'Teacher', '#0074D9', 3, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 0, "e": 0, "d": 0}, "teachers": {"v": 0, "a": 0, "e": 0, "d": 0}, "classes": {"v": 1, "a": 0, "e": 0, "d": 0}, "subjects": {"v": 1, "a": 0, "e": 0, "d": 0}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 0}, "results": {"v": 1, "a": 0, "e": 0, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(0, 'Student', 'Student / Parent', 'Student and Parent Portal', '#6c757d', 4, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 0, "a": 0, "e": 0, "d": 0}, "teachers": {"v": 0, "a": 0, "e": 0, "d": 0}, "classes": {"v": 0, "a": 0, "e": 0, "d": 0}, "subjects": {"v": 0, "a": 0, "e": 0, "d": 0}, "marks_entry": {"v": 0, "a": 0, "e": 0, "d": 0}, "results": {"v": 0, "a": 0, "e": 0, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 0, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 0, "a": 0, "e": 0, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 0, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'School Owner', 'School Owner', 'Tenant owner — the school subscription, plan and invoices', '#0b6b3a', -1, 0, 1, '{"dashboard": {"v": 1, "a": 1, "e": 1, "d": 1}, "users": {"v": 1, "a": 1, "e": 1, "d": 1}, "logs": {"v": 1, "a": 1, "e": 1, "d": 1}, "sessions": {"v": 1, "a": 1, "e": 1, "d": 1}, "settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "oauth_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "roles": {"v": 1, "a": 1, "e": 1, "d": 1}, "branches": {"v": 1, "a": 1, "e": 1, "d": 1}, "students": {"v": 1, "a": 1, "e": 1, "d": 1}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 1}, "classes": {"v": 1, "a": 1, "e": 1, "d": 1}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 1}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 1}, "results": {"v": 1, "a": 1, "e": 1, "d": 1}, "my_results": {"v": 1, "a": 1, "e": 1, "d": 1}, "result_settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "broadsheet": {"v": 1, "a": 1, "e": 1, "d": 1}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 1}, "fees": {"v": 1, "a": 1, "e": 1, "d": 1}, "timetable": {"v": 1, "a": 1, "e": 1, "d": 1}, "billing": {"v": 1, "a": 1, "e": 1, "d": 1}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'Admin', 'Admin', 'School Administrator', '#155724', 0, 1, 1, '{"dashboard": {"v": 1, "a": 1, "e": 1, "d": 1}, "users": {"v": 1, "a": 1, "e": 1, "d": 1}, "logs": {"v": 1, "a": 1, "e": 1, "d": 1}, "sessions": {"v": 1, "a": 1, "e": 1, "d": 1}, "settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "oauth_setup": {"v": 1, "a": 1, "e": 1, "d": 1}, "roles": {"v": 1, "a": 1, "e": 1, "d": 1}, "branches": {"v": 1, "a": 1, "e": 1, "d": 1}, "students": {"v": 1, "a": 1, "e": 1, "d": 1}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 1}, "classes": {"v": 1, "a": 1, "e": 1, "d": 1}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 1}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 1}, "results": {"v": 1, "a": 1, "e": 1, "d": 1}, "my_results": {"v": 1, "a": 1, "e": 1, "d": 1}, "result_settings": {"v": 1, "a": 1, "e": 1, "d": 1}, "broadsheet": {"v": 1, "a": 1, "e": 1, "d": 1}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 1}, "fees": {"v": 1, "a": 1, "e": 1, "d": 1}, "timetable": {"v": 1, "a": 1, "e": 1, "d": 1}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'Principal', 'Principal', 'Head Teacher — school-wide academic authority, no system administration', '#6f42c1', 1, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 1, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 1, "e": 1, "d": 0}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 0}, "classes": {"v": 1, "a": 1, "e": 1, "d": 0}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 0}, "marks_entry": {"v": 1, "a": 0, "e": 0, "d": 0}, "results": {"v": 1, "a": 1, "e": 1, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 1, "a": 0, "e": 1, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 0, "e": 0, "d": 0}, "fees": {"v": 1, "a": 0, "e": 1, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'Branch Admin', 'Branch Admin', 'Branch Administrator', '#b45309', 2, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 1, "e": 1, "d": 0}, "teachers": {"v": 1, "a": 1, "e": 1, "d": 0}, "classes": {"v": 1, "a": 1, "e": 1, "d": 0}, "subjects": {"v": 1, "a": 1, "e": 1, "d": 0}, "marks_entry": {"v": 1, "a": 0, "e": 0, "d": 0}, "results": {"v": 1, "a": 1, "e": 1, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 0, "e": 0, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}, "schools": {"v": 0, "a": 0, "e": 0, "d": 0}, "plans": {"v": 0, "a": 0, "e": 0, "d": 0}, "subscriptions": {"v": 0, "a": 0, "e": 0, "d": 0}, "gateways": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'Teacher', 'Teacher', 'Teacher', '#0074D9', 3, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 1, "a": 0, "e": 0, "d": 0}, "teachers": {"v": 0, "a": 0, "e": 0, "d": 0}, "classes": {"v": 1, "a": 0, "e": 0, "d": 0}, "subjects": {"v": 1, "a": 0, "e": 0, "d": 0}, "marks_entry": {"v": 1, "a": 1, "e": 1, "d": 0}, "results": {"v": 1, "a": 0, "e": 0, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 1, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 1, "a": 1, "e": 1, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 1, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}'),
(1, 'Student', 'Student / Parent', 'Student and Parent Portal', '#6c757d', 4, 0, 1, '{"dashboard": {"v": 1, "a": 0, "e": 0, "d": 0}, "users": {"v": 0, "a": 0, "e": 0, "d": 0}, "logs": {"v": 0, "a": 0, "e": 0, "d": 0}, "sessions": {"v": 0, "a": 0, "e": 0, "d": 0}, "settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "backup": {"v": 0, "a": 0, "e": 0, "d": 0}, "smtp_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "oauth_setup": {"v": 0, "a": 0, "e": 0, "d": 0}, "roles": {"v": 0, "a": 0, "e": 0, "d": 0}, "branches": {"v": 0, "a": 0, "e": 0, "d": 0}, "students": {"v": 0, "a": 0, "e": 0, "d": 0}, "teachers": {"v": 0, "a": 0, "e": 0, "d": 0}, "classes": {"v": 0, "a": 0, "e": 0, "d": 0}, "subjects": {"v": 0, "a": 0, "e": 0, "d": 0}, "marks_entry": {"v": 0, "a": 0, "e": 0, "d": 0}, "results": {"v": 0, "a": 0, "e": 0, "d": 0}, "my_results": {"v": 1, "a": 0, "e": 0, "d": 0}, "result_settings": {"v": 0, "a": 0, "e": 0, "d": 0}, "broadsheet": {"v": 0, "a": 0, "e": 0, "d": 0}, "attendance": {"v": 0, "a": 0, "e": 0, "d": 0}, "fees": {"v": 0, "a": 0, "e": 0, "d": 0}, "timetable": {"v": 0, "a": 0, "e": 0, "d": 0}, "billing": {"v": 0, "a": 0, "e": 0, "d": 0}}')
ON DUPLICATE KEY UPDATE `label`=VALUES(`label`), `permissions`=VALUES(`permissions`);

-- 6. Configuraciones del Sistema (system_settings)
INSERT INTO `system_settings` (`school_id`, `setting_key`, `setting_value`) VALUES
(0, 'site_name', 'Sistema Gestión Escolar'),
(0, 'currency_code', 'USD'),
(0, 'currency_symbol', '$'),
(0, 'billing_currency', 'USD'),
(0, 'default_language', 'es'),
(0, 'platform_mode', '0'),
(0, 'allow_school_signup', '0'),
(0, 'maintenance_mode', '0'),
(0, 'billing_enabled', '0'),
(0, 'billing_test_mode', '1'),
(0, 'billing_invoice_prefix', 'INV'),
(0, 'gw_manual_enabled', '1'),
(0, 'gw_manual_label', 'Transferencia Bancaria'),
(0, 'gw_manual_instructions', 'Realizar la transferencia bancaria y enviar el comprobante por WhatsApp.'),
(0, 'allow_user_profile_uploads', '1'),
(0, 'show_forgot_password', '1'),
(1, 'site_name', 'Sistema Gestión Escolar'),
(1, 'result_school_name', 'Sistema Gestión Escolar'),
(1, 'result_school_address', 'Sede Principal'),
(1, 'result_school_phone', ''),
(1, 'result_footer_note', 'Este es un boletín de calificaciones generado por el sistema.'),
(1, 'result_signature_left', 'Director de Grupo'),
(1, 'result_signature_right', 'Rectoría / Coordinación'),
(1, 'result_show_position', '1'),
(1, 'result_show_gpa', '1'),
(1, 'result_show_photo', '1'),
(1, 'result_title', 'Boletín de Calificaciones'),
(1, 'result_accent_color', '#001f3f'),
(1, 'result_template', 'classic'),
(1, 'result_show_dob', '1'),
(1, 'result_show_grade_col', '1'),
(1, 'result_show_remarks_col', '1'),
(1, 'result_show_failed_line', '1'),
(1, 'result_show_signatures', '1'),
(1, 'result_show_footer', '1'),
(1, 'result_show_qr', '1'),
(1, 'result_show_principal_sign', '1'),
(1, 'result_show_principal_remark', '1'),
(1, 'result_show_attendance', '1'),
(1, 'result_show_grade_key', '1'),
(1, 'result_show_ca_columns', '1'),
(1, 'result_grade_key_note', 'Escala de valoración y equivalencias.'),
(1, 'withhold_on_arrears', '0'),
(1, 'arrears_threshold', '0'),
(1, 'attendance_default_days', '0'),
(1, 'require_principal_approval', '1'),
(1, 'student_default_password', 'student123'),
(1, 'teacher_default_password', 'teacher123'),
(1, 'admission_no_prefix', 'EST'),
(1, 'allow_public_signup', '0')
ON DUPLICATE KEY UPDATE `setting_value`=VALUES(`setting_value`);

-- 7. Usuarios Iniciales
-- admin: admin / admin123 (Administrador del Colegio)
-- appowner: appowner / appowner123 (Super Admin Plataforma)
-- owner: owner / owner123 (Dueño del Colegio)
-- principal: principal / principal123 (Rectoría / Coordinación)
-- teacher1: teacher1 / teacher123 (Docente 1)
-- teacher2: teacher2 / teacher123 (Docente 2)
INSERT INTO `users` (`id`, `username`, `full_name`, `password`, `email`, `phone`, `email_verified`, `role`, `school_id`, `branch_id`, `is_active`, `must_change_password`) VALUES
(1, 'admin', 'System Administrator', '$2y$10$WC2fKWbKisoP2u/GhQ2RkebkxN2WsLlHgzfAT47qXfcQxSKbKyVzC', 'admin@ceie.website', '03001000001', 1, 'Admin', 1, 1, 1, 0),
(2, 'appowner', 'App Owner', '$2y$10$/Mxyd3S275p/acLn3WI6F.2nIwkxaq9VqiXXd4l93Y5lgeRPt/quO', 'appowner@ceie.website', '03001000002', 1, 'Super Admin', 0, NULL, 1, 0),
(3, 'owner', 'School Owner', '$2y$10$Dwhb3ogtZYRKNms3Qff.deaAJw/i5NYbCnO3D7THvq3ke4BeQDCtK', 'owner@ceie.website', '03001000003', 1, 'School Owner', 1, 1, 1, 0),
(4, 'principal', 'Coordinador Académico', '$2y$10$.4BiI6l3GJqliByjsDt7HeivPr1tjn/aWBnkOeXMWxy8cZFc0Jo.K', 'principal@ceie.website', '03001000005', 1, 'Principal', 1, 1, 1, 0),
(5, 'teacher1', 'Docente Ciencias y Matemáticas', '$2y$10$pGWpsstzgW2xIhQlSg/9HuVbCMmujuvYa75ygxJbnsdSBn6LuXpJO', 'teacher1@ceie.website', '03001000006', 1, 'Teacher', 1, 1, 1, 0),
(6, 'teacher2', 'Docente Lenguaje e Inglés', '$2y$10$pGWpsstzgW2xIhQlSg/9HuVbCMmujuvYa75ygxJbnsdSBn6LuXpJO', 'teacher2@ceie.website', '03001000007', 1, 'Teacher', 1, 1, 1, 0)
ON DUPLICATE KEY UPDATE `password`=VALUES(`password`), `full_name`=VALUES(`full_name`);

-- 8. Año Académico y Periodos de Evaluación
INSERT INTO `academic_years` (`id`, `school_id`, `name`, `start_date`, `end_date`, `is_current`, `is_locked`) VALUES
(1, 1, '2026', '2026-01-15', '2026-11-30', 1, 0)
ON DUPLICATE KEY UPDATE `is_current`=VALUES(`is_current`);

INSERT INTO `exam_terms` (`id`, `school_id`, `academic_year_id`, `name`, `sort_order`, `status`, `weightage`) VALUES
(1, 1, 1, 'Primer Periodo', 1, 'Open', 30.00),
(2, 1, 1, 'Segundo Periodo', 2, 'Upcoming', 30.00),
(3, 1, 1, 'Tercer Periodo', 3, 'Upcoming', 40.00)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 9. Escala de Calificaciones (Grading Sets y Grading Scheme)
INSERT INTO `grading_sets` (`id`, `school_id`, `name`, `description`, `is_default`) VALUES
(1, 1, 'Escala Estándar', 'Escala de valoración cuantitativa y cualitativa', 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `grading_scheme` (`id`, `school_id`, `set_id`, `grade`, `min_percent`, `max_percent`, `grade_point`, `remarks`, `interpretation`, `color`, `is_fail`, `sort_order`) VALUES
(1, 1, 1, 'S', 90.00, 100.00, 5.0, 'Superior', 'Desempeño Superior', '#28a745', 0, 1),
(2, 1, 1, 'A', 80.00, 89.99, 4.0, 'Alto', 'Desempeño Alto', '#17a2b8', 0, 2),
(3, 1, 1, 'B', 70.00, 79.99, 3.5, 'Básico', 'Desempeño Básico', '#ffc107', 0, 3),
(4, 1, 1, 'BJ', 0.00, 69.99, 2.0, 'Bajo', 'Desempeño Bajo (Requiere Refuerzo)', '#dc3545', 1, 4)
ON DUPLICATE KEY UPDATE `grade`=VALUES(`grade`);

-- 10. Grados y Secciones de Ejemplo
INSERT INTO `classes` (`id`, `school_id`, `branch_id`, `name`, `sort_order`, `numeric_level`, `is_active`) VALUES
(1, 1, 1, 'Grado 1', 1, 1, 1),
(2, 1, 1, 'Grado 2', 2, 2, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `sections` (`id`, `school_id`, `branch_id`, `class_id`, `name`, `capacity`, `is_active`) VALUES
(1, 1, 1, 1, '1-A', 40, 1),
(2, 1, 1, 2, '2-A', 40, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

-- 11. Asignaturas y Asignaciones a Grados
INSERT INTO `subjects` (`id`, `school_id`, `name`, `code`, `subject_type`, `include_in_total`, `is_active`) VALUES
(1, 1, 'Matemáticas', 'MAT', 'Core', 1, 1),
(2, 1, 'Lenguaje', 'LEN', 'Core', 1, 1),
(3, 1, 'Ciencias Naturales', 'CNAT', 'Core', 1, 1),
(4, 1, 'Inglés', 'ING', 'Core', 1, 1),
(5, 1, 'Informática', 'INFO', 'Elective', 1, 1)
ON DUPLICATE KEY UPDATE `name`=VALUES(`name`);

INSERT INTO `class_subjects` (`class_id`, `subject_id`, `school_id`, `total_marks`, `passing_marks`, `include_in_total`, `sort_order`) VALUES
(1, 1, 1, 100.00, 70.00, 1, 1),
(1, 2, 1, 100.00, 70.00, 1, 2),
(1, 3, 1, 100.00, 70.00, 1, 3),
(1, 4, 1, 100.00, 70.00, 1, 4),
(1, 5, 1, 100.00, 70.00, 1, 5),
(2, 1, 1, 100.00, 70.00, 1, 1),
(2, 2, 1, 100.00, 70.00, 1, 2),
(2, 3, 1, 100.00, 70.00, 1, 3),
(2, 4, 1, 100.00, 70.00, 1, 4),
(2, 5, 1, 100.00, 70.00, 1, 5)
ON DUPLICATE KEY UPDATE `total_marks`=VALUES(`total_marks`);

-- 12. Docentes en el Sistema
INSERT INTO `teachers` (`id`, `school_id`, `branch_id`, `user_id`, `employee_no`, `designation`, `qualification`, `joining_date`, `status`) VALUES
(1, 1, 1, 5, 'EMP-001', 'Docente Titular', 'Licenciado en Matemáticas', '2026-01-15', 'Active'),
(2, 1, 1, 6, 'EMP-002', 'Docente Titular', 'Licenciada en Humanidades', '2026-01-15', 'Active')
ON DUPLICATE KEY UPDATE `employee_no`=VALUES(`employee_no`);

-- Asignación de Docente a Secciones
INSERT INTO `teacher_subjects` (`school_id`, `teacher_id`, `class_id`, `section_id`, `subject_id`, `academic_year_id`) VALUES
(1, 1, 1, 1, 1, 1),
(1, 1, 1, 1, 3, 1),
(1, 2, 1, 1, 2, 1),
(1, 2, 1, 1, 4, 1)
ON DUPLICATE KEY UPDATE `is_active`=1;

SET FOREIGN_KEY_CHECKS = 1;
COMMIT;
