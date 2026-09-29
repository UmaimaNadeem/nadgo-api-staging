-- Add GHK-Cu, Klow Blend, Glow Blend products + missing NAD+ 200mg variant
-- Run via phpMyAdmin > Import or: mysql -u USER -p DBNAME < this_file.sql

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────
-- Product 1: add missing 200mg variant
-- (frontend fallback has NADPEN-200-OTP at £149)
-- ─────────────────────────────────────────────
INSERT IGNORE INTO `product_variants`
  (`product_id`, `sku`, `dosage`, `purchase_type`, `label`, `price`, `stock_quantity`)
VALUES
  (1, 'NADPEN-200-OTP', '200mg', 'One-time purchase', 'Nad+ Pen 200mg', 149.00, NULL);

-- ─────────────────────────────────────────────
-- Product 5: GHK-Cu (Copper Peptide)
-- ─────────────────────────────────────────────
INSERT IGNORE INTO `products`
  (`id`, `slug`, `name`, `category`, `currency`, `is_active`)
VALUES
  (5, 'ghk-cu', 'GHK-Cu (Copper Peptide)', 'pen', 'GBP', 1);

INSERT IGNORE INTO `product_variants`
  (`product_id`, `sku`, `dosage`, `label`, `price`, `stock_quantity`)
VALUES
  (5, 'GHK-CU-100MG', '100mg/3ml', 'GHK-Cu 100mg/3ml', 87.99, NULL);

-- ─────────────────────────────────────────────
-- Product 6: Klow Blend
-- ─────────────────────────────────────────────
INSERT IGNORE INTO `products`
  (`id`, `slug`, `name`, `category`, `currency`, `is_active`)
VALUES
  (6, 'klow-blend', 'Klow Blend', 'pen', 'GBP', 1);

INSERT IGNORE INTO `product_variants`
  (`product_id`, `sku`, `dosage`, `label`, `price`, `stock_quantity`)
VALUES
  (6, 'KLOW-BLEND-80MG', '80mg/3ml', 'Klow Blend 80mg/3ml', 129.99, NULL);

-- ─────────────────────────────────────────────
-- Product 7: Glow Blend
-- ─────────────────────────────────────────────
INSERT IGNORE INTO `products`
  (`id`, `slug`, `name`, `category`, `currency`, `is_active`)
VALUES
  (7, 'glow-blend', 'Glow Blend', 'pen', 'GBP', 1);

INSERT IGNORE INTO `product_variants`
  (`product_id`, `sku`, `dosage`, `label`, `price`, `stock_quantity`)
VALUES
  (7, 'GLOW-BLEND-70MG', '70mg/3ml', 'Glow Blend 70mg/3ml', 101.99, NULL);
