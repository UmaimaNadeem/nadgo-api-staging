<?php
/**
 * Minimal Stripe integration over the REST API (no SDK / composer needed):
 *   - create a hosted Checkout Session for an order
 *   - verify incoming webhook signatures
 */
class StripeException extends \RuntimeException {}

class StripeGateway
{
    public function __construct(private array $cfg) {}

    public function enabled(): bool
    {
        $key = (string) ($this->cfg['secret_key'] ?? '');
        return !empty($this->cfg['enabled']) && $key !== '' && !str_starts_with($key, 'sk_live_or_test');
    }

    public function publishableKey(): string
    {
        return (string) ($this->cfg['publishable_key'] ?? '');
    }

    public function successUrl(string $orderRef): string
    {
        return str_replace('{ORDER_REF}', rawurlencode($orderRef), (string) ($this->cfg['success_url'] ?? ''));
    }

    public function cancelUrl(string $orderRef): string
    {
        return str_replace('{ORDER_REF}', rawurlencode($orderRef), (string) ($this->cfg['cancel_url'] ?? ''));
    }

    /**
     * Create a Checkout Session for a single total amount.
     * @return array{id:string,url:string}
     */
    public function createCheckoutSession(array $p): array
    {
        if (!$this->enabled()) {
            throw new StripeException('Stripe is not configured.');
        }
        $form = array_filter([
            'mode'                                            => 'payment',
            'success_url'                                     => $p['success_url'],
            'cancel_url'                                      => $p['cancel_url'],
            'client_reference_id'                             => $p['order_ref'],
            'customer_email'                                  => $p['email'] ?? null,
            'metadata[order_ref]'                             => $p['order_ref'],
            'line_items[0][quantity]'                         => 1,
            'line_items[0][price_data][currency]'             => strtolower($p['currency'] ?? 'gbp'),
            'line_items[0][price_data][unit_amount]'          => (int) round(((float) $p['amount']) * 100),
            'line_items[0][price_data][product_data][name]'   => $p['description'] ?? ('Order ' . $p['order_ref']),
        ], fn($v) => $v !== null && $v !== '');

        $res  = $this->post('https://api.stripe.com/v1/checkout/sessions', $form);
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['url'])) {
            throw new StripeException('Unexpected Stripe response: ' . $res);
        }
        return ['id' => (string) $data['id'], 'url' => (string) $data['url']];
    }

    /** Verify a webhook signature; returns the decoded event or null if invalid. */
    public function verifyWebhook(string $payload, string $sigHeader, int $toleranceSec = 300): ?array
    {
        $secret = (string) ($this->cfg['webhook_secret'] ?? '');
        if ($secret === '' || $sigHeader === '') {
            return null;
        }
        $t = null;
        $v1 = [];
        foreach (explode(',', $sigHeader) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $t = $v;
            if ($k === 'v1') $v1[] = $v;
        }
        if (!$t || !$v1) {
            return null;
        }
        if (abs(time() - (int) $t) > $toleranceSec) {
            return null; // replay protection
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        $match = false;
        foreach ($v1 as $sig) {
            if (hash_equals($expected, $sig)) { $match = true; break; }
        }
        if (!$match) {
            return null;
        }
        $event = json_decode($payload, true);
        return is_array($event) ? $event : null;
    }

    /**
     * Create a PaymentIntent for direct card payments (Stripe Elements on the frontend).
     * @return array{id:string,client_secret:string}
     */
    public function createPaymentIntent(array $p): array
    {
        if (!$this->enabled()) {
            throw new StripeException('Stripe is not configured.');
        }
        $form = array_filter([
            'amount'                    => (int) round(((float) $p['amount']) * 100),
            'currency'                  => strtolower($p['currency'] ?? 'gbp'),
            'metadata[order_ref]'       => $p['order_ref'],
            'description'               => $p['description'] ?? ('Order ' . $p['order_ref']),
        ], fn($v) => $v !== null && $v !== '');

        $res  = $this->post('https://api.stripe.com/v1/payment_intents', $form);
        $data = json_decode($res, true);
        if (!is_array($data) || empty($data['client_secret'])) {
            throw new StripeException('Unexpected Stripe response: ' . $res);
        }
        return [
            'id'            => (string) $data['id'],
            'client_secret' => (string) $data['client_secret'],
        ];
    }

    /** Retrieve a PaymentIntent by ID to check its status. */
    public function retrievePaymentIntent(string $id): ?array
    {
        if (!$this->enabled()) return null;
        $res  = $this->get('https://api.stripe.com/v1/payment_intents/' . rawurlencode($id));
        $data = json_decode($res, true);
        return is_array($data) ? $data : null;
    }

    private function get(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . (string) $this->cfg['secret_key']],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($res === false) {
            throw new StripeException('Stripe request failed: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new StripeException('Stripe API error ' . $code . ': ' . $res);
        }
        return (string) $res;
    }

    private function post(string $url, array $form): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($form),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . (string) $this->cfg['secret_key']],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($res === false) {
            throw new StripeException('Stripe request failed: ' . $err);
        }
        if ($code < 200 || $code >= 300) {
            throw new StripeException('Stripe API error ' . $code . ': ' . $res);
        }
        return (string) $res;
    }
}
