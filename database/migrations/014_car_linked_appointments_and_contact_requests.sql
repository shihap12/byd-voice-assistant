-- ═══════════════════════════════════════════════════════════════
--  Migration 014: ربط المواعيد بسيارة محددة + جدول طلبات التواصل مع مختص
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

-- 1. ربط كل موعد بالسيارة اللي كان العميل مهتم فيها وقت الحجز
ALTER TABLE `appointments`
    ADD COLUMN IF NOT EXISTS `car_id` INT UNSIGNED NULL AFTER `phone_number`,
    ADD CONSTRAINT `fk_appointments_car`
        FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE SET NULL;

ALTER TABLE `appointments`
    ADD INDEX IF NOT EXISTS `idx_appointments_car_id` (`car_id`);

-- 2. جدول جديد: طلبات تواصل مع مختص (منفصل عن المواعيد)
CREATE TABLE IF NOT EXISTS `specialist_contact_requests` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `customer_name`  VARCHAR(190)     NOT NULL,
    `phone_number`   VARCHAR(20)      NOT NULL,
    `car_id`         INT UNSIGNED     NULL,
    `channel`        ENUM('voice','chat','whatsapp') NOT NULL DEFAULT 'voice',
    `session_id`     VARCHAR(190)     NULL,
    `status`         ENUM('pending','contacted') NOT NULL DEFAULT 'pending',
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE SET NULL,
    INDEX `idx_scr_status`  (`status`),
    INDEX `idx_scr_car_id`  (`car_id`),
    INDEX `idx_scr_phone`   (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;