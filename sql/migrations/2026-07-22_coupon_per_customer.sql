-- Per-customer coupon usage tracking
-- Run after 2026-07-21_coupons.sql

ALTER TABLE `coupons`
  ADD COLUMN IF NOT EXISTS `per_customer_limit` INT DEFAULT NULL
    COMMENT 'Max times one customer email may use this coupon. NULL = unlimited.'
    AFTER `max_uses`;

CREATE TABLE IF NOT EXISTS `coupon_usages` (
  `id`             INT          NOT NULL AUTO_INCREMENT,
  `coupon_code`    VARCHAR(50)  NOT NULL,
  `customer_email` VARCHAR(255) NOT NULL,
  `order_ref`      VARCHAR(100) DEFAULT NULL,
  `used_at`        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_coupon_email` (`coupon_code`, `customer_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- WELCOME10 is "first order only" — one use per customer
UPDATE `coupons` SET `per_customer_limit` = 1 WHERE `code` = 'WELCOME10';
