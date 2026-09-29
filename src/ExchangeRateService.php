<?php

/**
 * Display-only fiat exchange rates with JSON-file caching.
 *
 * GBP is NadGo's authoritative storefront/order currency. This service only
 * supplies indicative rates for translating GBP price labels in the frontend.
 * Orders and payments continue to be created and settled in GBP.
 */
class ExchangeRateService
{
    private const BASE = 'GBP';
    private const CACHE_TTL = 21600; // 6 hours
    private const QUOTES = [
        'EUR', 'SEK', 'RUB', 'NOK', 'DKK', 'RON', 'HUF', 'SAR', 'AED', 'CNY',
    ];

    private string $cacheFile;

    public function __construct(?string $cacheFile = null)
    {
        $this->cacheFile = $cacheFile ?: dirname(__DIR__) . '/cache/exchange_rates.json';
    }

    /**
     * Returns:
     * [base => GBP, rates => [GBP => 1, EUR => ...], fetched_at => ..., stale => bool]
     */
    public function getRates(): array
    {
        $cached = $this->readCache();
        if ($this->isFresh($cached)) {
            return $this->present($cached, false);
        }

        $fresh = $this->fetchFromFrankfurter();
        if ($fresh !== null) {
            $payload = [
                'base'       => self::BASE,
                'rates'      => [self::BASE => 1.0, ...$fresh],
                'fetched_at' => gmdate('c'),
                'fetched_ts' => time(),
            ];

            $this->writeCache($payload);
            return $this->present($payload, false);
        }

        if ($cached !== null) {
            error_log('[nadgo-api] Frankfurter unavailable; returning stale display FX rates');
            return $this->present($cached, true);
        }

        throw new \RuntimeException('Could not fetch display exchange rates and no cached values exist.');
    }

    private function readCache(): ?array
    {
        if (!is_file($this->cacheFile) || !is_readable($this->cacheFile)) {
            return null;
        }

        $raw = @file_get_contents($this->cacheFile);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if (($data['base'] ?? null) !== self::BASE || !is_array($data['rates'] ?? null)) {
            return null;
        }

        foreach (self::QUOTES as $quote) {
            if (!isset($data['rates'][$quote]) || (float) $data['rates'][$quote] <= 0) {
                return null;
            }
        }

        return $data;
    }

    private function isFresh(?array $cached): bool
    {
        if ($cached === null) return false;

        $fetchedTs = isset($cached['fetched_ts']) ? (int) $cached['fetched_ts'] : 0;
        if ($fetchedTs <= 0 && !empty($cached['fetched_at'])) {
            $parsed = strtotime((string) $cached['fetched_at']);
            $fetchedTs = $parsed !== false ? $parsed : 0;
        }

        return $fetchedTs > 0 && (time() - $fetchedTs) < self::CACHE_TTL;
    }

    private function fetchFromFrankfurter(): ?array
    {
        $url = 'https://api.frankfurter.dev/v2/rates?base=' . self::BASE
             . '&quotes=' . rawurlencode(implode(',', self::QUOTES));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 7,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_USERAGENT      => 'NadGo/1.0',
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false || $code !== 200) {
            error_log('[nadgo-api] Frankfurter fetch failed: ' . ($err ?: "HTTP $code"));
            return null;
        }

        $data = json_decode($res, true);
        if (!is_array($data)) return null;

        $rates = [];
        foreach ($data as $row) {
            $quote = strtoupper((string) ($row['quote'] ?? ''));
            $rate  = (float) ($row['rate'] ?? 0);
            if (in_array($quote, self::QUOTES, true) && $rate > 0) {
                $rates[$quote] = $rate;
            }
        }

        if (count($rates) !== count(self::QUOTES)) {
            error_log('[nadgo-api] Frankfurter response missing one or more requested currencies');
            return null;
        }

        return $rates;
    }

    private function writeCache(array $payload): void
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create exchange-rate cache directory.');
        }

        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Could not encode exchange-rate cache.');
        }

        $tmp = $this->cacheFile . '.tmp';
        if (@file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write exchange-rate cache.');
        }

        if (!@rename($tmp, $this->cacheFile)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not finalize exchange-rate cache.');
        }

        @chmod($this->cacheFile, 0664);
    }

    private function present(array $cached, bool $stale): array
    {
        $rates = [self::BASE => 1.0];
        foreach (self::QUOTES as $quote) {
            $rates[$quote] = (float) $cached['rates'][$quote];
        }

        return [
            'base'       => self::BASE,
            'rates'      => $rates,
            'fetched_at' => $cached['fetched_at'] ?? null,
            'stale'      => $stale,
        ];
    }
}
