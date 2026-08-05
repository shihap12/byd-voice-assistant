-- ═══════════════════════════════════════════════════════════════
--  Migration 011: نظام حجز مواعيد زيارة الفرع
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

CREATE TABLE IF NOT EXISTS `appointments` (
    `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `customer_name`     VARCHAR(190)     NOT NULL,
    `phone_number`      VARCHAR(20)      NOT NULL,
    `appointment_date`  DATE             NOT NULL,
    `appointment_time`  TIME             NOT NULL,
    `duration_minutes`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `status`            ENUM('scheduled','cancelled','completed') NOT NULL DEFAULT 'scheduled',
    `source`            ENUM('voice','chat','whatsapp','admin')   NOT NULL DEFAULT 'voice',
    `session_id`        VARCHAR(190)     NULL,
    `notes`             TEXT             NULL,
    `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_appointment_date_time` (`appointment_date`, `appointment_time`),
    INDEX `idx_status` (`status`),
    INDEX `idx_phone_number` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- إعدادات دوام الفرع ومدى الحجز المسموح، قابلة للتعديل من صفحة الأدمن
-- (نفس جدول admin_settings المستخدم لاسم البوت — INSERT IGNORE عشان ما يعيد
-- الكتابة فوق قيم موجودة مسبقاً لو الميغريشن انعادت بالغلط)
INSERT IGNORE INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
    ('appointment_start_time',       '09:00'),
    ('appointment_end_time',         '17:00'),
    ('appointment_slot_minutes',     '30'),
    ('appointment_booking_days_ahead', '14');