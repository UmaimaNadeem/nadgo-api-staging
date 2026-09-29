-- Coupon / promo-code support
-- Creates the coupons table and adds discount columns to the orders table.

SET NAMES utf8mb4;

-- ── coupons table ─────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `coupons` (
  `id`               INT          NOT NULL AUTO_INCREMENT,
  `code`             VARCHAR(50)  NOT NULL,
  `description`      VARCHAR(255) DEFAULT NULL,
  `discount_type`    ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  `discount_value`   DECIMAL(10,2) NOT NULL,
  `min_order_amount` DECIMAL(10,2) DEFAULT NULL,   -- NULL = no minimum
  `max_uses`         INT           DEFAULT NULL,   -- NULL = unlimited
  `used_count`       INT           NOT NULL DEFAULT 0,
  `active_from`      DATETIME      DEFAULT NULL,   -- NULL = active immediately
  `active_until`     DATETIME      DEFAULT NULL,   -- NULL = never expires
  `is_active`        TINYINT(1)    NOT NULL DEFAULT 1,
  `created_at`       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_coupon_code` (`code`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── extend orders table ───────────────────────────────────────────────────────
-- Idempotent: only adds if the column doesn't exist yet.
ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `coupon_code`     VARCHAR(50)   DEFAULT NULL AFTER `tax_rate`,
  ADD COLUMN IF NOT EXISTS `discount_amount` DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER `coupon_code`;

-- ── seed sample coupons ───────────────────────────────────────────────────────
INSERT IGNORE INTO `coupons` (`code`, `description`, `discount_type`, `discount_value`, `min_order_amount`, `max_uses`, `is_active`) VALUES
  ('WELCOME10', '10% off your first order',  'percent', 10.00, NULL,   NULL, 1),
  ('SAVE20',    '£20 off orders over £100',  'fixed',   20.00, 100.00, NULL, 1);
