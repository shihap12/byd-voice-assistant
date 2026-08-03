-- ═══════════════════════════════════════════════════════════════
--  Migration 008: جدول صور السيارات
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

CREATE TABLE IF NOT EXISTS `car_images` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `car_id`        INT UNSIGNED     NOT NULL,
    `file_name`     VARCHAR(255)     NOT NULL COMMENT 'اسم الملف الأصلي',
    `file_path`     VARCHAR(500)     NOT NULL COMMENT 'المسار النسبي للملف في storage',
    `display_order` SMALLINT         NOT NULL DEFAULT 0 COMMENT 'ترتيب العرض',
    `created_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE CASCADE,
    INDEX `idx_car_images_car` (`car_id`, `display_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
