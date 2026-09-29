-- Store the GBP-per-USDT exchange rate at the moment a crypto payment is submitted.
-- Allows admin to verify the USDT amount against the rate that was shown to the customer.

ALTER TABLE payments
    ADD COLUMN crypto_rate    DECIMAL(12, 8) NULL
        COMMENT 'GBP per 1 USDT at time of submission; NULL for non-crypto payments'
    AFTER note,
    ADD COLUMN crypto_network VARCHAR(100)   NULL
        COMMENT 'Blockchain network selected by customer (e.g. USDT ERC20); NULL for non-crypto'
    AFTER crypto_rate;
