<?php
/**
 * Wallid Payment Gateway integration.
 * https://payment-api.wallid.co/api/payment-gw/v1
 *
 * Auth: HTTP Basic (api_key_id:api_key_secret).
 * All monetary amounts sent to Wallid are in minor units (e.g. 4999 = £49.99).
 */
class WallidService
{
    private const BASE = 'https://payment-api.wallid.co/api/payment-gw/v1';

    public function __construct(private array $config) {}

    // =========================================================================
    // Public
    // =========================================================================

    public function enabled(): bool
    {
        return !empty($this->config['enabled'])
            && !empty($this->config['api_key_id'])
            && !empty($this->config['api_key_secret']);
    }

    /**
     * Create a hosted payment session.
     *
     * Required $params keys:
     *   order_id, amount (int, minor units), currency, success_url, fail_url, items[]
     *
     * Returns ['api_payment_id' => string, 'payment_link' => string].
     */
    public function createPayment(array $params): array
    {
        $res = $this->request('POST', '/create', $params);
        if (empty($res['payment_link'])) {
            throw new \RuntimeException('Wallid did not return a payment link.');
        }
        return [
            'api_payment_id' => (string) ($res['api_payment_id'] ?? ''),
            'payment_link'   => (string)  $res['payment_link'],
        ];
    }

    /**
     * Fetch the current status of a payment.
     */
    public function getStatus(string $apiPaymentId): array
    {
        return $this->request('GET', '/status', ['apiPaymentId' => $apiPaymentId]);
    }

    /**
     * Verify the HMAC-SHA256 signature on an incoming webhook request.
     * Returns true when webhook_secret is not configured (verification disabled).
     *
     * Signed message: "{X-Webhook-Timestamp}.{raw_body}"
     * Signature header format: "sha256=<lowercase hex>"
     */
    public function verifyWebhookSignature(string $timestamp, string $signature, string $rawBody): bool
    {
        if (empty($this->config['webhook_secret'])) {
            return true;
        }
        $message  = $timestamp . '.' . $rawBody;
        $expected = 'sha256=' . hash_hmac('sha256', $message, $this->config['webhook_secret']);
        return hash_equals($expected, $signature);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function request(string $method, string $path, array $payload = []): array
    {
        $url = self::BASE . $path;
        if ($method === 'GET' && !empty($payload)) {
            $url .= '?' . http_build_query($payload);
        }

        $credentials = base64_encode($this->config['api_key_id'] . ':' . $this->config['api_key_secret']);

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Basic ' . $credentials,
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Network error communicating with Wallid: ' . $err);
        }

        // Wallid uses plain-text bodies for error responses
        $data = json_decode((string) $raw, true);

        if ($code === 401) {
            throw new \RuntimeException('Wallid authentication failed. Check your API key credentials.');
        }
        if ($code === 400) {
            $msg = is_string($raw) && trim($raw) !== '' ? trim($raw) : 'Bad request.';
            throw new \RuntimeException('Wallid validation error: ' . $msg);
        }
        if ($code === 404) {
            throw new \RuntimeException('Wallid payment not found.');
        }
        if ($code >= 500) {
            throw new \RuntimeException('Wallid API is currently unavailable (HTTP ' . $code . ').');
        }

        return is_array($data) ? $data : [];
    }
}
