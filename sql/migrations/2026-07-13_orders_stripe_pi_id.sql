ALTER TABLE orders
    ADD COLUMN stripe_pi_id VARCHAR(100) NULL AFTER payment_method;
