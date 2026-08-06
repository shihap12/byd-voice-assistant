-- ═══════════════════════════════════════════════════════════════
--  Migration 012: Customer Profile Persistence for WhatsApp
--  آمن لـ TiDB — لا يستخدم AUTO_INCREMENT جديد على جداول موجودة
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

-- 1. تأكد إن عمود name موجود ومدته كافية لاسم ثلاثي
ALTER TABLE `customers`
    MODIFY COLUMN `name` VARCHAR(200) NULL COMMENT 'الاسم الكامل للعميل';

-- 2. إضافة عمود updated_at إذا مش موجود
ALTER TABLE `customers`
    ADD COLUMN IF NOT EXISTS `updated_at` DATETIME NOT NULL
        DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP;

-- 3. Backfill: حدّث الأسماء الفارغة من جدول appointments
UPDATE `customers` c
SET c.`name` = (
    SELECT a.`customer_name`
    FROM `appointments` a
    WHERE a.`phone_number` = c.`phone_number`
      AND a.`customer_name` IS NOT NULL
      AND a.`customer_name` != ''
    ORDER BY a.`id` DESC
    LIMIT 1
)
WHERE (c.`name` IS NULL OR c.`name` = '')
  AND EXISTS (
    SELECT 1 FROM `appointments` a
    WHERE a.`phone_number` = c.`phone_number`
      AND a.`customer_name` IS NOT NULL
      AND a.`customer_name` != ''
);
