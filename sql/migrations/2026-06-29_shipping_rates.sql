-- Flat-rate shipping by country, configurable from the admin portal.
-- 'default' is the fallback used when no country-specific rate exists.

CREATE TABLE IF NOT EXISTS shipping_rates (
    id              INT              NOT NULL AUTO_INCREMENT PRIMARY KEY,
    country         VARCHAR(150)     NOT NULL,
    rate            DECIMAL(10,2)    NOT NULL DEFAULT 0.00,
    free_threshold  DECIMAL(10,2)    NULL     COMMENT 'Subtotal above which shipping is free; NULL = never free',
    is_active       TINYINT(1)       NOT NULL DEFAULT 1,
    created_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_shipping_country (country)
);

INSERT INTO shipping_rates (country, rate, free_threshold, is_active) VALUES
    ('default',                  9.99,  50.00, 1),
    ('United Kingdom',           4.99,  50.00, 1),
    ('Ireland',                  6.99,  75.00, 1),
    ('United States',           14.99,   NULL, 1),
    ('Canada',                  14.99,   NULL, 1),
    ('Australia',               16.99,   NULL, 1),
    ('Germany',                  8.99,  75.00, 1),
    ('France',                   8.99,  75.00, 1),
    ('Spain',                    8.99,  75.00, 1),
    ('Italy',                    8.99,  75.00, 1),
    ('Netherlands',              8.99,  75.00, 1),
    ('United Arab Emirates',    12.99,   NULL, 1)
ON DUPLICATE KEY UPDATE rate = VALUES(rate), free_threshold = VALUES(free_threshold);
