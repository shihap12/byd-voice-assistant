-- ═══════════════════════════════════════════════════════════════
--  Migration 015: نظام حجز زيارات الفرع (منفصل عن مواعيد الصيانة)
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

CREATE TABLE IF NOT EXISTS `visits` (
    `id`                INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `customer_name`     VARCHAR(190)     NOT NULL,
    `phone_number`      VARCHAR(20)      NOT NULL,
    `car_id`            INT UNSIGNED     NULL,
    `visit_date`        DATE             NOT NULL,
    `visit_time`        TIME             NOT NULL,
    `duration_minutes`  SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `status`            ENUM('scheduled','cancelled','completed','missed') NOT NULL DEFAULT 'scheduled',
    `source`            ENUM('voice','chat','whatsapp','admin')   NOT NULL DEFAULT 'voice',
    `session_id`        VARCHAR(190)     NULL,
    `notes`             TEXT             NULL,
    `created_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_visits_car` FOREIGN KEY (`car_id`) REFERENCES `cars`(`id`) ON DELETE SET NULL,
    INDEX `idx_visit_date_time` (`visit_date`, `visit_time`),
    INDEX `idx_visit_status` (`status`),
    INDEX `idx_visit_phone` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;