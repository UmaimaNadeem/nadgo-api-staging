-- Fix incorrect product_id mapping caused by coming-soon products occupying IDs 5-9.
-- GHK-Cu, Klow Blend, Glow Blend variants (ids 14-16) were linked to wrong products.
-- This migration adds the three products at IDs 10-12 and re-links the variants.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────
-- 1. Insert the three real products at IDs 10-12
-- ─────────────────────────────────────────────
INSERT IGNORE INTO `products`
  (`id`, `slug`, `name`, `category`, `currency`, `is_active`)
VALUES
  (10, 'ghk-cu',     'GHK-Cu (Copper Peptide)', 'pen', 'GBP', 1),
  (11, 'klow-blend', 'Klow Blend',               'pen', 'GBP', 1),
  (12, 'glow-blend', 'Glow Blend',               'pen', 'GBP', 1);

-- ─────────────────────────────────────────────
-- 2. Re-link the orphaned variants to the correct products
-- ─────────────────────────────────────────────
UPDATE `product_variants` SET `product_id` = 10 WHERE `sku` = 'GHK-CU-100MG';
UPDATE `product_variants` SET `product_id` = 11 WHERE `sku` = 'KLOW-BLEND-80MG';
UPDATE `product_variants` SET `product_id` = 12 WHERE `sku` = 'GLOW-BLEND-70MG';
