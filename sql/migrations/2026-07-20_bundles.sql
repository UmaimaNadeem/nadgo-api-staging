-- NAD+ bundle products for the Bundles page
-- product id 13 = NAD+ 2 Month Supply  (frontend: id=13, SKU=NAD-BUNDLE-2M)
-- product id 14 = NAD+ 3 Month Supply  (frontend: id=14, SKU=NAD-BUNDLE-3M)
--
-- price     = full retail price (shown as strikethrough when sale_price is set)
-- sale_price = discounted bundle price (effective_price the customer pays)
--   2-month: 2 × £245 = £490  →  10% off = £441.00
--   3-month: 3 × £245 = £735  →  15% off = £624.75

SET NAMES utf8mb4;

INSERT IGNORE INTO `products`
  (`id`, `slug`, `name`, `category`, `currency`, `is_active`)
VALUES
  (13, 'nad-bundle-2m', 'NAD+ 2 Month Supply', 'bundle', 'GBP', 1),
  (14, 'nad-bundle-3m', 'NAD+ 3 Month Supply', 'bundle', 'GBP', 1);

INSERT IGNORE INTO `product_variants`
  (`product_id`, `sku`, `label`, `price`, `sale_price`, `stock_quantity`)
VALUES
  (13, 'NAD-BUNDLE-2M', 'NAD+ 2 Month Supply (2 × 1000mg)', 490.00, 441.00,  NULL),
  (14, 'NAD-BUNDLE-3M', 'NAD+ 3 Month Supply (3 × 1000mg)', 735.00, 624.75, NULL);
