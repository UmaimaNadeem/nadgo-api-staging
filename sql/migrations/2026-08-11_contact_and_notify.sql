-- Contact form submissions + product "Notify me" interest tracking
-- Run via phpMyAdmin > Import or: mysql -u USER -p DBNAME < this_file.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────
-- Contact / "request more info" form submissions
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `contact_submissions` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `first_name`       VARCHAR(100)    NOT NULL,
  `last_name`        VARCHAR(100),
  `email`            VARCHAR(200)    NOT NULL,
  `phone`            VARCHAR(50),
  `company`          VARCHAR(200),
  `country`          VARCHAR(100),
  `message`          TEXT,
  `product_interest` VARCHAR(200),  -- product page the enquiry came from, if any
  `created_at`       TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_contact_email` (`email`),
  KEY `idx_contact_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- "Notify me" interest per product (logged-in customers only)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_notify_requests` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `product_id` INT UNSIGNED    NOT NULL,
  `email`      VARCHAR(200)    NOT NULL,
  `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,   -- 0 = customer cancelled the request
  `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_notify_product_email` (`product_id`, `email`),
  KEY `idx_notify_product` (`product_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
