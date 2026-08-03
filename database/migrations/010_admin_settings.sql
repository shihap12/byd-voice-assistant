-- ═══════════════════════════════════════════════════════════════
--  Migration 010: جدول إعدادات الأدمن (اسم البوت وغيره)
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

CREATE TABLE IF NOT EXISTS `admin_settings` (
    `id`            INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100)     NOT NULL,
    `setting_value` TEXT             NOT NULL,
    `updated_at`    DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- القيم الافتراضية
INSERT INTO `admin_settings` (`setting_key`, `setting_value`) VALUES
    ('bot_name', 'ميرا'),
    ('bot_name_en', 'Mira');
