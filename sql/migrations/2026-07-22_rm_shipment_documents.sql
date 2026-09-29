-- Royal Mail order tracking + order documents
-- Run after 2026-07-22_coupon_per_customer.sql

ALTER TABLE `orders`
  ADD COLUMN IF NOT EXISTS `rm_order_id` VARCHAR(100) DEFAULT NULL
    COMMENT 'Click & Drop orderIdentifier returned on creation'
    AFTER `shipping_tracking_no`;

CREATE TABLE IF NOT EXISTS `order_documents` (
  `id`            INT          NOT NULL AUTO_INCREMENT,
  `order_ref`     VARCHAR(50)  NOT NULL,
  `document_name` VARCHAR(200) NOT NULL,
  `file_name`     VARCHAR(255) NOT NULL,
  `file_path`     VARCHAR(500) NOT NULL,
  `mime_type`     VARCHAR(100) DEFAULT NULL,
  `file_size`     INT          DEFAULT NULL,
  `created_at`    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_doc_order_ref` (`order_ref`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
