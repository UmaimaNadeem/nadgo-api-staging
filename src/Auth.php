<?php
/**
 * Authentication helpers:
 *   - constant-time API/admin key checks
 *   - stateless, signed login session tokens (HMAC, no session table)
 */
class Auth
{
    public function __construct(private array $config) {}

    // --- shared-secret keys ----------------------------------------------------

    public function checkFrontendKey(string $provided): bool
    {
        $expected = (string) ($this->config['api_key'] ?? '');
        return $this->validKey($expected, $provided, 'CHANGE_ME_FRONTEND_KEY');
    }

    public function checkAdminKey(string $provided): bool
    {
        $expected = (string) ($this->config['admin_api_key'] ?? '');
        return $this->validKey($expected, $provided, 'CHANGE_ME_ADMIN_KEY');
    }

    private function validKey(string $expected, string $provided, string $placeholder): bool
    {
        if ($expected === '' || $expected === $placeholder) {
            return false; // refuse to authenticate against an unset/placeholder key
        }
        return hash_equals($expected, $provided);
    }

    // --- one-time codes (password reset) --------------------------------------

    /** Generate a numeric code of the given length. */
    public function generateCode(int $length = 6): string
    {
        $max = (10 ** max(1, $length)) - 1;
        return str_pad((string) random_int(0, $max), $length, '0', STR_PAD_LEFT);
    }

    /** Hash a code for storage (never store the plaintext code). */
    public function hashCode(string $code): string
    {
        return hash_hmac('sha256', $code, $this->secret());
    }

    // --- login session token (signed, stateless) ------------------------------

    /** Issue a token that proves the holder is logged in as $email. */
    public function issueSessionToken(string $email): array
    {
        $ttl     = (int) ($this->config['session']['ttl_seconds'] ?? 2592000); // 30 days
        $expires = time() + $ttl;
        $payload = base64_encode(json_encode(['email' => strtolower($email), 'exp' => $expires]));
        $token   = $payload . '.' . hash_hmac('sha256', $payload, $this->secret());
        return [
            'session_token' => $token,   // new frontend
            'token'         => $token,   // legacy / production build
            'expires_at'    => $expires,
        ];
    }

    /** Return the verified email for a valid token, or null. */
    public function verifySessionToken(string $token): ?string
    {
        $pfx = '[nadgo-auth] verifySessionToken';
        if ($token === '') {
            error_log("$pfx FAIL: token is empty (header missing)");
            return null;
        }
        $dots = substr_count($token, '.');
        if ($dots !== 1) {
            error_log("$pfx FAIL: expected 1 dot, got $dots — token='" . substr($token, 0, 40) . "...'");
            return null;
        }
        [$payload, $sig] = explode('.', $token, 2);
        $expected = hash_hmac('sha256', $payload, $this->secret());
        if (!hash_equals($expected, $sig)) {
            error_log("$pfx FAIL: HMAC mismatch — payload='" . substr($payload, 0, 40) . "...'");
            return null;
        }
        $data = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($data) || empty($data['email']) || empty($data['exp'])) {
            error_log("$pfx FAIL: bad payload structure — decoded=" . json_encode($data));
            return null;
        }
        if ((int) $data['exp'] < time()) {
            error_log("$pfx FAIL: token expired — exp=" . $data['exp'] . " now=" . time() . " delta=" . (time() - (int)$data['exp']) . "s ago");
            return null;
        }
        error_log("$pfx OK — email={$data['email']} exp={$data['exp']} ttl=" . ((int)$data['exp'] - time()) . "s remaining");
        return (string) $data['email'];
    }

    private function secret(): string
    {
        $s = (string) ($this->config['app_secret'] ?? '');
        if ($s === '' || $s === 'CHANGE_ME_APP_SECRET') {
            // Fail loudly rather than signing with a known/empty secret.
            throw new \RuntimeException('app_secret is not configured.');
        }
        return $s;
    }
}
