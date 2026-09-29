-- Migration: add request_logs (developer log console at /logs)
-- Safe to run on an existing database — does not touch other tables.
-- Import via cPanel > phpMyAdmin > (select your DB) > Import, or:
--   mysql -u USER -p DBNAME < sql/migrations/2026-06-03_request_logs.sql

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `request_logs` (
    `id`          BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
    `created_at`  TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,  -- stored in UTC
    `ip`          VARCHAR(45)           NULL,
    `method`      VARCHAR(10)           NULL,
    `action`      VARCHAR(64)           NULL,
    `status`      SMALLINT UNSIGNED     NULL,
    `duration_ms` INT UNSIGNED          NULL,
    `actor`       VARCHAR(200)          NULL,   -- logged-in email, if any
    `error`       VARCHAR(500)          NULL,
    `user_agent`  VARCHAR(255)          NULL,

    PRIMARY KEY (`id`),
    KEY `idx_log_created` (`created_at`),
    KEY `idx_log_action` (`action`),
    KEY `idx_log_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
