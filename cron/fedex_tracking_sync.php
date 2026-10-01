<?php
/**
 * NadGo FedEx tracking synchronizer.
 *
 * Recommended cPanel cron:
 *   Every 15 minutes: /usr/local/bin/php /home/CPANEL_USER/public_html/api.nad-go.com/cron/fedex_tracking_sync.php >/dev/null 2>&1
 *
 * Run manually first:
 *   php /home/CPANEL_USER/public_html/api.nad-go.com/cron/fedex_tracking_sync.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__);
$configPath = $root . '/config.php';
if (!is_file($configPath)) {
    fwrite(STDERR, "config.php not found.\n");
    exit(1);
}
$config = require $configPath;

if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
}

require_once $root . '/src/OrderService.php';
require_once $root . '/src/FedExService.php';

try {
    $db      = new Database($config['db']);
    $auth    = new Auth($config);
    $mailer  = new Mailer($config['mail'], $config['brand'] ?? []);
    $uploads = new Uploads($config['uploads']);
    $stripe  = new StripeGateway($config['stripe'] ?? []);
    $fedEx   = new FedExService($config['fedex'] ?? []);

    $service = new OrderService($db, $mailer, $auth, $uploads, $config, $stripe);
    $service->setFedExService($fedEx);

    $result = $service->syncFedExTracking(300);

    echo json_encode([
        'ok' => true,
        'ran_at_utc' => gmdate('c'),
        'result' => $result,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

    exit(empty($result['errors']) ? 0 : 2);
} catch (Throwable $e) {
    error_log('[nadgo-api] FedEx tracking cron failed: ' . $e->getMessage());
    fwrite(STDERR, json_encode([
        'ok' => false,
        'ran_at_utc' => gmdate('c'),
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
