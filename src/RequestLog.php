<?php
/**
 * Per-request developer logging. Stores request METADATA only (never bodies,
 * passwords, tokens or payment details). Viewed via the admin /logs page.
 */
require_once __DIR__ . '/Database.php';

class RequestLog
{
    public function __construct(private Database $db, private array $cfg) {}

    public function enabled(): bool
    {
        return !empty($this->cfg['enabled']);
    }

    /** Insert one log row. Never throws out (logging must not break responses). */
    public function record(array $d): void
    {
        if (!$this->enabled()) {
            return;
        }
        try {
            $this->db->insert(
                'INSERT INTO request_logs (created_at, ip, method, action, status, duration_ms, actor, error, user_agent)
                 VALUES (UTC_TIMESTAMP(), :ip, :m, :a, :s, :d, :actor, :err, :ua)',
                [
                    ':ip'    => $d['ip']          ?? null,
                    ':m'     => $d['method']      ?? null,
                    ':a'     => $d['action']      ?? null,
                    ':s'     => $d['status']      ?? null,
                    ':d'     => $d['duration_ms'] ?? null,
                    ':actor' => $d['actor']       ?: null,
                    ':err'   => isset($d['error']) ? mb_substr((string) $d['error'], 0, 500) : null,
                    ':ua'    => isset($d['user_agent']) ? mb_substr((string) $d['user_agent'], 0, 255) : null,
                ]
            );
            $this->maybeCleanup();
        } catch (\Throwable $e) {
            error_log('[nadgo-api] request log write failed: ' . $e->getMessage());
        }
    }

    /** Recent log rows for the viewer, newest first. */
    public function recent(int $limit = 100, ?string $action = null, ?int $status = null, bool $errorsOnly = false): array
    {
        $where = [];
        $args  = [];
        if ($action)    { $where[] = 'action = :a'; $args[':a'] = $action; }
        if ($status)    { $where[] = 'status = :s'; $args[':s'] = $status; }
        if ($errorsOnly){ $where[] = '(status >= 400 OR error IS NOT NULL)'; }

        $sql = 'SELECT id, created_at, ip, method, action, status, duration_ms, actor, error, user_agent FROM request_logs';
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(1000, $limit));

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * Collect the PHP error_log(s): the ini-configured file plus any cPanel-style
     * per-directory `error_log` files under the app. Returns tails (newest last).
     */
    public function phpErrorLogs(string $baseDir, int $lines = 200): array
    {
        $files = [];
        $ini = (string) ini_get('error_log');
        if ($ini !== '' && is_file($ini) && is_readable($ini)) {
            $files[realpath($ini) ?: $ini] = true;
        }
        // cPanel drops an `error_log` into each directory where an error occurs.
        $candidates = array_merge(
            [$baseDir . '/error_log'],
            glob($baseDir . '/*/error_log') ?: []
        );
        foreach ($candidates as $f) {
            if (is_file($f) && is_readable($f)) {
                $files[realpath($f) ?: $f] = true;
            }
        }

        $out = [];
        foreach (array_keys($files) as $f) {
            $out[] = ['file' => $f, 'size' => @filesize($f) ?: 0, 'tail' => $this->tailFile($f, $lines)];
        }
        return $out;
    }

    /** Efficient tail: read only the trailing bytes needed for $lines lines. */
    private function tailFile(string $file, int $lines): array
    {
        $size = (int) @filesize($file);
        if ($size === 0) {
            return [];
        }
        $fp = @fopen($file, 'rb');
        if (!$fp) {
            return [];
        }
        $chunk = 65536;
        $data  = '';
        $pos   = $size;
        while ($pos > 0 && substr_count($data, "\n") <= $lines) {
            $read = (int) min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $data = fread($fp, $read) . $data;
        }
        fclose($fp);
        $all = preg_split('/\r?\n/', rtrim($data, "\r\n")) ?: [];
        return array_slice($all, -$lines);
    }

    /** Occasionally prune rows older than the retention window. */
    private function maybeCleanup(): void
    {
        $days = (int) ($this->cfg['retention_days'] ?? 14);
        if ($days > 0 && random_int(1, 100) === 1) {
            $this->db->pdo()->exec(
                "DELETE FROM request_logs WHERE created_at < (UTC_TIMESTAMP() - INTERVAL $days DAY)"
            );
        }
    }
}
