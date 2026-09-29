-- Migration: add password_resets (forgot-password emailed code)
-- Safe to run on an existing database — does not touch other tables.
--   mysql -u USER -p DBNAME < sql/migrations/2026-06-04_password_resets.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `email`       VARCHAR(200)     NOT NULL,
    `code_hash`   CHAR(64)         NOT NULL,                     -- hmac_sha256(code, app_secret)
    `attempts`    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `consumed_at` TIMESTAMP             NULL,
    `expires_at`  TIMESTAMP        NOT NULL,
    `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_pr_email` (`email`),
    KEY `idx_pr_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
