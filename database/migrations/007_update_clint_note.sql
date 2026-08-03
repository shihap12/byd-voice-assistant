-- ═══════════════════════════════════════════════════════════════
--  Migration 007: إضافة رقم الجوال واسم العميل مباشرة على جدول الملاحظات
-- ═══════════════════════════════════════════════════════════════

USE `byd-byd`;

ALTER TABLE `customer_notes`
    ADD COLUMN `phone_number`  VARCHAR(50)  NULL AFTER `customer_id`,
    ADD COLUMN `customer_name` VARCHAR(150) NULL AFTER `phone_number`;