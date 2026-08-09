USE `byd-byd`;

ALTER TABLE `appointments`
    MODIFY COLUMN `status` ENUM('scheduled','cancelled','completed','missed')
    NOT NULL DEFAULT 'scheduled';