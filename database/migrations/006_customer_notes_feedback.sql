-- ═══════════════════════════════════════════════════════════════
--  Migration 006: جدول ملاحظات العملاء + جدول تقييم رضا العملاء
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

-- ملاحظات العميل اللي بتتسجل أثناء المكالمة (شكوى، اقتراح، طلب خاص...)
CREATE TABLE IF NOT EXISTS `customer_notes` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `call_id`       VARCHAR(100)    NOT NULL,
    `customer_id`   INT UNSIGNED    NULL,
    `note_text`     TEXT            NOT NULL,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_notes_call`      (`call_id`),
    INDEX `idx_notes_customer`  (`customer_id`),
    INDEX `idx_notes_created`   (`created_at` DESC),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- رأي العميل بالتجربة + درجة الرضا المستخرجة بالذكاء الاصطناعي (٠ لـ ١٠٠)
CREATE TABLE IF NOT EXISTS `call_feedback` (
    `id`                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `call_id`            VARCHAR(100)    NOT NULL,
    `customer_id`        INT UNSIGNED    NULL,
    `feedback_text`      TEXT            NOT NULL COMMENT 'كلام العميل الحرفي عن تجربته',
    `sentiment_score`    TINYINT UNSIGNED NULL     COMMENT '٠ إلى ١٠٠ — يحسبها Gemini',
    `sentiment_summary`  VARCHAR(255)    NULL      COMMENT 'ملخص قصير لسبب الدرجة',
    `created_at`         DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE INDEX `uq_feedback_call`   (`call_id`),
    INDEX `idx_feedback_customer`     (`customer_id`),
    INDEX `idx_feedback_score`        (`sentiment_score`),
    FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
