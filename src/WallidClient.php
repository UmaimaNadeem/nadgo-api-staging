<?php
declare(strict_types=1);

namespace EuroNutra\Pay\Services;

use RuntimeException;

final class WallidClient
{
    private string $baseUrl;
    private string $apiKeyId;
    private string $apiKeySecret;

    public function __construct()
    {
        $this->baseUrl = rtrim(trim((string)($_ENV['WALLID_API_BASE'] ?? '')), '/');
        $this->apiKeyId = trim((string)($_ENV['WALLID_API_KEY_ID'] ?? ''));
        $this->apiKeySecret = trim((string)($_ENV['WALLID_API_KEY_SECRET'] ?? ''));

        if ($this->baseUrl === '' || $this->apiKeyId === '' || $this->apiKeySecret === '') {
            throw new RuntimeException('Wallid API configuration is incomplete.', 500);
        }
    }

    /** @return array<string,mixed> */
    public function getStatus(string $apiPaymentId): array
    {
        $apiPaymentId = trim($apiPaymentId);
        if ($apiPaymentId === '' || strlen($apiPaymentId) > 128 || !preg_match('/^[A-Za-z0-9._:-]+$/', $apiPaymentId)) {
            throw new RuntimeException('Invalid Wallid API payment id.');
        }

        return $this->request('GET', '/status?apiPaymentId=' . rawurlencode($apiPaymentId));
    }

    /** @return array<string,mixed> */
    public function createPayment(array $payload): array
    {
        if ($payload === []) {
            throw new RuntimeException('Wallid create-payment payload is empty.');
        }
        return $this->request('POST', '/create', $payload);
    }

    /** @return array<string,mixed> */
    private function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize Wallid API request.', 500);
        }

        $headers = ['Accept: application/json'];
        $body = null;
        if ($jsonBody !== null) {
            $body = json_encode($jsonBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $headers[] = 'Content-Type: application/json';
        }

        $options = [
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->apiKeyId . ':' . $this->apiKeySecret,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_FOLLOWLOCATION => false,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Wallid API connection failed' . ($error !== '' ? ': ' . $error : '.'), 502);
        }

        // TEMPORARY WALLID DIAGNOSTIC: log only the HTTP status and provider
        // response body. Authentication credentials and request headers are never
        // logged. Remove this diagnostic after the production response shape is
        // confirmed.
        if (strtoupper($method) === 'POST' && $path === '/create') {
            $diagnosticBody = is_string($raw) ? $raw : '';
            if (strlen($diagnosticBody) > 4096) {
                $diagnosticBody = substr($diagnosticBody, 0, 4096) . '...[truncated]';
            }
            error_log('[euro-nutra-pay] Wallid /create diagnostic HTTP ' . $status . ': ' . $diagnosticBody);
        }

        $decoded = json_decode($raw, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Wallid API request failed (HTTP ' . $status . ').', 502);
        }
        if (!is_array($decoded)) {
            throw new RuntimeException('Wallid API returned an invalid JSON response.', 502);
        }

        return $decoded;
    }
}
