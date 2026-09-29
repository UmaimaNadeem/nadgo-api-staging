-- Single-row DB cache for the live USDT/GBP rate (fetched from CoinGecko).
-- One row per currency pair; refreshed on demand when older than 5 minutes.

CREATE TABLE IF NOT EXISTS crypto_rate_cache (
    currency     VARCHAR(20)    NOT NULL,
    gbp_per_usdt DECIMAL(12, 8) NOT NULL,
    usdt_per_gbp DECIMAL(12, 8) NOT NULL,
    fetched_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (currency)
);
