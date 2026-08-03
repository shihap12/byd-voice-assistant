-- ═══════════════════════════════════════════════════════════════
--  BYD Voice Assistant — Complete Database Setup
--  استيراد هاد الملف من phpMyAdmin: استيراد ← اختر الملف ← تنفيذ
--  MySQL 8.0+ / MariaDB 10.4+ | UTF8MB4 | InnoDB
-- ═══════════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- ─────────────────────────────────────────────────────────────
-- 1. إنشاء قاعدة البيانات
-- ─────────────────────────────────────────────────────────────
CREATE DATABASE IF NOT EXISTS `byd-byd`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `byd-byd`;

-- ─────────────────────────────────────────────────────────────
-- 2. حذف الجداول القديمة (للبدء من جديد بدون أخطاء)
-- ─────────────────────────────────────────────────────────────


-- ─────────────────────────────────────────────────────────────
-- 3. جدول السيارات (cars)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `cars` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `model_name`     VARCHAR(100)     NOT NULL COMMENT 'اسم الموديل بالإنجليزي مثل SEAL',
    `model_name_ar`  VARCHAR(100)     NOT NULL COMMENT 'اسم الموديل بالعربي مثل سيل',
    `model_code`     VARCHAR(50)      NOT NULL COMMENT 'كود داخلي مثل BYD_SEAL_2024',
    `year`           SMALLINT         NOT NULL DEFAULT 2024,
    `category`       ENUM('sedan','suv','hatchback','mpv','pickup') NOT NULL DEFAULT 'sedan',
    `price_from`     DECIMAL(10,2)    NULL     COMMENT 'السعر الابتدائي',
    `is_active`      TINYINT(1)       NOT NULL DEFAULT 1,
    `warranty_years` TINYINT UNSIGNED NULL     COMMENT 'سنوات الضمان',
    `warranty_km`    INT UNSIGNED     NULL     COMMENT 'كيلومترات الضمان',
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_model_code`    (`model_code`),
    INDEX `idx_model_year`          (`model_name`, `year`),
    INDEX `idx_model_ar`            (`model_name_ar`),
    INDEX `idx_active_year`         (`is_active`, `year` DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4. جدول المواصفات (car_specifications)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `car_specifications` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `car_id`         INT UNSIGNED     NOT NULL,
    `spec_key`       VARCHAR(150)     NOT NULL COMMENT 'مثل battery_capacity أو range_km',
    `spec_value`     VARCHAR(500)     NOT NULL,
    `spec_group`     VARCHAR(50)      NOT NULL DEFAULT 'general'
                     COMMENT 'battery|performance|dimensions|safety|comfort|general',
    `unit`           VARCHAR(20)      NULL     COMMENT 'مثل km أو kWh',
    `display_order`  SMALLINT         NOT NULL DEFAULT 0,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    UNIQUE INDEX `uq_car_spec`       (`car_id`, `spec_key`),
    INDEX `idx_car_group`            (`car_id`, `spec_group`),
    INDEX `idx_group_order`          (`spec_group`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 5. جدول ألوان السيارات (car_colors)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `car_colors` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `car_id`         INT UNSIGNED     NOT NULL,
    `color_name_en`  VARCHAR(100)     NOT NULL DEFAULT '',
    `color_name_ar`  VARCHAR(100)     NOT NULL DEFAULT '',
    `hex_code`       VARCHAR(7)       NULL     COMMENT 'كود اللون مثل #FFFFFF',
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    INDEX `idx_car_id`               (`car_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 6. جدول ملفات PDF (pdf_documents)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `pdf_documents` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `car_id`         INT UNSIGNED     NOT NULL,
    `file_name`      VARCHAR(255)     NOT NULL,
    `file_path`      VARCHAR(500)     NOT NULL,
    `extracted_text` LONGTEXT         NULL     COMMENT 'النص المستخرج من Gemini',
    `page_count`     SMALLINT         NULL,
    `status`         ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    `uploaded_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `processed_at`   DATETIME         NULL,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    INDEX `idx_car_status`           (`car_id`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 7. جدول سجل الاستفسارات (user_queries)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE `user_queries` (
    `id`             BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `call_id`        VARCHAR(100)     NOT NULL,
    `query_text`     TEXT             NOT NULL,
    `car_id`         INT UNSIGNED     NULL,
    `intent`         VARCHAR(100)     NULL     COMMENT 'get_specs|compare|search_manual|session_end',
    `response_ms`    SMALLINT         NULL,
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE SET NULL,
    INDEX `idx_intent_date`          (`intent`, `created_at` DESC),
    INDEX `idx_car_intent`           (`car_id`, `intent`),
    INDEX `idx_call_id`              (`call_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════
-- بيانات تجريبية — بيانات حقيقية لسيارات BYD
-- ═══════════════════════════════════════════════════════════════

-- ─────────────────────────────────────────────────────────────
-- السيارات الرئيسية
-- ─────────────────────────────────────────────────────────────
