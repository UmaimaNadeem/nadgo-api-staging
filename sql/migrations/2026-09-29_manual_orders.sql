-- Manual (admin-created) orders
-- Marks how an order was created: 'web' = customer checkout, 'manual' = created in the admin portal.
-- Safe to run more than once.

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `order_source` VARCHAR(20) NOT NULL DEFAULT 'web' AFTER `payment_method`;
