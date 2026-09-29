-- Products & Inventory Management
-- Run via phpMyAdmin > Import or: mysql -u USER -p DBNAME < this_file.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────
-- Products
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `products` (
  `id`          INT UNSIGNED   NOT NULL AUTO_INCREMENT,
  `slug`        VARCHAR(100)   NOT NULL,
  `name`        VARCHAR(200)   NOT NULL,
  `category`    VARCHAR(100)   NOT NULL DEFAULT '',
  `description` TEXT,
  `image_url`   VARCHAR(500),
  `currency`    CHAR(3)        NOT NULL DEFAULT 'GBP',
  `is_active`   TINYINT(1)     NOT NULL DEFAULT 1,
  `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_products_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Product variants (each sellable SKU)
-- ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `product_variants` (
  `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `product_id`     INT UNSIGNED    NOT NULL,
  `sku`            VARCHAR(100)    NOT NULL,
  `dosage`         VARCHAR(50),
  `purchase_type`  VARCHAR(100),
  `label`          VARCHAR(200),
  `price`          DECIMAL(10,2)   NOT NULL,
  `sale_price`     DECIMAL(10,2),
  `stock_quantity` INT,               -- NULL = unlimited, 0 = out of stock
  `is_active`      TINYINT(1)      NOT NULL DEFAULT 1,
  `created_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_variants_sku` (`sku`),
  KEY `idx_variants_product` (`product_id`),
  CONSTRAINT `fk_variants_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─────────────────────────────────────────────
-- Seed: active products
-- ─────────────────────────────────────────────

-- 1. Nad+ Injection Pen
INSERT INTO `products` (`id`, `slug`, `name`, `category`, `currency`, `is_active`) VALUES
  (1, 'nad-injection-pen', 'Nad+ Injection Pen', 'pen', 'GBP', 1);

INSERT INTO `product_variants` (`product_id`, `sku`, `dosage`, `purchase_type`, `label`, `price`, `stock_quantity`) VALUES
  (1, 'NADPEN-500-OTP',  '500mg',  'One-time purchase', 'Nad+ Pen 500mg',  175.00, NULL),
  (1, 'NADPEN-1000-OTP', '1000mg', 'One-time purchase', 'Nad+ Pen 1000mg', 245.00, NULL);

-- 2. Pen Needles
INSERT INTO `products` (`id`, `slug`, `name`, `category`, `currency`, `is_active`) VALUES
  (2, 'pen-needles', 'NadGo™ Pen Needles', 'accessory', 'GBP', 1);

INSERT INTO `product_variants` (`product_id`, `sku`, `label`, `price`, `stock_quantity`) VALUES
  (2, 'NEEDLES-10', 'Pack of 10', 12.00, NULL),
  (2, 'NEEDLES-30', 'Pack of 30', 30.00, NULL);

-- 3. Alcohol Swabs
INSERT INTO `products` (`id`, `slug`, `name`, `category`, `currency`, `is_active`) VALUES
  (3, 'alcohol-swabs', 'Alcohol Swabs', 'accessory', 'GBP', 1);

INSERT INTO `product_variants` (`product_id`, `sku`, `label`, `price`, `stock_quantity`) VALUES
  (3, 'SWABS-24',  'Pack of 24',  9.00, NULL),
  (3, 'SWABS-50',  'Pack of 50',  18.00, NULL),
  (3, 'SWABS-100', 'Pack of 100', 26.00, NULL);

-- 4. Travel Case
INSERT INTO `products` (`id`, `slug`, `name`, `category`, `currency`, `is_active`) VALUES
  (4, 'travel-case', 'NadGo™ Travel Case', 'accessory', 'GBP', 1);

INSERT INTO `product_variants` (`product_id`, `sku`, `label`, `price`, `stock_quantity`) VALUES
  (4, 'TCASE-STD', 'Travel Case', 35.00, NULL);

-- ─────────────────────────────────────────────
-- Seed: coming-soon products (inactive)
-- ─────────────────────────────────────────────
INSERT INTO `products` (`slug`, `name`, `category`, `currency`, `is_active`) VALUES
  ('nad-nasal-spray',   'Nad+ Nasal Spray',   'pen',   'GBP', 0),
  ('nmn-pen',           'NMN Pen',            'pen',   'GBP', 0),
  ('nr-pen',            'NR Pen',             'pen',   'GBP', 0),
  ('glutathione-pen',   'Glutathione Pen',    'pen',   'GBP', 0),
  ('nadgo-bundles',     'NadGo™ Bundles',     'bundle','GBP', 0);
