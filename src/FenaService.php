<?php
/**
 * Fena (Toolkit Payments API) Open Banking single-payments integration.
 * https://toolkit-docs.fena.co/toolkit-api/payments-module/single-payments/overview
 *
 * Auth: "terminal-id" / "terminal-secret" headers (per API key, from the Fena dashboard).
 * Amounts sent to Fena are decimal strings in major units (e.g. "49.99").
 *
 * Sandbox vs live is NOT a different API key/base URL — per Fena, "payment mode
 * is determined by the selected beneficiary bank account" (sandbox account ->
 * sandbox payment, live verified account -> live payment). So switching modes
 * here just means switching which bank_account_* config value is sent as
 * `bankAccount`, controlled by `mode`.
 *
 * Fena's webhook payload has no documented HMAC signature. Verification instead
 * relies on a shared secret registered as a query param on the webhook URL
 * configured in the Fena dashboard (see verifyWebhookSecret()).
 */
class FenaService
{
    private const BASE = 'https://epos.api.prod-gcp.fena.co';

    public function __construct(private array $config) {}

    // =========================================================================
    // Public
    // =========================================================================

    /** 'sandbox' (default) or 'live'. Anything else falls back to 'sandbox'. */
    public function mode(): string
    {
        return strtolower((string) ($this->config['mode'] ?? 'sandbox')) === 'live' ? 'live' : 'sandbox';
    }

    public function isLive(): bool
    {
        return $this->mode() === 'live';
    }

    public function enabled(): bool
    {
        if (empty($this->config['enabled'])
            || empty($this->config['terminal_id'])
            || empty($this->config['terminal_secret'])) {
            return false;
        }
        // Live mode requires an explicit verified bank account — omitting it would
        // fall back to the company default account, which may not be the intended
        // one, so treat an unconfigured live account as "not enabled" rather than
        // silently risking a payment to the wrong account.
        if ($this->isLive() && $this->bankAccountId() === null) {
            return false;
        }
        return true;
    }

    /**
     * Create and process a hosted Open Banking payment.
     *
     * Required $params keys: reference (<=12 chars), amount (float, major units).
     * Optional: description, customer_email, customer_name, custom_redirect_url.
     *
     * Returns ['api_payment_id' => string, 'payment_link' => string, 'status' => string].
     */
    public function createPayment(array $params): array
    {
        $body = [
            'reference'     => $params['reference'],
            'amount'        => number_format((float) $params['amount'], 2, '.', ''),
            'paymentMethod' => 'fena_ob',
        ];
        $bankAccount = $this->bankAccountId();
        if ($this->isLive() && $bankAccount === null) {
            throw new \RuntimeException('Fena live mode requires bank_account_live to be configured.');
        }
        if ($bankAccount !== null)                   $body['bankAccount']       = $bankAccount;
        if (!empty($params['description']))          $body['description']      = $params['description'];
        if (!empty($params['customer_email']))       $body['customerEmail']    = $params['customer_email'];
        if (!empty($params['customer_name']))        $body['customerName']     = $params['customer_name'];
        if (!empty($params['custom_redirect_url']))  $body['customRedirectUrl'] = $params['custom_redirect_url'];

        $res    = $this->request('POST', '/open/payments/single/create-and-process', $body);
        $result = $res['result'] ?? [];
        if (empty($result['link']) || empty($result['id'])) {
            throw new \RuntimeException('Fena did not return a payment link.');
        }

        return [
            'api_payment_id' => (string) $result['id'],
            'payment_link'   => (string) $result['link'],
            'status'         => (string) ($result['status'] ?? ''),
        ];
    }

    /**
     * Fetch the current status of a payment.
     */
    public function getStatus(string $paymentId): array
    {
        $res = $this->request('GET', '/open/payments/single/' . rawurlencode($paymentId));
        return $res['data'] ?? [];
    }

    /**
     * Verify the shared secret on an incoming webhook request (passed as a query
     * param on the webhook URL registered in the Fena dashboard — Fena does not
     * sign webhook bodies). Returns true when webhook_secret is not configured
     * (verification disabled), matching WallidService's no-op behaviour.
     */
    public function verifyWebhookSecret(string $providedSecret): bool
    {
        if (empty($this->config['webhook_secret'])) {
            return true;
        }
        return hash_equals((string) $this->config['webhook_secret'], $providedSecret);
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    /** Bank account ID for the active mode, or null to use Fena's default account. */
    private function bankAccountId(): ?string
    {
        $key = $this->isLive() ? 'bank_account_live' : 'bank_account_sandbox';
        $id  = trim((string) ($this->config[$key] ?? ''));
        return $id !== '' ? $id : null;
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init(self::BASE . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'terminal-id: ' . ($this->config['terminal_id'] ?? ''),
                'terminal-secret: ' . ($this->config['terminal_secret'] ?? ''),
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($payload !== null) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Network error communicating with Fena: ' . $err);
        }

        $data = json_decode((string) $raw, true);

        if ($code === 401) {
            throw new \RuntimeException('Fena authentication failed. Check your terminal-id/terminal-secret.');
        }
        if ($code === 403) {
            throw new \RuntimeException('Fena access denied. Check your API key policies.');
        }
        if ($code === 404) {
            throw new \RuntimeException('Fena payment not found.');
        }
        if ($code >= 500) {
            throw new \RuntimeException('Fena API is currently unavailable (HTTP ' . $code . ').');
        }
        if ($code >= 400) {
            $msg = is_array($data) ? json_encode($data) : trim((string) $raw);
            throw new \RuntimeException('Fena request error: ' . ($msg ?: 'Bad request.'));
        }

        return is_array($data) ? $data : [];
    }
}
