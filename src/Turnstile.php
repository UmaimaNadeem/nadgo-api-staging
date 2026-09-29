<?php
/**
 * Cloudflare Turnstile server-side verification.
 * Confirms the token the browser widget produced really passed Cloudflare's check.
 */
class Turnstile
{
    public function __construct(private array $cfg) {}

    /**
     * @return bool true if the token is valid (or if Turnstile is disabled).
     */
    public function verify(string $token, string $ip = ''): bool
    {
        if (empty($this->cfg['enabled'])) {
            return true; // feature off -> skip
        }

        $secret = (string) ($this->cfg['secret_key'] ?? '');
        if ($secret === '' || $secret === 'CHANGE_ME_TURNSTILE_SECRET') {
            // Enabled but not configured: don't lock everyone out — warn and pass.
            error_log('[nadgo-api] Turnstile enabled but secret_key not configured — skipping.');
            return true;
        }

        if ($token === '') {
            return false;
        }

        $url   = $this->cfg['verify_url'] ?? 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
        $body  = http_build_query(array_filter([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $ip,
        ]));

        $raw = $this->post($url, $body);
        if ($raw === null) {
            // Network/verification error: fail closed (login + honeypot still apply).
            return false;
        }

        $data = json_decode($raw, true);
        return is_array($data) && !empty($data['success']);
    }

    /** POST form-encoded body; returns response string or null on failure. */
    private function post(string $url, string $body): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 4,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            curl_close($ch);
            if ($res === false) {
                error_log('[nadgo-api] Turnstile curl error: ' . $err);
                return null;
            }
            return (string) $res;
        }

        // Fallback without cURL.
        $ctx = stream_context_create(['http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $body,
            'timeout' => 5,
        ]]);
        $res = @file_get_contents($url, false, $ctx);
        return $res === false ? null : $res;
    }
}
