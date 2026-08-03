-- ═══════════════════════════════════════════════════════════════
--  BYD Voice Assistant — Conversation Tracking Tables
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

CREATE TABLE IF NOT EXISTS `customers` (
    `id`             INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `phone_number`   VARCHAR(50)      NOT NULL COMMENT 'رقم الهاتف للعميل',
    `name`           VARCHAR(100)     NULL     COMMENT 'اسم العميل إذا توفر',
    `created_at`     DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_phone_number`  (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `calls` (
    `id`               INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `call_id`          VARCHAR(100)     NOT NULL COMMENT 'Vapi Call ID',
    `conversation_id`  VARCHAR(100)     NULL     COMMENT 'Vapi Conversation ID',
    `customer_id`      INT UNSIGNED     NULL,
    `session_id`       VARCHAR(100)     NULL     COMMENT 'المعرف الداخلي للجلسة',
    `status`           VARCHAR(50)      NOT NULL DEFAULT 'initiated',
    `started_at`       DATETIME         NULL,
    `ended_at`         DATETIME         NULL,
    `duration_seconds` INT UNSIGNED     NOT NULL DEFAULT 0,
    `summary`          TEXT             NULL,
    `recording_url`    VARCHAR(500)     NULL,
    `created_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_call_id`       (`call_id`),
    INDEX `idx_calls_conv_id`       (`conversation_id`),
    INDEX `idx_calls_session_id`    (`session_id`),
    INDEX `idx_calls_created_at`    (`created_at`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `messages` (
    `id`           BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `call_id`      VARCHAR(100)     NOT NULL,
    `role`         VARCHAR(20)      NOT NULL COMMENT 'user|assistant|system',
    `message_text` TEXT             NOT NULL,
    `message_id`   VARCHAR(100)     NULL     COMMENT 'Vapi message ID or unique hash',
    `created_at`   DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_call_message`  (`call_id`, `message_id`),
    INDEX `idx_messages_call_id`    (`call_id`),
    INDEX `idx_messages_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `transcripts` (
    `id`              INT UNSIGNED     NOT NULL AUTO_INCREMENT,
    `call_id`         VARCHAR(100)     NOT NULL,
    `transcript_text` LONGTEXT         NOT NULL,
    `created_at`      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_transcript_call` (`call_id`),
    INDEX `idx_transcripts_created`   (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
