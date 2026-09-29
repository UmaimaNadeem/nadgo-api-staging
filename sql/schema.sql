-- Nadgo Orders API — database schema
-- Import via cPanel > phpMyAdmin > (select your DB) > Import, or:
--   mysql -u USER -p DBNAME < schema.sql

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ----------------------------------------------------------------------------
-- users: the customer's editable profile (contact + default address).
--   Login is by email OTP. `password_hash` is reserved for a future optional
--   password login and is NOT used yet.
--   Each order keeps its OWN address snapshot (orders.ship_*) so editing a
--   profile never rewrites where past orders were shipped.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`         VARCHAR(200)    NOT NULL,
    `phone`         VARCHAR(60)          NULL,
    `first_name`    VARCHAR(100)         NULL,
    `last_name`     VARCHAR(100)         NULL,
    `address1`      VARCHAR(255)         NULL,
    `address2`      VARCHAR(255)         NULL,
    `city`          VARCHAR(120)         NULL,
    `postcode`      VARCHAR(40)          NULL,
    `country`       VARCHAR(100)         NULL,
    `password_hash` VARCHAR(255)         NULL,                    -- reserved (not used yet)
    `created_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_user_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- orders: one row per order
--   payment_status : pending -> submitted -> paid | rejected
--   delivery_status: pending -> processing -> shipped -> delivered | cancelled
--   payment_status : pending -> submitted -> paid | rejected
--   delivery_status: pending -> processing -> shipped -> delivered | cancelled
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `orders` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_ref`         VARCHAR(100)    NOT NULL,                 -- external/generated order id
    `tracking_token`    VARCHAR(64)     NOT NULL,                 -- secret token for direct order links
    `user_id`           BIGINT UNSIGNED      NULL,               -- owning user (profile)

    -- Contact -----------------------------------------------------------------
    `customer_email`    VARCHAR(200)    NOT NULL,                 -- required: tracking, OTP, receipts
    `customer_phone`    VARCHAR(60)     NOT NULL,

    -- Shipping address --------------------------------------------------------
    `ship_first_name`   VARCHAR(100)    NOT NULL,
    `ship_last_name`    VARCHAR(100)    NOT NULL,
    `ship_address1`     VARCHAR(255)    NOT NULL,
    `ship_address2`     VARCHAR(255)         NULL,               -- apartment, suite, etc.
    `ship_city`         VARCHAR(120)    NOT NULL,
    `ship_postcode`     VARCHAR(40)     NOT NULL,
    `ship_country`      VARCHAR(100)    NOT NULL,

    -- Totals ------------------------------------------------------------------
    `currency`          VARCHAR(10)     NOT NULL DEFAULT 'GBP',
    `subtotal_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `shipping_amount`   DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `tax_amount`        DECIMAL(12,2)   NOT NULL DEFAULT 0.00,   -- VAT
    `tax_rate`          DECIMAL(5,2)    NOT NULL DEFAULT 0.00,   -- e.g. 20.00 (%)
    `total_amount`      DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

    -- Payment (bank transfer or crypto — proof lives in `payments`) -----------
    `payment_method`    VARCHAR(20)          NULL,               -- 'bank' | 'crypto'
    `payment_status`    VARCHAR(20)     NOT NULL DEFAULT 'pending',
    `paid_at`           TIMESTAMP            NULL,

    -- Fulfilment --------------------------------------------------------------
    `delivery_status`   VARCHAR(20)     NOT NULL DEFAULT 'pending',
    `shipping_carrier`  VARCHAR(100)         NULL,
    `shipping_tracking_no` VARCHAR(150)      NULL,
    `delivery_note`     TEXT                 NULL,

    -- Audit -------------------------------------------------------------------
    `raw_payload`       JSON                 NULL,
    `source_ip`         VARCHAR(45)          NULL,
    `created_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_order_ref` (`order_ref`),
    UNIQUE KEY `uniq_tracking_token` (`tracking_token`),
    KEY `idx_customer_email` (`customer_email`),
    KEY `idx_user_id` (`user_id`),
    KEY `idx_payment_status` (`payment_status`),
    KEY `idx_delivery_status` (`delivery_status`),
    KEY `idx_created_at` (`created_at`),
    CONSTRAINT `fk_orders_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- order_items: line items belonging to an order
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `order_items` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`      BIGINT UNSIGNED NOT NULL,
    `product_name`  VARCHAR(255)    NOT NULL,
    `dosage`        VARCHAR(60)          NULL,                   -- e.g. "500mg" | "1000mg"
    `purchase_type` VARCHAR(60)          NULL,                   -- e.g. "Subscribe & Save" | "One-time purchase"
    `variant`       VARCHAR(255)         NULL,                   -- combined label for display
    `sku`           VARCHAR(120)         NULL,
    `image_url`     VARCHAR(500)         NULL,                   -- product thumbnail
    `unit_price`    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,
    `quantity`      INT UNSIGNED    NOT NULL DEFAULT 1,
    `line_total`    DECIMAL(12,2)   NOT NULL DEFAULT 0.00,

    PRIMARY KEY (`id`),
    KEY `idx_order_id` (`order_id`),
    CONSTRAINT `fk_items_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- payments: history of payment proofs submitted by the customer (step 2)
--   status: submitted -> verified | rejected
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `payments` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `order_id`      BIGINT UNSIGNED NOT NULL,
    `method`        VARCHAR(20)     NOT NULL,                    -- 'bank' | 'crypto'
    `amount`        DECIMAL(12,2)        NULL,
    `tx_id`         VARCHAR(200)         NULL,                   -- crypto tx hash / bank reference
    `receipt_path`  VARCHAR(255)         NULL,                   -- stored receipt file (not web-served)
    `note`          TEXT                 NULL,
    `status`        VARCHAR(20)     NOT NULL DEFAULT 'submitted',
    `reject_reason` VARCHAR(255)         NULL,
    `submitted_at`  TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `verified_at`   TIMESTAMP            NULL,

    PRIMARY KEY (`id`),
    KEY `idx_payments_order` (`order_id`),
    CONSTRAINT `fk_payments_order` FOREIGN KEY (`order_id`)
        REFERENCES `orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- password_resets: emailed reset codes (hash only). Code expires; attempts capped.
-- ----------------------------------------------------------------------------
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

-- ----------------------------------------------------------------------------
-- rate_hits: one row per throttled request (sliding window, all in UTC)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_hits` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`     VARCHAR(190)    NOT NULL,                       -- e.g. "create_order:1.2.3.4"
    `created_at` TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_bucket_time` (`bucket`, `created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
-- request_logs: one row per API request (developer view at /logs).
--   No request bodies / secrets are stored — only metadata.
-- ----------------------------------------------------------------------------
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
