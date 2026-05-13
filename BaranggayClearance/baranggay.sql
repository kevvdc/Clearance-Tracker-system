-- ============================================================
-- baranggay.sql  —  Simplified Barangay Clearance System
-- Import into phpMyAdmin: select the `baranggay` database
-- first, then use Import tab to run this file.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = '';

-- ------------------------------------------------------------
-- Table: clearance_type
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `clearance_type`;
CREATE TABLE `clearance_type` (
  `type_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `type_name`    VARCHAR(100) NOT NULL,
  `description`  VARCHAR(255) DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `clearance_type` (`type_id`, `type_name`, `description`) VALUES
(1, 'Barangay Clearance',       'General clearance for employment, loans, and other purposes'),
(2, 'Certificate of Indigency', 'Issued to indigent residents for financial assistance'),
(3, 'Certificate of Residency', 'Proof of residency within the barangay'),
(4, 'Business Permit Clearance','Clearance required for business registration or renewal'),
(5, 'Good Moral Certificate',   'Certificate attesting to good moral character');

-- ------------------------------------------------------------
-- Table: staff_roles
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `staff_roles`;
CREATE TABLE `staff_roles` (
  `role_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `role_name`    VARCHAR(100) NOT NULL,
  `description`  VARCHAR(255) DEFAULT NULL,
  `is_active`    TINYINT(1)   NOT NULL DEFAULT 1,
  `created_at`   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `uq_role_name` (`role_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `staff_roles` (`role_name`, `description`) VALUES
('Barangay Captain',   'Head of the barangay'),
('Barangay Secretary', 'Administrative officer of the barangay'),
('Barangay Treasurer', 'Fiscal officer of the barangay'),
('Kagawad',            'Elected council member'),
('SK Chairman',        'Sangguniang Kabataan chairman'),
('Barangay Clerk',     'General administrative staff');

-- ------------------------------------------------------------
-- Table: users  (ALL accounts — admin, staff, resident)
-- Separate first_name, middle_name, last_name columns added.
-- full_name is kept as a computed convenience/display column.
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `user_id`       INT(11)      NOT NULL AUTO_INCREMENT,
  `username`      VARCHAR(80)  NOT NULL,
  `password`      VARCHAR(255) NOT NULL,
  `first_name`    VARCHAR(60)  NOT NULL DEFAULT '',
  `middle_name`   VARCHAR(60)  DEFAULT NULL,
  `last_name`     VARCHAR(60)  NOT NULL DEFAULT '',
  `full_name`     VARCHAR(200) NOT NULL COMMENT 'Derived: first + middle + last',
  `email`         VARCHAR(150) DEFAULT NULL,
  `role`          ENUM('admin','staff','resident') NOT NULL DEFAULT 'resident',
  `staff_role_id` INT(11)      DEFAULT NULL,
  `status`        ENUM('active','pending','suspended') NOT NULL DEFAULT 'pending',
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `verified_at`   TIMESTAMP    NULL DEFAULT NULL,
  `verified_by`   INT(11)      DEFAULT NULL,
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Table: residents
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `residents`;
CREATE TABLE `residents` (
  `resident_id`    INT(11)       NOT NULL AUTO_INCREMENT,
  `user_id`        INT(11)       NOT NULL,
  `first_name`     VARCHAR(60)   NOT NULL,
  `middle_name`    VARCHAR(60)   DEFAULT NULL,
  `last_name`      VARCHAR(60)   NOT NULL,
  `birthdate`      DATE          DEFAULT NULL,
  `gender`         ENUM('Male','Female','Other') DEFAULT NULL,
  `civil_status`   VARCHAR(30)   DEFAULT NULL,
  `address`        VARCHAR(200)  NOT NULL,
  `purok`          VARCHAR(50)   DEFAULT NULL,
  `contact`        VARCHAR(20)   DEFAULT NULL,
  `email`          VARCHAR(150)  DEFAULT NULL,
  `years_in_brgy`  INT(3)        DEFAULT NULL,
  `id_type`        VARCHAR(50)   DEFAULT NULL,
  `id_number`      VARCHAR(80)   DEFAULT NULL,
  `created_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`resident_id`),
  UNIQUE KEY `uq_resident_user` (`user_id`),
  KEY `idx_last_name` (`last_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Table: requests
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `requests`;
CREATE TABLE `requests` (
  `request_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `resident_id`     INT(11)      NOT NULL,
  `type_id`         INT(11)      NOT NULL,
  `date_requested`  DATE         NOT NULL DEFAULT (CURDATE()),
  `purpose`         VARCHAR(200) NOT NULL,
  `status`          ENUM('pending','verified','approved','released','rejected') NOT NULL DEFAULT 'pending',
  `source`          ENUM('online','walkin') NOT NULL DEFAULT 'online',
  `handled_by`      INT(11)      DEFAULT NULL,
  `notes`           TEXT         DEFAULT NULL,
  `created_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`request_id`),
  KEY `fk_req_resident` (`resident_id`),
  KEY `fk_req_type`     (`type_id`),
  KEY `fk_req_handler`  (`handled_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------------
-- Table: request_audit
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `request_audit`;
CREATE TABLE `request_audit` (
  `audit_id`      INT(11)      NOT NULL AUTO_INCREMENT,
  `request_id`    INT(11)      NOT NULL,
  `action`        VARCHAR(80)  NOT NULL,
  `old_status`    VARCHAR(30)  DEFAULT NULL,
  `new_status`    VARCHAR(30)  DEFAULT NULL,
  `notes`         TEXT         DEFAULT NULL,
  `done_by`       INT(11)      NOT NULL,
  `done_at`       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_id`),
  KEY `fk_audit_request` (`request_id`),
  KEY `fk_audit_user`    (`done_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── Foreign Key Constraints ────────────────────────────────
ALTER TABLE `users`
  ADD CONSTRAINT `users_ibfk_verified_by`  FOREIGN KEY (`verified_by`)   REFERENCES `users`       (`user_id`) ON DELETE SET NULL,
  ADD CONSTRAINT `users_ibfk_staff_role`   FOREIGN KEY (`staff_role_id`) REFERENCES `staff_roles` (`role_id`) ON DELETE SET NULL;

ALTER TABLE `residents`
  ADD CONSTRAINT `residents_ibfk_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_resident`  FOREIGN KEY (`resident_id`) REFERENCES `residents`     (`resident_id`),
  ADD CONSTRAINT `requests_ibfk_type`      FOREIGN KEY (`type_id`)     REFERENCES `clearance_type`(`type_id`),
  ADD CONSTRAINT `requests_ibfk_handler`   FOREIGN KEY (`handled_by`)  REFERENCES `users`         (`user_id`) ON DELETE SET NULL;

ALTER TABLE `request_audit`
  ADD CONSTRAINT `audit_ibfk_request` FOREIGN KEY (`request_id`) REFERENCES `requests` (`request_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `audit_ibfk_user`    FOREIGN KEY (`done_by`)    REFERENCES `users`    (`user_id`);

SET FOREIGN_KEY_CHECKS = 1;
