-- ============================================================
-- migration.sql  —  Additions for guest applications,
--                   ID image upload, sign-up approval workflow
-- Run this AFTER importing baranggay.sql
-- ============================================================

-- 1. Guest (no-account) clearance applications
CREATE TABLE IF NOT EXISTS `guest_applications` (
  `app_id`        INT(11)      NOT NULL AUTO_INCREMENT,
  `full_name`     VARCHAR(200) NOT NULL,
  `first_name`    VARCHAR(60)  NOT NULL DEFAULT '',
  `middle_name`   VARCHAR(60)  DEFAULT NULL,
  `last_name`     VARCHAR(60)  NOT NULL DEFAULT '',
  `address`       VARCHAR(200) NOT NULL,
  `birthdate`     DATE         DEFAULT NULL,
  `contact`       VARCHAR(20)  NOT NULL,
  `email`         VARCHAR(150) NOT NULL,
  `civil_status`  VARCHAR(30)  DEFAULT NULL,
  `purpose`       VARCHAR(200) NOT NULL,
  `id_image`      VARCHAR(255) DEFAULT NULL,   -- stored filename
  `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `reject_reason` TEXT         DEFAULT NULL,
  `linked_user_id`INT(11)      DEFAULT NULL,   -- set after approval
  `reviewed_by`   INT(11)      DEFAULT NULL,
  `reviewed_at`   TIMESTAMP    NULL DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`app_id`),
  KEY `fk_ga_linked_user` (`linked_user_id`),
  KEY `fk_ga_reviewed_by` (`reviewed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. Track which pending users came from normal sign-up
--    (They already land in users with status='pending'; we just need
--     to store extra sign-up data like their password hash before approval.)
--    Add a signup_source column so admin can distinguish in the UI.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `signup_source` ENUM('admin','signup','guest_app') NOT NULL DEFAULT 'admin' AFTER `status`,
  ADD COLUMN IF NOT EXISTS `signup_guest_app_id` INT(11) DEFAULT NULL AFTER `signup_source`;

-- Foreign keys (ignore error if already exists)
ALTER TABLE `guest_applications`
  ADD CONSTRAINT `fk_ga_linked_user` FOREIGN KEY (`linked_user_id`) REFERENCES `users`(`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_ga_reviewed_by` FOREIGN KEY (`reviewed_by`)   REFERENCES `users`(`user_id`) ON DELETE SET NULL;
