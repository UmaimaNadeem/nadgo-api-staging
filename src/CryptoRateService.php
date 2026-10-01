<?php
require_once __DIR__ . '/Database.php';

/**
 * Provides live exchange rates with DB-backed caching (5-min TTL, stale fallback).
 *
 * Rates stored in crypto_rate_cache (one row per pair):
 *   tether_gbp  — USDT/GBP: gbp_per_usdt, usdt_per_gbp
 *   gbp_aed     — GBP/AED:  gbp_per_aed (in gbp_per_usdt col), aed_per_gbp (in usdt_per_gbp col)
 *
 * A single CoinGecko call fetches tether in both gbp and aed, so both cache rows
 * are refreshed atomically on every cache miss.
 */
class CryptoRateService
{
    private const CURRENCY_USDT = 'tether_gbp';
    private const CURRENCY_AED  = 'gbp_aed';
    private const CACHE_TTL     = 300; // seconds

    public function __construct(private Database $db) {}

    /**
     * USDT/GBP rate.
     * Returns: [currency, gbp_per_usdt, usdt_per_gbp, fetched_at, stale]
     */
    public function getRate(): array
    {
        $row = $this->fetchFromDb(self::CURRENCY_USDT);

        if ($row && time() - strtotime($row['fetched_at']) < self::CACHE_TTL) {
            return [...$row, 'stale' => false];
        }

        $fresh = $this->fetchFromCoinGecko();

        if ($fresh !== null) {
            $this->upsertDb(self::CURRENCY_USDT, $fresh['gbp_per_usdt'], $fresh['usdt_per_gbp']);
            $this->upsertDb(self::CURRENCY_AED,  $fresh['gbp_per_aed'],  $fresh['aed_per_gbp']);
            $row = $this->fetchFromDb(self::CURRENCY_USDT);
            return [...$row, 'stale' => false];
        }

        if ($row) {
            error_log('[nadgo-api] CoinGecko unavailable; returning stale crypto rate');
            return [...$row, 'stale' => true];
        }

        throw new \RuntimeException('Could not fetch crypto exchange rate and no cached value exists.');
    }

    /**
     * GBP/AED rate (for Stripe, whose account is in AED).
     * Returns: [currency, aed_per_gbp, gbp_per_aed, fetched_at, stale]
     *
     * The gbp_aed cache row stores:
     *   gbp_per_usdt column → gbp_per_aed
     *   usdt_per_gbp column → aed_per_gbp
     */
    public function getAedRate(): array
    {
        $row = $this->fetchFromDb(self::CURRENCY_AED);

        if ($row && time() - strtotime($row['fetched_at']) < self::CACHE_TTL) {
            return $this->presentAedRow($row, false);
        }

        $fresh = $this->fetchFromCoinGecko();

        if ($fresh !== null) {
            $this->upsertDb(self::CURRENCY_USDT, $fresh['gbp_per_usdt'], $fresh['usdt_per_gbp']);
            $this->upsertDb(self::CURRENCY_AED,  $fresh['gbp_per_aed'],  $fresh['aed_per_gbp']);
            $row = $this->fetchFromDb(self::CURRENCY_AED);
            return $this->presentAedRow($row, false);
        }

        if ($row) {
            error_log('[nadgo-api] CoinGecko unavailable; returning stale AED rate');
            return $this->presentAedRow($row, true);
        }

        throw new \RuntimeException('Could not fetch GBP/AED rate and no cached value exists.');
    }

    // -------------------------------------------------------------------------

    private function fetchFromDb(string $currency): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT currency, gbp_per_usdt, usdt_per_gbp, fetched_at
               FROM crypto_rate_cache WHERE currency = :c LIMIT 1'
        );
        $stmt->execute([':c' => $currency]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function upsertDb(string $currency, float $rateA, float $rateB): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO crypto_rate_cache (currency, gbp_per_usdt, usdt_per_gbp, fetched_at)
                  VALUES (:c, :a, :b, NOW())
             ON DUPLICATE KEY UPDATE
                  gbp_per_usdt = VALUES(gbp_per_usdt),
                  usdt_per_gbp = VALUES(usdt_per_gbp),
                  fetched_at   = NOW()'
        );
        $stmt->execute([':c' => $currency, ':a' => $rateA, ':b' => $rateB]);
    }

    /**
     * Fetches tether price in both GBP and AED in a single CoinGecko request.
     * The GBP/AED cross rate is derived as: aed_per_gbp = tether_aed / tether_gbp
     */
    private function fetchFromCoinGecko(): ?array
    {
        $ch = curl_init('https://api.coingecko.com/api/v3/simple/price?ids=tether&vs_currencies=gbp,aed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'NadGo/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);

        if ($res === false || $code !== 200) {
            error_log('[nadgo-api] CoinGecko fetch failed: ' . ($err ?: "HTTP $code"));
            return null;
        }

        $data       = json_decode($res, true);
        $gbpPerUsdt = (float) ($data['tether']['gbp'] ?? 0);
        $aedPerUsdt = (float) ($data['tether']['aed'] ?? 0);

        if ($gbpPerUsdt <= 0 || $aedPerUsdt <= 0) {
            error_log('[nadgo-api] CoinGecko returned invalid rate');
            return null;
        }

        // Cross rate via tether as the bridge: 1 GBP = (tether_aed / tether_gbp) AED
        $aedPerGbp = round($aedPerUsdt / $gbpPerUsdt, 8);

        return [
            'gbp_per_usdt' => $gbpPerUsdt,
            'usdt_per_gbp' => round(1 / $gbpPerUsdt, 8),
            'gbp_per_aed'  => round(1 / $aedPerGbp, 8),
            'aed_per_gbp'  => $aedPerGbp,
        ];
    }

    /** Return AED row with semantic field names (columns are shared generically). */
    private function presentAedRow(array $row, bool $stale): array
    {
        return [
            'currency'    => $row['currency'],
            'gbp_per_aed' => (float) $row['gbp_per_usdt'],
            'aed_per_gbp' => (float) $row['usdt_per_gbp'],
            'fetched_at'  => $row['fetched_at'],
            'stale'       => $stale,
        ];
    }
}
