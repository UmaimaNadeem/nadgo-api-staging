<?php
/**
 * DB-backed sliding-window rate limiter. No APCu/Redis needed — works on plain
 * cPanel shared hosting. All timestamps are UTC for tz-independence.
 */
require_once __DIR__ . '/Database.php';

class RateLimitException extends \RuntimeException
{
    public function __construct(string $message, public int $retryAfter)
    {
        parent::__construct($message);
    }
}

class RateLimiter
{
    public function __construct(private Database $db, private array $limits) {}

    /**
     * Record a request against ($action, $identifier). Throws RateLimitException
     * (with a Retry-After hint) when the configured limit is exceeded.
     */
    public function hit(string $action, string $identifier): void
    {
        $rule = $this->limits[$action] ?? null;
        if (!$rule) {
            return; // no limit configured for this action
        }
        $limit  = (int) ($rule['limit'] ?? 0);
        $window = (int) ($rule['window'] ?? 0);
        if ($limit <= 0 || $window <= 0) {
            return; // disabled
        }

        $bucket = $action . ':' . $identifier;
        $pdo    = $this->db->pdo();

        // Count hits in the current window (window is an int from config — safe to inline).
        $count = $pdo->prepare(
            "SELECT COUNT(*) FROM rate_hits
              WHERE bucket = :b AND created_at > (UTC_TIMESTAMP() - INTERVAL $window SECOND)"
        );
        $count->execute([':b' => $bucket]);

        if ((int) $count->fetchColumn() >= $limit) {
            // Retry-After = when the oldest hit in the window falls out of it.
            $oldest = $pdo->prepare(
                "SELECT TIMESTAMPDIFF(SECOND, UTC_TIMESTAMP(), created_at + INTERVAL $window SECOND)
                   FROM rate_hits
                  WHERE bucket = :b AND created_at > (UTC_TIMESTAMP() - INTERVAL $window SECOND)
                  ORDER BY created_at ASC LIMIT 1"
            );
            $oldest->execute([':b' => $bucket]);
            $retry = max(1, (int) $oldest->fetchColumn());
            throw new RateLimitException('Too many requests. Please try again later.', $retry);
        }

        $pdo->prepare('INSERT INTO rate_hits (bucket, created_at) VALUES (:b, UTC_TIMESTAMP())')
            ->execute([':b' => $bucket]);

        $this->maybeCleanup($pdo);
    }

    /** Occasionally sweep rows older than a day so the table stays small. */
    private function maybeCleanup(\PDO $pdo): void
    {
        if (random_int(1, 50) === 1) {
            $pdo->exec('DELETE FROM rate_hits WHERE created_at < (UTC_TIMESTAMP() - INTERVAL 1 DAY)');
        }
    }
}
