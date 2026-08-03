-- ═══════════════════════════════════════════════════════════════
--  Migration 002: إضافة جدول car_trims + عمود type لـ car_colors
--  شغّل هاد الملف في phpMyAdmin: استيراد ← اختر الملف ← تنفيذ
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

-- ─────────────────────────────────────────────────────────────
-- 1. إضافة عمود type لجدول car_colors (exterior / interior)
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `car_colors`
    ADD COLUMN `color_type` ENUM('exterior','interior') NOT NULL DEFAULT 'exterior'
    AFTER `car_id`;

-- ─────────────────────────────────────────────────────────────
-- 2. جدول نسخ السيارة car_trims
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `car_trims` (
    `id`                    INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `car_id`                INT UNSIGNED    NOT NULL,
    `trim_name`             VARCHAR(100)    NOT NULL  COMMENT 'مثل Comfort أو Design',
    `trim_name_ar`          VARCHAR(100)    NULL      COMMENT 'الاسم بالعربي',
    `drivetrain`            VARCHAR(20)     NULL      COMMENT '4x2 / 4x4 / AWD / RWD',
    `power_hp`              SMALLINT        NULL      COMMENT 'القوة بالحصان',
    `torque_nm`             SMALLINT        NULL      COMMENT 'العزم بالنيوتن متر',
    `acceleration_0_100`    DECIMAL(4,1)    NULL      COMMENT 'من 0 لـ 100 بالثانية',
    `top_speed_kmh`         SMALLINT        NULL      COMMENT 'أقصى سرعة',
    `battery_capacity_kwh`  DECIMAL(5,1)    NULL      COMMENT 'سعة البطارية',
    `battery_type`          VARCHAR(100)    NULL      COMMENT 'نوع البطارية مثل BYD Blade Battery',
    `range_km`              SMALLINT        NULL      COMMENT 'المدى بالكيلومتر',
    `charge_ac_kw`          DECIMAL(4,1)    NULL      COMMENT 'شحن AC',
    `charge_dc_kw`          SMALLINT        NULL      COMMENT 'شحن DC سريع',
    `price`                 DECIMAL(12,2)   NULL      COMMENT 'سعر هذه النسخة',
    `is_active`             TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    UNIQUE INDEX `uq_car_trim` (`car_id`, `trim_name`),
    INDEX `idx_car_active`    (`car_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 3. جدول مميزات النسخة car_trim_features
--    لتخزين أي ميزة موجودة في نسخة معينة (✓ أو قيمة)
-- ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `car_trim_features` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `car_id`        INT UNSIGNED    NOT NULL,
    `trim_name`     VARCHAR(100)    NOT NULL  COMMENT 'اسم النسخة (Comfort/Design)',
    `feature_key`   VARCHAR(150)    NOT NULL  COMMENT 'مفتاح الميزة',
    `feature_value` VARCHAR(500)    NOT NULL  DEFAULT 'yes',
    `feature_group` VARCHAR(50)     NOT NULL  DEFAULT 'general'
                    COMMENT 'interior/exterior/technology/safety/comfort',
    `feature_label` VARCHAR(200)    NULL      COMMENT 'الاسم العربي للميزة',
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    UNIQUE INDEX `uq_trim_feature` (`car_id`, `trim_name`, `feature_key`),
    INDEX `idx_car_group`          (`car_id`, `feature_group`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────────────────────
-- 4. إضافة عمود passenger_count لجدول cars
-- ─────────────────────────────────────────────────────────────
ALTER TABLE `cars`
    ADD COLUMN IF NOT EXISTS `passenger_count` TINYINT UNSIGNED NULL COMMENT 'عدد الركاب'
    AFTER `warranty_km`,
    ADD COLUMN IF NOT EXISTS `cargo_liters`    SMALLINT UNSIGNED NULL COMMENT 'حجم الصندوق بالليتر'
    AFTER `passenger_count`,
    ADD COLUMN IF NOT EXISTS `towing_kg`       SMALLINT UNSIGNED NULL COMMENT 'قدرة الجر بالكيلوغرام'
    AFTER `cargo_liters`;
