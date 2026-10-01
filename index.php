<?php
/**
 * Nadgo Orders API — single entry point / action router.
 *
 * All requests target this file with ?action=<name>. JSON endpoints expect a
 * JSON body; submit_payment expects multipart/form-data (file upload).
 *
 *   Accounts:  register | login
 *   Customer:  create_order | payment_info | submit_payment
 *              track_orders | get_order | get_profile | update_profile | change_password
 *              product_review | contact
 *              create_fena_payment | fena_webhook
 *              create_wallid_payment | wallid_webhook
 *   Products:  get_product (public)
 *              notify_me_status | notify_me_toggle (logged-in only)
 *   Coupons:   validate_coupon (public)
 *              admin_list_coupons | admin_save_coupon | admin_delete_coupon
 *   Admin:     admin_list_orders | admin_verify_payment
 *              admin_create_order | admin_search_customers   (manual orders)
 *              admin_update_delivery | admin_get_receipt | admin_test_email
 *              admin_list_products | admin_save_product | admin_save_variant | admin_adjust_stock
 *              admin_create_shipment
 *              admin_list_contact_submissions | admin_list_notify_requests
 *
 * Auth:
 *   X-API-Key        -> register/login/create_order/change_password (frontend key)
 *   X-Admin-Key      -> admin_*                             (admin token)
 *   X-Session-Token  -> logged-in user (from register/login)
 *   tracking_token   -> payment_info/submit_payment/get_order (per-order link)
 */

declare(strict_types=1);

$__t0 = microtime(true); // request start, for logging duration

// --- load config -------------------------------------------------------------
$configPath = __DIR__ . '/config.php';
if (!is_file($configPath)) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Server not configured (config.php missing).']);
    exit;
}
$config = require $configPath;

if (is_file(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}
ini_set('display_errors', !empty($config['debug']) ? '1' : '0');
error_reporting(E_ALL);

require_once __DIR__ . '/src/OrderService.php';
require_once __DIR__ . '/src/ProductService.php';
require_once __DIR__ . '/src/ShippingService.php';
require_once __DIR__ . '/src/CryptoRateService.php';
require_once __DIR__ . '/src/ExchangeRateService.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/Turnstile.php';
require_once __DIR__ . '/src/RequestLog.php';
require_once __DIR__ . '/src/StripeGateway.php';
require_once __DIR__ . '/src/RoyalMailService.php';
require_once __DIR__ . '/src/FedExService.php';
require_once __DIR__ . '/src/DeliveryNoteService.php';
require_once __DIR__ . '/src/CouponService.php';
require_once __DIR__ . '/src/WallidService.php';
require_once __DIR__ . '/src/FenaService.php';

// --- helpers -----------------------------------------------------------------
function respond(int $status, array $body): never
{
    if ($status >= 400 && isset($body['error'])) {
        $GLOBALS['__log_error'] = (string) $body['error']; // surfaced in /logs
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
function jsonBody(): array
{
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        respond(400, ['ok' => false, 'error' => 'Request body must be valid JSON.']);
    }
    return $data;
}
function header_val(string $name): string
{
    $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return (string) ($_SERVER[$key] ?? '');
}

// --- CORS --------------------------------------------------------------------
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin !== '' && in_array($origin, $config['cors_allowed_origins'] ?? [], true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Admin-Key, X-Session-Token, X-Tracking-Token, CF-Turnstile-Token');
    header('Access-Control-Max-Age: 86400');
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// --- root: serve the admin portal (GET / with no action) ---------------------
// The API is consumed via ?action=...; a plain GET to the root shows the admin
// login page so it lives at https://api.nad-go.com/ directly.
$action = (string) ($_GET['action'] ?? '');
if ($action === '') {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET') {
        $path = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), '/');
        // /logs -> developer log viewer (admin token entered in-page); / -> admin portal.
        $page = str_ends_with($path, '/logs') ? '/admin/logs.html' : '/admin/index.html';
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . $page);
        exit;
    }
    respond(404, ['ok' => false, 'error' => 'Unknown action.']);
}

$clientIp = (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

// --- wire up services --------------------------------------------------------
try {
    $db      = new Database($config['db']);
    $auth    = new Auth($config);
    $mailer  = new Mailer($config['mail'], $config['brand'] ?? []);
    $uploads = new Uploads($config['uploads']);
    // Built-in default for change_password; any value in config.php 'rate_limits' overrides it.
    $limiter   = new RateLimiter($db, ($config['rate_limits'] ?? []) + [
        'change_password' => ['limit' => 10, 'window' => 900],   // per IP+email / 15 min (anti brute-force)
    ]);
    $turnstile = new Turnstile($config['turnstile'] ?? []);
    $logger    = new RequestLog($db, $config['logging'] ?? []);
    $stripe         = new StripeGateway($config['stripe'] ?? []);
    $productService  = new ProductService($db, $mailer);
    $shippingService = new ShippingService($db);
    $couponService   = new CouponService($db);
    $service         = new OrderService($db, $mailer, $auth, $uploads, $config, $stripe);
    $fedEx = new FedExService($config['fedex'] ?? []);
    $deliveryNote = new DeliveryNoteService();
    $service->setProductService($productService);
    $service->setCouponService($couponService);
    $service->setShippingService($shippingService);
    $service->setFedExService($fedEx);
    $wallid = new WallidService($config['wallid'] ?? []);
    $service->setWallidService($wallid);
    $fena = new FenaService($config['fena'] ?? []);
    $service->setFenaService($fena);
    $royalMail = new RoyalMailService($config['royal_mail'] ?? []);


    // Log every API request (metadata only) once the response is finished.
    register_shutdown_function(function () use ($logger, $auth, $clientIp, $action, $__t0) {
        if ($action === 'admin_logs' || $action === 'admin_php_log') {
            return; // don't log the log-viewer's own polling
        }
        $status = http_response_code() ?: 0;
        $error  = $GLOBALS['__log_error'] ?? null;
        $fatal  = error_get_last();
        if (!$error && $fatal && in_array($fatal['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $error  = $fatal['message'];
            $status = $status >= 500 ? $status : 500;
        }
        $logger->record([
            'ip'          => $clientIp,
            'method'      => $_SERVER['REQUEST_METHOD'] ?? '',
            'action'      => $action,
            'status'      => $status,
            'duration_ms' => (int) round((microtime(true) - $__t0) * 1000),
            'actor'       => $auth->verifySessionToken(header_val('X-Session-Token')),
            'error'       => $error,
            'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ]);
    });
} catch (\Throwable $e) {
    error_log('[nadgo-api] bootstrap: ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => !empty($config['debug']) ? $e->getMessage() : 'Server error.']);
}

// --- access-control helper for per-order endpoints ---------------------------
$resolveOrderAccess = function (string $ref) use ($service, $auth): array {
    $order = $service->findByRef($ref);
    if (!$order) {
        respond(404, ['ok' => false, 'error' => 'Order not found.']);
    }
    $token       = header_val('X-Tracking-Token') ?: (string) ($_REQUEST['tracking_token'] ?? '');
    $sessionMail = $auth->verifySessionToken(header_val('X-Session-Token'));
    if (!$service->authorizeOrderAccess($order, $token ?: null, $sessionMail)) {
        respond(403, ['ok' => false, 'error' => 'Not authorized for this order.']);
    }
    return $order;
};

// --- dispatch ----------------------------------------------------------------
try {
    switch ($action) {

        // ---- accounts: register / login -------------------------------------
        case 'register':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            if (!$turnstile->verify((string) ($b['turnstile_token'] ?? header_val('CF-Turnstile-Token')), $clientIp)) {
                respond(403, ['ok' => false, 'error' => 'Bot verification failed. Please try again.']);
            }
            $limiter->hit('register', $clientIp);
            respond(201, ['ok' => true] + $service->register($b));

        case 'login':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            $email = (string) ($b['email'] ?? '');
            $limiter->hit('login', $clientIp . '|' . strtolower($email));
            respond(200, ['ok' => true] + $service->login($email, (string) ($b['password'] ?? '')));

        // ---- forgot password: email a reset code, then set a new password ---
        case 'request_password_reset':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            if (!$turnstile->verify((string) ($b['turnstile_token'] ?? header_val('CF-Turnstile-Token')), $clientIp)) {
                respond(403, ['ok' => false, 'error' => 'Bot verification failed. Please try again.']);
            }
            $email = (string) ($b['email'] ?? '');
            $limiter->hit('request_password_reset', $clientIp . '|' . strtolower($email));
            $limiter->hit('request_password_reset_ip', $clientIp);
            $service->requestPasswordReset($email);
            respond(200, ['ok' => true, 'message' => 'If the email has an account, a reset code has been sent.']);

        case 'reset_password':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            $email = (string) ($b['email'] ?? '');
            $limiter->hit('reset_password', $clientIp . '|' . strtolower($email));
            respond(200, ['ok' => true] + $service->resetPassword(
                $email, (string) ($b['code'] ?? ''), (string) ($b['password'] ?? '')
            ));

        // ---- contact / request-more-info (emails admins, no DB) -------------
        case 'contact':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            if (!$turnstile->verify((string) ($b['turnstile_token'] ?? header_val('CF-Turnstile-Token')), $clientIp)) {
                respond(403, ['ok' => false, 'error' => 'Bot verification failed. Please try again.']);
            }
            $limiter->hit('contact', $clientIp);
            $r = $service->submitContact($b);
            respond($r['sent'] ? 200 : 502, [
                'ok'      => $r['sent'],
                'message' => $r['sent'] ? 'Thanks — your request has been sent.' : 'Could not send right now, please try again.',
            ]);

        // ---- product review (emails admins/contact, no DB) ------------------
        case 'product_review':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            if (!$turnstile->verify((string) ($b['turnstile_token'] ?? header_val('CF-Turnstile-Token')), $clientIp)) {
                respond(403, ['ok' => false, 'error' => 'Bot verification failed. Please try again.']);
            }
            $limiter->hit('contact', $clientIp);
            $r = $service->submitProductReview($b);
            respond($r['sent'] ? 200 : 502, [
                'ok'      => $r['sent'],
                'message' => $r['sent'] ? 'Thanks — your review has been submitted.' : 'Could not send right now, please try again.',
            ]);

        // ---- STEP 1: create order (guest, logged in, or register inline) ------
        case 'create_order':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            $sessionEmail = $auth->verifySessionToken(header_val('X-Session-Token'));
            // Every logged-out checkout (guest or account creation) must clear bot verification.
            if (!$sessionEmail
                && !$turnstile->verify((string) ($b['turnstile_token'] ?? header_val('CF-Turnstile-Token')), $clientIp)) {
                respond(403, ['ok' => false, 'error' => 'Bot verification failed. Please try again.']);
            }

            $createAccount = !$sessionEmail && !empty($b['create_account']);
            if ($sessionEmail) {
                $actorEmail = strtolower($sessionEmail);
                $b['_guest_checkout'] = false;
            } elseif ($createAccount) {
                // Inline account creation keeps the previous authenticated-order behaviour.
                $actorEmail = $service->authenticateForOrder(null, $b);
                $b['_guest_checkout'] = false;
            } else {
                // True guest checkout: validate the supplied email but do not create/link a user account.
                $actorEmail = strtolower(trim((string) ($b['email'] ?? $b['customer_email'] ?? '')));
                if (!filter_var($actorEmail, FILTER_VALIDATE_EMAIL)) {
                    respond(422, ['ok' => false, 'error' => 'A valid email is required.']);
                }
                $b['_guest_checkout'] = true;
            }

            $b['email'] = $actorEmail;
            $limiter->hit('create_order', $clientIp);
            $result = $service->createOrder($b, $clientIp);
            // Card method -> create a Stripe Checkout session; frontend redirects to it.
            // If Stripe fails, the order still exists (pending) — report, don't 500.
            if (($b['payment_method'] ?? '') === 'card') {
                try {
                    $result += $service->createStripeCheckout($result['order_ref']);
                } catch (\Throwable $e) {
                    error_log('[nadgo-api] stripe checkout failed: ' . $e->getMessage());
                    $result['checkout_error'] = 'Could not start card payment. Choose another method or try again.';
                }
            }
            respond(201, ['ok' => true] + $result);

        // ---- Stripe webhook (verified by signature; no API key) -------------
        case 'stripe_webhook':
            $payload = file_get_contents('php://input') ?: '';
            $event = $stripe->verifyWebhook($payload, $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
            if ($event === null) {
                respond(400, ['ok' => false, 'error' => 'Invalid signature.']);
            }
            $evType = $event['type'] ?? '';

            // Hosted Checkout Session flow (currently unused by the frontend, kept for future use)
            if ($evType === 'checkout.session.completed') {
                $s = $event['data']['object'] ?? [];
                if (($s['payment_status'] ?? '') === 'paid') {
                    $ref = $s['metadata']['order_ref'] ?? ($s['client_reference_id'] ?? '');
                    if ($ref !== '') {
                        $service->markCardPaid(
                            (string) $ref,
                            (string) ($s['payment_intent'] ?? $s['id'] ?? ''),
                            isset($s['amount_total']) ? ((int) $s['amount_total']) / 100 : null
                        );
                    }
                }
            }

            // Elements flow safety net: fires after stripe.confirmCardPayment() succeeds.
            // Handles the edge case where the browser dies before confirm_card_payment action is called.
            if ($evType === 'payment_intent.succeeded') {
                $pi  = $event['data']['object'] ?? [];
                $ref = $pi['metadata']['order_ref'] ?? '';
                if ($ref !== '') {
                    $service->markCardPaid(
                        (string) $ref,
                        (string) ($pi['id'] ?? ''),
                        isset($pi['amount']) ? ((int) $pi['amount']) / 100 : null
                    );
                }
            }

            respond(200, ['ok' => true]);

        // ---- STEP 2: show payment instructions + order (per-order auth) -----
        case 'payment_info':
            $order = $resolveOrderAccess((string) ($_GET['ref'] ?? ''));
            respond(200, [
                'ok'                   => true,
                'order'                => $service->presentOrder($order),
                'payment_instructions' => $service->paymentInstructions(),
            ]);

        // ---- STEP 2: submit payment proof (multipart, per-order auth) -------
        case 'submit_payment':
            requirePost();
            honeypotCheck($_POST);
            $limiter->hit('submit_payment', $clientIp);
            $order = $resolveOrderAccess((string) ($_POST['ref'] ?? $_GET['ref'] ?? ''));
            $result = $service->submitPayment($order, $_POST, $_FILES['receipt'] ?? null);
            respond(201, ['ok' => true] + $result);

        // ---- STEP 2a: create a Fena Open Banking hosted payment --------------------
        case 'create_fena_payment':
            $order      = $resolveOrderAccess((string) ($_GET['ref'] ?? ''));
            $fcfg       = $config['fena'] ?? [];
            $successUrl = $fcfg['success_url'] ?? (($config['brand']['website_url'] ?? '') . '/order/{ORDER_REF}?fena=1');
            respond(200, ['ok' => true] + $service->createFenaPayment($order['order_ref'], $successUrl));

        // ---- STEP 2b: Fena webhook (Fena POSTs here on payment status changes) -----
        // Fena does not sign webhook bodies; a shared secret configured on the
        // webhook URL (?...&secret=xxx) in the Fena dashboard stands in for a signature.
        case 'fena_webhook':
            $rawBody = (string) file_get_contents('php://input');
            if (!$fena->verifyWebhookSecret((string) ($_GET['secret'] ?? ''))) {
                error_log('[nadgo-api] fena_webhook: secret mismatch');
                respond(400, ['ok' => false, 'error' => 'Invalid secret.']);
            }
            $fPayload = json_decode($rawBody, true);
            if (!is_array($fPayload)) {
                respond(400, ['ok' => false, 'error' => 'Invalid payload.']);
            }
            if (($fPayload['eventName'] ?? '') === 'status-update' && ($fPayload['status'] ?? '') === 'paid') {
                $fRef = (string) ($fPayload['reference'] ?? '');
                if ($fRef !== '') {
                    $fAmount = isset($fPayload['amount']) ? (float) $fPayload['amount'] : null;
                    try {
                        $service->confirmFenaPayment($fRef, (string) ($fPayload['id'] ?? ''), $fAmount);
                    } catch (\Throwable $e) {
                        error_log('[nadgo-api] fena_webhook confirm error: ' . $e->getMessage() . ' ref=' . $fRef);
                    }
                }
            }
            respond(200, ['ok' => true]);

        // ---- STEP 2a: create a Wallid hosted payment session ----------------------
        case 'create_wallid_payment':
            $order      = $resolveOrderAccess((string) ($_GET['ref'] ?? ''));
            $wcfg       = $config['wallid'] ?? [];
            $successUrl = $wcfg['success_url'] ?? (($config['brand']['website_url'] ?? '') . '/order/{ORDER_REF}?wallid=1');
            $failUrl    = $wcfg['fail_url']    ?? (($config['brand']['website_url'] ?? '') . '/checkout?canceled=1');
            respond(200, ['ok' => true] + $service->createWallidPayment($order['order_ref'], $successUrl, $failUrl));

        // ---- STEP 2b: Wallid webhook (Wallid POSTs here on payment status changes) ---
        case 'wallid_webhook':
            $rawBody   = (string) file_get_contents('php://input');
            $timestamp = header_val('X-Webhook-Timestamp');
            $signature = header_val('X-Webhook-Signature');
            if (!$wallid->verifyWebhookSignature($timestamp, $signature, $rawBody)) {
                error_log('[nadgo-api] wallid_webhook: signature mismatch');
                respond(400, ['ok' => false, 'error' => 'Invalid signature.']);
            }
            if ($timestamp !== '' && abs(time() - (int) $timestamp) > 300) {
                respond(400, ['ok' => false, 'error' => 'Stale timestamp.']);
            }
            $wPayload = json_decode($rawBody, true);
            if (!is_array($wPayload)) {
                respond(400, ['ok' => false, 'error' => 'Invalid payload.']);
            }
            foreach ($wPayload['events'] ?? [] as $wEvent) {
                if (($wEvent['status'] ?? '') === 'SUCCESS' && !empty($wEvent['order_id'])) {
                    $wAmount = isset($wEvent['amount']) ? round((float) $wEvent['amount'] / 100, 2) : null;
                    try {
                        $service->confirmWallidPayment(
                            (string) $wEvent['order_id'],
                            (string) ($wEvent['api_payment_id'] ?? ''),
                            $wAmount
                        );
                    } catch (\Throwable $e) {
                        error_log('[nadgo-api] wallid_webhook confirm error: ' . $e->getMessage() . ' order=' . $wEvent['order_id']);
                    }
                }
            }
            respond(200, ['ok' => true]);

        // ---- STEP 3a: create a Stripe PaymentIntent for card entry (Stripe Elements) ----
        case 'create_card_payment':
            $order = $resolveOrderAccess((string) ($_GET['ref'] ?? ''));
            $result = $service->createCardPaymentIntent($order['order_ref']);
            respond(200, ['ok' => true] + $result);

        // ---- STEP 2b: confirm that a PaymentIntent succeeded (called after frontend confirm) ----
        case 'confirm_card_payment':
            requirePost();
            $b = jsonBody();
            honeypotCheck($b);
            $order = $resolveOrderAccess((string) ($b['ref'] ?? ''));
            $result = $service->confirmCardPayment($order['order_ref'], (string) ($b['payment_intent_id'] ?? ''));
            respond(200, ['ok' => true] + $result);

        // ---- refresh session (extend expiry for active users) ------------------
        case 'refresh_session':
            $email = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$email) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session.']);
            }
            respond(200, ['ok' => true] + $auth->issueSessionToken($email));

        case 'track_orders':
            requirePost();
            $rawTok = header_val('X-Session-Token');
            error_log('[nadgo-api] track_orders: X-Session-Token received length=' . strlen($rawTok) . ' preview=' . substr($rawTok, 0, 20));
            $email = $auth->verifySessionToken($rawTok);
            if (!$email) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Verify your email again.']);
            }
            respond(200, ['ok' => true, 'orders' => $service->listOrdersByEmail($email)]);

        case 'get_order':
            $order = $resolveOrderAccess((string) ($_GET['ref'] ?? ''));
            respond(200, ['ok' => true, 'order' => $service->presentOrder($order, true)]);

        // ---- user profile (logged-in session; address etc. are editable) ----
        case 'get_profile':
            $email = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$email) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Verify your email again.']);
            }
            respond(200, ['ok' => true, 'profile' => $service->getProfile($email)]);

        case 'update_profile':
            requirePost();
            $email = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$email) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Verify your email again.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            respond(200, ['ok' => true, 'profile' => $service->updateProfile($email, $b)]);

        // ---- change password (logged-in): headers X-API-Key + X-Session-Token;
        //      body {website:"", current_password, new_password[, confirm_password]} ----
        case 'change_password':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $email = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$email) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Please log in again.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            $limiter->hit('change_password', $clientIp . '|' . strtolower($email));
            $pick = static function (array $d, array $keys): string {
                foreach ($keys as $k) {
                    if (isset($d[$k]) && is_string($d[$k])) return $d[$k];
                }
                return '';
            };
            respond(200, ['ok' => true] + $service->changePassword(
                $email,
                $pick($b, ['current_password', 'old_password', 'currentPassword']),
                $pick($b, ['new_password', 'newPassword', 'password']),
                array_key_exists('confirm_password', $b) ? (string) $b['confirm_password'] : null
            ));

        // ---- admin (single admin token) -------------------------------------
        case 'admin_logs':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'logs' => $logger->recent(
                (int) ($_GET['limit'] ?? 200),
                ($_GET['action_filter'] ?? '') ?: null,
                ($_GET['status'] ?? '') ? (int) $_GET['status'] : null,
                !empty($_GET['errors_only'])
            )]);

        case 'admin_php_log':
            requireAdmin($auth);
            respond(200, [
                'ok'         => true,
                'configured' => ini_get('error_log') ?: null,
                'files'      => $logger->phpErrorLogs(__DIR__, (int) ($_GET['lines'] ?? 200)),
            ]);

        case 'admin_test_email':
            requireAdmin($auth);
            $to = (string) ($_GET['to'] ?? $_POST['to'] ?? '');
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                respond(422, ['ok' => false, 'error' => 'Provide a valid ?to= email address.']);
            }
            $sent = $mailer->sendTest($to);
            respond($sent ? 200 : 502, [
                'ok'        => $sent,
                'to'        => $to,
                'transport' => $config['mail']['transport'] ?? null,
                'message'   => $sent ? 'Test email sent.' : 'Send failed.',
                'error'     => $sent ? null : $mailer->lastError(),
            ]);

        case 'admin_list_orders':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'orders' => $service->adminListOrders(
                ($_GET['payment_status'] ?? '') ?: null,
                ($_GET['delivery_status'] ?? '') ?: null,
                (int) ($_GET['limit'] ?? 100)
            )]);

        case 'admin_search_customers':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'customers' => $service->adminSearchCustomers(
                (string) ($_GET['q'] ?? ''),
                (int) ($_GET['limit'] ?? 10)
            )]);

        case 'admin_create_order':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            respond(201, ['ok' => true] + $service->adminCreateManualOrder($b, $clientIp));

        case 'admin_verify_payment':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            respond(200, ['ok' => true] + $service->adminVerifyPayment(
                (string) ($b['ref'] ?? ''),
                (string) ($b['decision'] ?? ''),
                ($b['reject_reason'] ?? null) ? (string) $b['reject_reason'] : null
            ));

        case 'admin_update_delivery':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            respond(200, ['ok' => true] + $service->adminUpdateDelivery((string) ($b['ref'] ?? ''), $b));

        case 'admin_create_shipment':
            requirePost();
            requireAdmin($auth);
            $b   = jsonBody();
            $ref = trim((string) ($b['ref'] ?? ''));
            if ($ref === '') respond(400, ['ok' => false, 'error' => 'ref is required.']);
            $order = $service->findByRef($ref);
            if (!$order) respond(404, ['ok' => false, 'error' => 'Order not found.']);
            if (!empty($order['rm_order_id'])) {
                respond(409, ['ok' => false, 'error' => 'A Royal Mail shipment already exists for this order. Delete it first.']);
            }
            $result = $royalMail->createShipment($order, [
                'weight_grams'     => (int) ($b['weight_grams']    ?? 500),
                'package_format'   => (string) ($b['package_format']  ?? 'smallParcel'),
                'service_code'     => (string) ($b['service_code']    ?? ''),
                'address_override' => $b['address_override'] ?? null,
            ]);
            $service->saveRmShipment($ref, (string) ($result['rm_order_id'] ?? ''), $result['tracking_number'] ?? null);
            respond(200, ['ok' => true] + $result);

        case 'admin_create_fedex_shipment':
            requirePost();
            requireAdmin($auth);
            $b   = jsonBody();
            $ref = trim((string) ($b['ref'] ?? ''));

            if ($ref === '') {
                respond(400, ['ok' => false, 'error' => 'ref is required.']);
            }

            $order = $service->findByRef($ref);
            if (!$order) {
                respond(404, ['ok' => false, 'error' => 'Order not found.']);
            }

            if (strcasecmp(trim((string) ($order['shipping_carrier'] ?? '')), 'FedEx') !== 0) {
                respond(422, ['ok' => false, 'error' => 'This order is not a FedEx order.']);
            }

            if (($order['payment_status'] ?? '') !== 'paid') {
                respond(409, ['ok' => false, 'error' => 'The order must be paid before creating a FedEx shipment.']);
            }

            if (!in_array((string) ($order['delivery_status'] ?? ''), ['pending', 'processing'], true)) {
                respond(409, ['ok' => false, 'error' => 'A FedEx shipment can only be created for a pending or processing order.']);
            }

            if (!empty($order['shipping_tracking_no'])) {
                respond(409, ['ok' => false, 'error' => 'A FedEx shipment already exists for this order.']);
            }

            $serviceType = strtoupper(trim((string) ($order['shipping_method_code'] ?? '')));
            if ($serviceType === '' || !str_starts_with($serviceType, 'FEDEX_')) {
                respond(422, ['ok' => false, 'error' => 'The order does not contain a valid FedEx service code.']);
            }

            if (!$fedEx->isConfigured()) {
                respond(503, ['ok' => false, 'error' => 'FedEx is not configured.']);
            }

            try {
                $recipient = [
                    'contact_name' => trim(
                        (string) ($order['ship_first_name'] ?? '') . ' ' .
                        (string) ($order['ship_last_name'] ?? '')
                    ),
                    'company_name' => '',
                    'phone'        => (string) ($order['customer_phone'] ?? ''),
                    'street_lines' => array_values(array_filter([
                        trim((string) ($order['ship_address1'] ?? '')),
                        trim((string) ($order['ship_address2'] ?? '')),
                    ], static fn($v) => $v !== '')),
                    'city'         => (string) ($order['ship_city'] ?? ''),
                    'postal_code'  => (string) ($order['ship_postcode'] ?? ''),
                    'country_code' => 'GB',
                ];

                $result = $fedEx->createShipment(
                    $recipient,
                    [
                        'weight'      => 1.0,
                        'weight_unit' => 'KG',
                        'dimensions'  => null,
                    ],
                    $serviceType
                );

                $trackingNumber = trim((string) ($result['tracking_number'] ?? ''));
                $labelBase64    = (string) ($result['label_base64'] ?? '');

                if ($trackingNumber === '') {
                    throw new RuntimeException('FedEx created the shipment but did not return a tracking number.');
                }
                if ($labelBase64 === '') {
                    throw new RuntimeException('FedEx created the shipment but did not return a shipping label.');
                }

                $saved = $fedEx->saveLabel(
                    $labelBase64,
                    __DIR__ . '/uploads/fedex-labels',
                    $trackingNumber
                );

                $service->saveFedExShipment($ref, $trackingNumber);

                unset($result['label_base64']);
                $result['label_available'] = is_file($saved);
                respond(200, ['ok' => true] + $result);
            } catch (\InvalidArgumentException $e) {
                respond(422, ['ok' => false, 'error' => $e->getMessage()]);
            } catch (FedExApiException $e) {
                error_log('[nadgo-api] FedEx live shipment error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()] + $e->diagnostics());
            } catch (\Throwable $e) {
                error_log('[nadgo-api] FedEx live shipment error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'admin_create_delivery_note':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $ref = trim((string) ($b['ref'] ?? ''));
            if ($ref === '') {
                respond(400, ['ok' => false, 'error' => 'ref is required.']);
            }
            $order = $service->findByRef($ref);
            if (!$order) {
                respond(404, ['ok' => false, 'error' => 'Order not found.']);
            }
            try {
                // Independent warehouse document: no FedEx API request and no
                // shipment/tracking/order status changes.
                $view = $service->presentOrder($order);
                $tracking = trim((string) ($order['shipping_tracking_no'] ?? ''));
                $path = $deliveryNote->generate(
                    $view,
                    $tracking,
                    __DIR__ . '/uploads/delivery-notes'
                );
                if (!is_file($path) || filesize($path) === 0) {
                    throw new RuntimeException('Delivery note PDF was not saved correctly.');
                }
                respond(200, [
                    'ok' => true,
                    'delivery_note_available' => true,
                    'filename' => basename($path),
                ]);
            } catch (\Throwable $e) {
                error_log('[nadgo-api] Delivery note generation failed for ' . $ref . ': ' . $e->getMessage());
                respond(500, ['ok' => false, 'error' => 'Delivery note generation failed: ' . $e->getMessage()]);
            }

        case 'admin_get_delivery_note':
            requireAdmin($auth);
            $ref = trim((string) ($_GET['ref'] ?? ''));

            if ($ref === '') {
                respond(400, ['ok' => false, 'error' => 'ref is required.']);
            }

            $order = $service->findByRef($ref);
            if (!$order) {
                respond(404, ['ok' => false, 'error' => 'Order not found.']);
            }

            $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '', $ref);
            $path = __DIR__ . '/uploads/delivery-notes/delivery_note_' . $safeRef . '.pdf';

            if (!is_file($path)) {
                respond(404, ['ok' => false, 'error' => 'Delivery note file not found.']);
            }

            header('Content-Type: application/pdf');
            header('Content-Disposition: inline; filename="delivery_note_' . $safeRef . '.pdf"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'admin_cancel_fedex_shipment':
            requirePost();
            requireAdmin($auth);
            $b   = jsonBody();
            $ref = trim((string) ($b['ref'] ?? ''));

            if ($ref === '') {
                respond(400, ['ok' => false, 'error' => 'ref is required.']);
            }

            $order = $service->findByRef($ref);
            if (!$order) {
                respond(404, ['ok' => false, 'error' => 'Order not found.']);
            }

            if (strcasecmp(trim((string) ($order['shipping_carrier'] ?? '')), 'FedEx') !== 0) {
                respond(422, ['ok' => false, 'error' => 'This order is not a FedEx order.']);
            }

            $trackingNumber = trim((string) ($order['shipping_tracking_no'] ?? ''));
            if ($trackingNumber === '') {
                respond(409, ['ok' => false, 'error' => 'This order does not have an active FedEx shipment.']);
            }

            if (!$fedEx->isConfigured()) {
                respond(503, ['ok' => false, 'error' => 'FedEx is not configured.']);
            }

            try {
                $result = $fedEx->cancelShipment($trackingNumber);

                if (empty($result['cancelled'])) {
                    $message = trim((string) ($result['message'] ?? ''));
                    respond(409, [
                        'ok' => false,
                        'error' => $message !== ''
                            ? 'FedEx did not cancel the shipment: ' . $message
                            : 'FedEx did not confirm that the shipment was cancelled.',
                        'fedex' => $result,
                    ]);
                }

                // Only alter NadGo after FedEx has explicitly confirmed cancellation.
                $service->clearFedExShipment($ref);

                $safeTracking = preg_replace('/[^A-Za-z0-9_-]/', '', $trackingNumber);
                $ext = strtolower((string) ($config['fedex']['label_image_type'] ?? 'PDF')) === 'png'
                    ? 'png'
                    : 'pdf';
                $labelPath = __DIR__ . '/uploads/fedex-labels/fedex_' . $safeTracking . '.' . $ext;
                $labelDeleted = !is_file($labelPath) || @unlink($labelPath);

                respond(200, [
                    'ok' => true,
                    'tracking_number' => $trackingNumber,
                    'cancelled' => true,
                    'message' => (string) ($result['message'] ?? ''),
                    'transaction_id' => (string) ($result['transaction_id'] ?? ''),
                    'label_deleted' => $labelDeleted,
                ]);
            } catch (\InvalidArgumentException $e) {
                respond(422, ['ok' => false, 'error' => $e->getMessage()]);
            } catch (FedExApiException $e) {
                error_log('[nadgo-api] FedEx cancellation error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()] + $e->diagnostics());
            } catch (\Throwable $e) {
                error_log('[nadgo-api] FedEx cancellation error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'admin_get_fedex_label':
            requireAdmin($auth);
            $ref = trim((string) ($_GET['ref'] ?? ''));

            if ($ref === '') {
                respond(400, ['ok' => false, 'error' => 'ref is required.']);
            }

            $order = $service->findByRef($ref);
            if (!$order) {
                respond(404, ['ok' => false, 'error' => 'Order not found.']);
            }

            if (strcasecmp(trim((string) ($order['shipping_carrier'] ?? '')), 'FedEx') !== 0) {
                respond(422, ['ok' => false, 'error' => 'This order is not a FedEx order.']);
            }

            $trackingNumber = trim((string) ($order['shipping_tracking_no'] ?? ''));
            if ($trackingNumber === '') {
                respond(404, ['ok' => false, 'error' => 'No FedEx shipment exists for this order.']);
            }

            $safeTracking = preg_replace('/[^A-Za-z0-9_-]/', '', $trackingNumber);
            $ext = strtolower((string) ($config['fedex']['label_image_type'] ?? 'PDF')) === 'png' ? 'png' : 'pdf';
            $path = __DIR__ . '/uploads/fedex-labels/fedex_' . $safeTracking . '.' . $ext;

            if (!is_file($path)) {
                respond(404, ['ok' => false, 'error' => 'FedEx label file not found.']);
            }

            header('Content-Type: ' . ($ext === 'png' ? 'image/png' : 'application/pdf'));
            header('Content-Disposition: inline; filename="fedex_' . $safeTracking . '.' . $ext . '"');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;

        case 'admin_delete_rm_shipment':
            requirePost();
            requireAdmin($auth);
            $b   = jsonBody();
            $ref = trim((string) ($b['ref'] ?? ''));
            if ($ref === '') respond(400, ['ok' => false, 'error' => 'ref is required.']);
            $order = $service->findByRef($ref);
            if (!$order) respond(404, ['ok' => false, 'error' => 'Order not found.']);
            if (!empty($order['rm_order_id'])) {
                try {
                    $royalMail->cancelOrder((string) $order['rm_order_id']);
                } catch (\Throwable $e) {
                    error_log('[nadgo-api] RM cancel error: ' . $e->getMessage());
                }
            }
            $service->clearRmShipment($ref);
            respond(200, ['ok' => true]);

        case 'admin_list_documents':
            requireAdmin($auth);
            $ref = trim((string) ($_GET['ref'] ?? ''));
            if ($ref === '') respond(400, ['ok' => false, 'error' => 'ref is required.']);
            respond(200, ['ok' => true, 'documents' => $service->listDocuments($ref)]);

        case 'admin_add_document':
            requirePost();
            requireAdmin($auth);
            $ref  = trim((string) ($_POST['ref']  ?? ''));
            $name = trim((string) ($_POST['name'] ?? ''));
            if ($ref === '')  respond(400, ['ok' => false, 'error' => 'ref is required.']);
            if ($name === '') respond(400, ['ok' => false, 'error' => 'name is required.']);
            if (empty($_FILES['file'])) respond(400, ['ok' => false, 'error' => 'file is required.']);
            $doc = $service->addDocument($ref, $name, $_FILES['file']);
            respond(201, ['ok' => true, 'document' => $doc]);

        case 'admin_delete_document':
            requirePost();
            requireAdmin($auth);
            $b  = jsonBody();
            $id = (int) ($b['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id is required.']);
            $service->deleteDocument($id);
            respond(200, ['ok' => true]);

        case 'admin_get_document':
            requireAdmin($auth);
            $id  = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) respond(400, ['ok' => false, 'error' => 'id is required.']);
            $doc = $service->documentPath($id);
            header('Content-Type: ' . ($doc['mime'] ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . addslashes(basename($doc['filename'])) . '"');
            header('Content-Length: ' . filesize($doc['path']));
            readfile($doc['path']);
            exit;

        case 'admin_get_receipt':
            requireAdmin($auth);
            $file = $service->adminReceiptPath((int) ($_GET['payment_id'] ?? 0));
            header('Content-Type: ' . (mime_content_type($file['path']) ?: 'application/octet-stream'));
            header('Content-Disposition: inline; filename="' . basename($file['filename']) . '"');
            header('Content-Length: ' . filesize($file['path']));
            readfile($file['path']);
            exit;

        // ---- products: public -----------------------------------------------
        case 'get_product':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $limiter->hit('get_product', $clientIp);
            $id = (int) ($_GET['id'] ?? 0);
            if ($id <= 0) respond(422, ['ok' => false, 'error' => 'id is required.']);
            respond(200, ['ok' => true, 'product' => $productService->getProductById($id)]);

        // ---- "notify me" interest — logged-in customers only -----------------
        case 'notify_me_status':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $notifyEmail = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$notifyEmail) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Verify your email again.']);
            }
            $notifyPid = (int) ($_GET['product_id'] ?? 0);
            if ($notifyPid <= 0) respond(422, ['ok' => false, 'error' => 'product_id is required.']);
            respond(200, ['ok' => true, 'is_active' => $productService->getNotifyStatus($notifyPid, $notifyEmail)]);

        case 'notify_me_toggle':
            requirePost();
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $notifyEmail = $auth->verifySessionToken(header_val('X-Session-Token'));
            if (!$notifyEmail) {
                respond(401, ['ok' => false, 'error' => 'Invalid or expired session. Verify your email again.']);
            }
            $b = jsonBody();
            honeypotCheck($b);
            $limiter->hit('notify_me_toggle', $clientIp);
            $notifyPid = (int) ($b['product_id'] ?? 0);
            $notifyActive = (bool) ($b['is_active'] ?? true);
            respond(200, ['ok' => true] + $productService->setNotifyRequest($notifyPid, $notifyEmail, $notifyActive));

        // ---- products: admin ------------------------------------------------
        case 'admin_list_products':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'products' => $productService->adminListProducts()]);

        case 'admin_list_notify_requests':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'requests' => $productService->adminListNotifyRequests()]);

        case 'admin_list_contact_submissions':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'submissions' => $service->adminListContactSubmissions()]);

        case 'admin_save_product':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $id = isset($b['id']) && $b['id'] ? (int) $b['id'] : null;
            respond(200, ['ok' => true, 'product' => $productService->adminSaveProduct($b, $id)]);

        case 'admin_save_variant':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $id = isset($b['id']) && $b['id'] ? (int) $b['id'] : null;
            respond(200, ['ok' => true, 'variant' => $productService->adminSaveVariant($b, $id)]);

        case 'admin_adjust_stock':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $vid   = (int) ($b['variant_id'] ?? 0);
            $delta = (int) ($b['delta'] ?? 0);
            if ($vid <= 0) respond(422, ['ok' => false, 'error' => 'variant_id is required.']);
            if ($delta === 0) respond(422, ['ok' => false, 'error' => 'delta must be non-zero.']);
            respond(200, ['ok' => true, 'variant' => $productService->adminAdjustStock($vid, $delta)]);

        // ---- display exchange rates: public ---------------------------------
        case 'get_exchange_rates':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            try {
                respond(200, ['ok' => true, 'exchange' => (new ExchangeRateService())->getRates()]);
            } catch (\Throwable $e) {
                error_log('[nadgo-api] display FX rate error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => 'Could not fetch display exchange rates.']);
            }

        // ---- crypto rate: public --------------------------------------------
        case 'get_crypto_rate':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            try {
                respond(200, ['ok' => true, 'rate' => (new CryptoRateService($db))->getRate()]);
            } catch (\Throwable $e) {
                respond(502, ['ok' => false, 'error' => 'Could not fetch exchange rate.']);
            }

        // ---- shipping rates: public -----------------------------------------
        case 'get_shipping_rate':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            $country = trim((string) ($_GET['country'] ?? ''));
            if ($country === '') respond(422, ['ok' => false, 'error' => 'country is required.']);
            $methods = array_map(static function (array $sr): array {
                return [
                    'code'           => (string) ($sr['method_code'] ?? 'standard'),
                    'name'           => (string) ($sr['method_name'] ?? 'Standard shipping'),
                    'rate'           => (float) $sr['rate'],
                    'free_threshold' => $sr['free_threshold'] !== null ? (float) $sr['free_threshold'] : null,
                ];
            }, $shippingService->getRatesForCountry($country));
            // `shipping` keeps older frontend builds working; new checkout uses shipping_methods.
            respond(200, ['ok' => true, 'shipping' => $methods[0], 'shipping_methods' => $methods]);

        // ---- shipping rates: admin ------------------------------------------
        case 'get_fedex_rates':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            requirePost();
            // Legacy checkout compatibility: public rates match the fixed prices
            // enforced in OrderService. No live FedEx rating call is required.
            respond(200, [
                'ok' => true,
                'rates' => [
                    ['service_type' => 'FEDEX_PRIORITY', 'service_name' => 'FedEx® Priority', 'rate' => 4.99, 'currency' => 'GBP', 'delivery_date' => null, 'transit_time' => null],
                    ['service_type' => 'FEDEX_PRIORITY_EXPRESS', 'service_name' => 'FedEx® Priority Express', 'rate' => 8.99, 'currency' => 'GBP', 'delivery_date' => null, 'transit_time' => null],
                    ['service_type' => 'FEDEX_FIRST', 'service_name' => 'FedEx® First', 'rate' => 12.99, 'currency' => 'GBP', 'delivery_date' => null, 'transit_time' => null],
                ],
            ]);

        case 'admin_test_fedex':
            requirePost();
            requireAdmin($auth);
            try {
                respond(200, ['ok' => true] + $fedEx->testAuthentication());
            } catch (\Throwable $e) {
                error_log('[nadgo-api] FedEx OAuth test error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'admin_test_fedex_ship':
            requirePost();
            requireAdmin($auth);
            try {
                $b = jsonBody();
                $recipient = isset($b['recipient']) && is_array($b['recipient']) ? $b['recipient'] : [];
                $address = isset($b['address']) && is_array($b['address']) ? $b['address'] : [];
                $recipient = array_merge($recipient, [
                    'street_lines' => $address['street_lines'] ?? $address['street'] ?? [],
                    'city' => $address['city'] ?? '',
                    'postal_code' => $address['postal_code'] ?? '',
                    'country_code' => $address['country_code'] ?? 'GB',
                ]);
                $package = isset($b['package']) && is_array($b['package']) ? $b['package'] : [];
                $result = $fedEx->createShipment(
                    $recipient,
                    $package,
                    (string) ($b['service_type'] ?? '')
                );

                // Keep large Base64 label data out of the API response. Save the label
                // under uploads/fedex-labels so it can be inspected for certification.
                $labelBase64 = (string) ($result['label_base64'] ?? '');
                unset($result['label_base64']);
                if ($labelBase64 !== '') {
                    $saved = $fedEx->saveLabel(
                        $labelBase64,
                        __DIR__ . '/uploads/fedex-labels',
                        (string) ($result['tracking_number'] ?? '')
                    );
                    $result['label_file'] = 'uploads/fedex-labels/' . basename($saved);
                }
                respond(200, ['ok' => true] + $result);
            } catch (\InvalidArgumentException $e) {
                respond(422, ['ok' => false, 'error' => $e->getMessage()]);
            } catch (FedExApiException $e) {
                error_log('[nadgo-api] FedEx ship diagnostic: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()] + $e->diagnostics());
            } catch (\Throwable $e) {
                error_log('[nadgo-api] FedEx ship test error: ' . $e->getMessage());
                respond(502, ['ok' => false, 'error' => $e->getMessage()]);
            }

        case 'admin_list_shipping':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'rates' => $shippingService->listRates()]);

        case 'admin_save_shipping':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $id = isset($b['id']) && $b['id'] ? (int) $b['id'] : null;
            respond(200, ['ok' => true, 'rate' => $shippingService->saveRate($b, $id)]);

        case 'admin_delete_shipping':
            requirePost();
            requireAdmin($auth);
            $b = jsonBody();
            $id = (int) ($b['id'] ?? 0);
            if ($id <= 0) respond(422, ['ok' => false, 'error' => 'id is required.']);
            $shippingService->deleteRate($id);
            respond(200, ['ok' => true]);

        // ---- coupons: public ------------------------------------------------
        case 'validate_coupon':
            if (!$auth->checkFrontendKey(header_val('X-API-Key'))) {
                respond(401, ['ok' => false, 'error' => 'Unauthorized.']);
            }
            requirePost();
            $limiter->hit('validate_coupon', $clientIp);
            $b = jsonBody();
            $code     = trim((string) ($b['code']     ?? ''));
            $subtotal = (float)  ($b['subtotal'] ?? 0);
            if ($code === '') respond(422, ['ok' => false, 'error' => 'code is required.']);
            // Resolve customer email: prefer verified session, fall back to body field (guest).
            $vcEmail = $auth->verifySessionToken(header_val('X-Session-Token')) ?? trim((string) ($b['email'] ?? ''));
            respond(200, $couponService->validate($code, $subtotal, $b['items'] ?? [], $vcEmail));

        // ---- coupons: admin -------------------------------------------------
        case 'admin_list_coupons':
            requireAdmin($auth);
            respond(200, ['ok' => true, 'coupons' => $couponService->adminList()]);

        case 'admin_save_coupon':
            requirePost();
            requireAdmin($auth);
            $b  = jsonBody();
            $id = isset($b['id']) && $b['id'] ? (int) $b['id'] : null;
            respond(200, ['ok' => true, 'coupon' => $couponService->adminSave($b, $id)]);

        case 'admin_delete_coupon':
            requirePost();
            requireAdmin($auth);
            $b  = jsonBody();
            $id = (int) ($b['id'] ?? 0);
            if ($id <= 0) respond(422, ['ok' => false, 'error' => 'id is required.']);
            $couponService->adminDelete($id);
            respond(200, ['ok' => true]);

        default:
            respond(404, ['ok' => false, 'error' => 'Unknown action.']);
    }

} catch (ValidationException $e) {
    respond(422, ['ok' => false, 'error' => $e->getMessage()]
        + ($e instanceof FieldValidationException ? ['field' => $e->field] : []));
} catch (AuthException $e) {
    respond(401, ['ok' => false, 'error' => $e->getMessage()]);
} catch (ConflictException $e) {
    respond(409, ['ok' => false, 'error' => $e->getMessage()]);
} catch (NotFoundException $e) {
    respond(404, ['ok' => false, 'error' => $e->getMessage()]);
} catch (UploadException $e) {
    respond(422, ['ok' => false, 'error' => $e->getMessage()]);
} catch (RateLimitException $e) {
    header('Retry-After: ' . $e->retryAfter);
    respond(429, ['ok' => false, 'error' => $e->getMessage(), 'retry_after' => $e->retryAfter]);
} catch (\Throwable $e) {
    error_log('[nadgo-api] ' . $e->getMessage());
    respond(500, ['ok' => false, 'error' => !empty($config['debug']) ? $e->getMessage() : 'Internal server error.']);
}

// --- small guards (defined after dispatch; hoisted) --------------------------
function requirePost(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond(405, ['ok' => false, 'error' => 'Method not allowed. Use POST.']);
    }
}
function requireAdmin(Auth $auth): void
{
    if (!$auth->checkAdminKey(header_val('X-Admin-Key'))) {
        respond(401, ['ok' => false, 'error' => 'Admin authorization required.']);
    }
}
/** Reject bot submissions that filled the hidden honeypot field. */
function honeypotCheck(array $data): void
{
    global $config;
    $field = $config['honeypot_field'] ?? '';
    if ($field !== '' && isset($data[$field]) && trim((string) $data[$field]) !== '') {
        respond(422, ['ok' => false, 'error' => 'Invalid submission.']);
    }
}