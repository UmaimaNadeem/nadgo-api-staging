<?php
/**
 * All order business logic: create (step 1), payment proof (step 2),
 * account login + tracking, and admin verification / delivery updates.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Uploads.php';
require_once __DIR__ . '/StripeGateway.php';
require_once __DIR__ . '/ProductService.php';
require_once __DIR__ . '/FenaService.php';
require_once __DIR__ . '/FedExService.php';

class ValidationException extends \RuntimeException {}
class NotFoundException   extends \RuntimeException {}
class ConflictException   extends \RuntimeException {}
class AuthException       extends \RuntimeException {}

class OrderService
{
    private const PAYMENT_METHODS  = ['bank', 'crypto', 'card'];
    private const DELIVERY_STATES  = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];
    private const VAT_RATE = 0.20;
    private const MONEY_TOLERANCE  = 0.02;

    private ?ProductService $productService = null;
    private ?CouponService  $couponService  = null;
    private ?WallidService  $wallid         = null;
    private ?FenaService    $fena           = null;
    private ?ShippingService $shippingService = null;
    private ?FedExService $fedEx = null;
    private ?array $orderColumnsCache = null;

    public function __construct(
        private Database $db,
        private Mailer $mailer,
        private Auth $auth,
        private Uploads $uploads,
        private array $config,
        private ?StripeGateway $stripe = null
    ) {}

    public function setProductService(ProductService $ps): void
    {
        $this->productService = $ps;
    }

    public function setCouponService(CouponService $cs): void
    {
        $this->couponService = $cs;
    }

    public function setWallidService(WallidService $w): void
    {
        $this->wallid = $w;
    }

    public function setFenaService(FenaService $f): void
    {
        $this->fena = $f;
    }

    public function setShippingService(ShippingService $shippingService): void
    {
        $this->shippingService = $shippingService;
    }

    public function setFedExService(FedExService $fedEx): void
    {
        $this->fedEx = $fedEx;
    }

    // =========================================================================
    // STEP 1 — create order
    // =========================================================================

    public function createOrder(array $d, string $ip): array
    {
        // Contact (accept both bare and customer_* keys). Email is the identity,
        // stored lower-cased so login/tracking/user lookups stay consistent.
        $email = strtolower($this->first($d, ['email', 'customer_email']));
        $phone = $this->first($d, ['phone', 'customer_phone']);
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email is required.');
        }
        if ($phone === '') throw new ValidationException('phone is required.');

        // Billing address comes from the main checkout fields.
        $bill = [
            'first_name' => $this->first($d, ['first_name', 'bill_first_name']),
            'last_name'  => $this->first($d, ['last_name', 'bill_last_name']),
            'address1'   => $this->first($d, ['address', 'address1', 'bill_address1']),
            'address2'   => $this->first($d, ['apartment', 'address2', 'bill_address2']),
            'city'       => $this->first($d, ['city', 'bill_city']),
            'postcode'   => $this->first($d, ['postcode', 'postal_code', 'zip', 'bill_postcode']),
            'country'    => $this->first($d, ['country', 'bill_country']),
        ];
        foreach (['first_name', 'last_name', 'address1', 'city', 'postcode', 'country'] as $req) {
            if ($bill[$req] === '') throw new ValidationException('Billing ' . str_replace('address1', 'address', $req) . ' is required.');
        }

        // Shipping defaults to billing, but can be overridden by the optional
        // "Ship to a different address" checkout section.
        $differentShipping = !empty($d['ship_to_different_address']);
        $ship = $differentShipping ? [
            'first_name' => $this->first($d, ['ship_first_name']),
            'last_name'  => $this->first($d, ['ship_last_name']),
            'address1'   => $this->first($d, ['ship_address1']),
            'address2'   => $this->first($d, ['ship_address2']),
            'city'       => $this->first($d, ['ship_city']),
            'postcode'   => $this->first($d, ['ship_postcode']),
            'country'    => $this->first($d, ['ship_country']),
        ] : $bill;
        foreach (['first_name', 'last_name', 'address1', 'city', 'postcode', 'country'] as $req) {
            if ($ship[$req] === '') throw new ValidationException('Shipping ' . str_replace('address1', 'address', $req) . ' is required.');
        }
        $orderNotes = trim((string) ($d['order_notes'] ?? ''));
        if (strlen($orderNotes) > 2000) {
            throw new ValidationException('Order notes must be 2000 characters or fewer.');
        }

        $items = $this->validateItems($d['items'] ?? []);

        // Validate item prices and stock against the products DB.
        $this->productService?->validateItems($items);

        // Money breakdown — use provided values, else derive from items.
        $itemsSubtotal = round(array_sum(array_column($items, 'line_total')), 2);
        // The subtotal is always the sum of the items; a client-sent subtotal must match it.
        if (isset($d['subtotal'])
            && abs($this->money($this->first($d, ['subtotal', 'subtotal_amount'])) - $itemsSubtotal) > self::MONEY_TOLERANCE) {
            throw new ValidationException('Subtotal does not match the items.');
        }
        $subtotal = $itemsSubtotal;
        $shipping = $this->money($this->first($d, ['shipping', 'shipping_amount']) ?: 0);
        $tax      = $this->money($this->first($d, ['tax', 'vat', 'tax_amount']) ?: 0);
        $taxRate  = $this->money($this->first($d, ['tax_rate', 'vat_rate']) ?: 0);
        $total    = isset($d['total']) || isset($d['total_amount'])
            ? $this->money($this->first($d, ['total', 'total_amount']))
            : round($subtotal + $shipping, 2);

        // Coupon — validate server-side and recompute canonical discount.
        $couponCode = strtoupper(trim((string) ($d['coupon_code'] ?? '')));
        $discount   = 0.0;
        if ($couponCode !== '' && $this->couponService) {
            $couponResult = $this->couponService->validate($couponCode, $subtotal, [], $email);
            if (!$couponResult['valid']) {
                throw new ValidationException('Coupon error: ' . ($couponResult['message'] ?? 'invalid coupon.'));
            }
            $discount   = (float) $couponResult['discount_amount'];
            $couponCode = $couponResult['code']; // normalised uppercase from service
        }

        // Resolve and validate the checkout shipping method server-side. This prevents
        // a client from changing the shipping charge while still allowing legacy
        // countries with a single standard method.
        $shippingMethodCode = trim((string) ($d['shipping_method_code'] ?? ''));
        $shippingCarrier = '';
        $shippingMethodName = '';

        // UK checkout uses fixed customer-facing FedEx prices. Validate on the
        // server; never trust a client-supplied shipping amount or service name.
        $fixedUkFedExMethods = [
            'FEDEX_PRIORITY' => ['name' => 'FedEx® Priority', 'rate' => 4.99],
            'FEDEX_PRIORITY_EXPRESS' => ['name' => 'FedEx® Priority Express', 'rate' => 8.99],
            'FEDEX_FIRST' => ['name' => 'FedEx® First', 'rate' => 12.99],
        ];

        if ($this->isUkCountry($ship['country'])) {
            $selectedFixedMethod = $fixedUkFedExMethods[$shippingMethodCode] ?? null;
            if ($selectedFixedMethod === null) {
                throw new ValidationException('Please select a valid FedEx delivery method.');
            }

            $expectedShipping = $selectedFixedMethod['rate'];
            if (abs($shipping - $expectedShipping) > self::MONEY_TOLERANCE) {
                throw new ValidationException(
                    "Shipping amount is incorrect. Expected £{$expectedShipping} for " .
                    $selectedFixedMethod['name'] . '.'
                );
            }

            $shippingCarrier = 'FedEx';
            $shippingMethodName = $selectedFixedMethod['name'];
            $shipping = $expectedShipping;

        } elseif ($this->shippingService) {

            // Existing database shipping logic for non-FedEx methods/countries.
            $methods = $this->shippingService->getRatesForCountry($ship['country']);

            $selectedMethod = null;

            if ($shippingMethodCode !== '') {
                foreach ($methods as $candidate) {
                    if ((string) ($candidate['method_code'] ?? '') === $shippingMethodCode) {
                        $selectedMethod = $candidate;
                        break;
                    }
                }
            }

            $selectedMethod ??= $methods[0] ?? null;

            if (!$selectedMethod) {
                throw new ValidationException(
                    'No shipping method is available for the selected country.'
                );
            }

            $shippingMethodCode = (string) (
                $selectedMethod['method_code'] ?? 'standard'
            );

            $shippingMethodName = (string) (
                $selectedMethod['method_name'] ?? 'Standard shipping'
            );

            $shippingCarrier = trim((string) ($selectedMethod['carrier'] ?? ''));
            if ($shippingCarrier === '') {
                $shippingCarrier = 'Royal Mail';
            }

            $freeThreshold =
                $selectedMethod['free_threshold'] !== null
                    ? (float) $selectedMethod['free_threshold']
                    : null;

            $expectedShipping =
                $freeThreshold !== null && $subtotal >= $freeThreshold
                    ? 0.0
                    : round((float) $selectedMethod['rate'], 2);

            if (abs($shipping - $expectedShipping) > self::MONEY_TOLERANCE) {
                throw new ValidationException(
                    "Shipping amount is incorrect. Expected £{$expectedShipping} for {$shippingCarrier}."
                );
            }

            $shipping = $expectedShipping;
        }

        // UK prices already include VAT. Store the VAT portion for display/reporting,
        // but never add it to the amount charged. Match checkout by applying the
        // included-VAT calculation to merchandise after coupon discount; shipping
        // remains outside this informational VAT figure.
        $isUk = $this->isUkCountry($ship['country']);
        $vatBase = max(0.0, round($subtotal - $discount + $shipping, 2));
        $expectedTax = $isUk
            ? round($vatBase * (self::VAT_RATE / (1 + self::VAT_RATE)), 2)
            : 0.0;
        $expectedTaxRate = $isUk ? self::VAT_RATE : 0.0;
        $expectedTotal = max(0.0, round($subtotal + $shipping - $discount, 2));
        if (abs($tax - $expectedTax) > self::MONEY_TOLERANCE) {
            throw new ValidationException("VAT amount is incorrect. Expected £{$expectedTax} included VAT.");
        }
        if (abs($taxRate - $expectedTaxRate) > self::MONEY_TOLERANCE) {
            throw new ValidationException("VAT rate is incorrect. Expected " . ($expectedTaxRate * 100) . "%.");
        }
        if (abs($total - $expectedTotal) > self::MONEY_TOLERANCE) {
            throw new ValidationException("Order total is incorrect. Expected £{$expectedTotal}.");
        }
        $tax     = $expectedTax;
        $taxRate = $expectedTaxRate;
        $total   = $expectedTotal;

        $method = $this->str($d, 'payment_method');
        if ($method !== '' && !in_array($method, self::PAYMENT_METHODS, true)) {
            throw new ValidationException('payment_method must be one of: ' . implode(', ', self::PAYMENT_METHODS));
        }

        $orderRef = $this->str($d, 'order_ref') ?: $this->generateRef();
        $token    = bin2hex(random_bytes(16));

        $this->db->beginTransaction();
        try {
            $isGuestCheckout = !empty($d['_guest_checkout']);
            $userId = $isGuestCheckout ? null : $this->findOrCreateUser($email, $phone, $bill);
            $orderId = (int) $this->db->insert(
                'INSERT INTO orders
                    (order_ref, tracking_token, user_id, customer_email, customer_phone,
                     bill_first_name, bill_last_name, bill_address1, bill_address2,
                     bill_city, bill_postcode, bill_country,
                     ship_first_name, ship_last_name, ship_address1, ship_address2,
                     ship_city, ship_postcode, ship_country, order_notes,
                     currency, subtotal_amount, shipping_amount, tax_amount, tax_rate,
                     coupon_code, discount_amount, total_amount,
                     payment_method, shipping_carrier, shipping_method_code, shipping_method_name, raw_payload, source_ip)
                 VALUES
                    (:ref, :token, :uid, :email, :phone,
                     :bfn, :bln, :ba1, :ba2, :bcity, :bpc, :bcountry,
                     :fn, :ln, :a1, :a2, :city, :pc, :country, :notes,
                     :cur, :sub, :ship, :tax, :rate,
                     :coupon, :disc, :total,
                     :method, :shipcarrier, :shipmethodcode, :shipmethodname, :raw, :ip)',
                [
                    ':ref'     => $orderRef,
                    ':token'   => $token,
                    ':uid'     => $userId,
                    ':email'   => $email,
                    ':phone'   => $phone,
                    ':bfn'     => $bill['first_name'],
                    ':bln'     => $bill['last_name'],
                    ':ba1'     => $bill['address1'],
                    ':ba2'     => $bill['address2'] ?: null,
                    ':bcity'   => $bill['city'],
                    ':bpc'     => $bill['postcode'],
                    ':bcountry'=> $bill['country'],
                    ':fn'      => $ship['first_name'],
                    ':ln'      => $ship['last_name'],
                    ':a1'      => $ship['address1'],
                    ':a2'      => $ship['address2'] ?: null,
                    ':city'    => $ship['city'],
                    ':pc'      => $ship['postcode'],
                    ':country' => $ship['country'],
                    ':notes'   => $orderNotes !== '' ? $orderNotes : null,
                    ':cur'     => strtoupper($this->str($d, 'currency')) ?: 'GBP',
                    ':sub'     => $subtotal,
                    ':ship'    => $shipping,
                    ':tax'     => $tax,
                    ':rate'    => $taxRate,
                    ':coupon'  => $couponCode ?: null,
                    ':disc'    => $discount,
                    ':total'   => $total,
                    ':method'  => $method ?: null,
                    ':shipcarrier' => $shippingCarrier !== '' ? $shippingCarrier : null,
                    ':shipmethodcode' => $shippingMethodCode !== '' ? $shippingMethodCode : null,
                    ':shipmethodname' => $shippingMethodName !== '' ? $shippingMethodName : null,
                    ':raw'     => json_encode($d, JSON_UNESCAPED_UNICODE),
                    ':ip'      => $ip,
                ]
            );
            $this->insertItems($orderId, $items);
            $this->productService?->decrementStock($items);
            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23000') {
                throw new ConflictException('An order with this order_ref already exists.');
            }
            throw $e;
        }

        // Record coupon usage outside the transaction so it never rolls back an otherwise good order.
        if ($couponCode !== '' && $this->couponService) {
            $this->couponService->recordUsage($couponCode, $email, $orderRef);
        }

        $order = $this->findById($orderId);
        try {
            $this->mailer->notifyAdminNewOrder($order, $items);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] new-order admin email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->sendOrderConfirmation($order, $items);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] order-confirmation customer email failed: ' . $e->getMessage());
        }

        return [
            'order_ref'            => $orderRef,
            'tracking_token'       => $token,
            'payment_status'       => 'pending',
            'delivery_status'      => 'pending',
            'currency'             => $order['currency'],
            'subtotal_amount'      => $subtotal,
            'shipping_amount'      => $shipping,
            'tax_amount'           => $tax,
            'discount_amount'      => $discount,
            'coupon_code'          => $couponCode ?: null,
            'total_amount'         => $total,
            'payment_instructions' => $this->paymentInstructions(),
        ];
    }

    // =========================================================================
    // STEP 2 — submit payment proof
    // =========================================================================

    public function submitPayment(array $order, array $input, ?array $file): array
    {
        if ($order['payment_status'] === 'paid') {
            throw new ConflictException('This order is already paid.');
        }

        $method = $this->str($input, 'method') ?: (string) $order['payment_method'];
        if (!in_array($method, self::PAYMENT_METHODS, true)) {
            throw new ValidationException('method must be one of: ' . implode(', ', self::PAYMENT_METHODS));
        }

        $txId       = $this->str($input, 'tx_id');
        $note       = $this->str($input, 'note');
        $amount     = isset($input['amount']) && $input['amount'] !== ''
            ? $this->money($input['amount']) : null;
        $cryptoRate    = ($method === 'crypto' && isset($input['crypto_rate']) && $input['crypto_rate'] !== '')
            ? round((float) $input['crypto_rate'], 8) : null;
        $cryptoNetwork = ($method === 'crypto' && isset($input['crypto_network']) && $input['crypto_network'] !== '')
            ? substr(trim($input['crypto_network']), 0, 100) : null;

        $receiptPath = $this->uploads->storeReceipt($file, $order['order_ref']);

        if ($txId === '' && $receiptPath === null) {
            throw new ValidationException('Provide a transaction ID and/or a receipt file.');
        }

        $this->db->beginTransaction();
        try {
            $paymentId = (int) $this->db->insert(
                'INSERT INTO payments (order_id, method, amount, tx_id, receipt_path, note, crypto_rate, crypto_network)
                 VALUES (:oid, :method, :amount, :tx, :receipt, :note, :rate, :network)',
                [
                    ':oid'     => $order['id'],
                    ':method'  => $method,
                    ':amount'  => $amount,
                    ':tx'      => $txId ?: null,
                    ':receipt' => $receiptPath,
                    ':note'    => $note ?: null,
                    ':rate'    => $cryptoRate,
                    ':network' => $cryptoNetwork,
                ]
            );
            $stmt = $this->db->pdo()->prepare(
                'UPDATE orders SET payment_method = :method, payment_status = :status WHERE id = :id'
            );
            $stmt->execute([':method' => $method, ':status' => 'submitted', ':id' => $order['id']]);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        $payment = ['method' => $method, 'amount' => $amount, 'tx_id' => $txId,
                    'receipt_path' => $receiptPath, 'note' => $note,
                    'crypto_rate' => $cryptoRate, 'crypto_network' => $cryptoNetwork];
        try {
            $this->mailer->notifyAdminPaymentSubmitted($order, $payment); // admin notification #2
        } catch (\Throwable $e) {
            error_log('[nadgo-api] payment-submitted admin email failed: ' . $e->getMessage());
        }

        return ['payment_id' => $paymentId, 'payment_status' => 'submitted'];
    }

    public function paymentInstructions(): array
    {
        $cfg = $this->config['payment_instructions'] ?? [];
        $out = [];
        if (!empty($cfg['bank']['enabled']))   $out['bank']   = $cfg['bank'];
        if (!empty($cfg['crypto']['enabled'])) $out['crypto'] = $cfg['crypto'];
        if (!empty($cfg['card']['enabled']) && !empty($this->config['stripe']['enabled'])) {
            $out['card'] = ['enabled' => true];
        }
        if (!empty($cfg['fena']['enabled']) && !empty($this->config['fena']['enabled'])) {
            $out['fena'] = ['enabled' => true];
        }
        if (!empty($cfg['wallid']['enabled']) && !empty($this->config['wallid']['enabled'])) {
            $out['wallid'] = ['enabled' => true];
        }
        return $out;
    }

    /**
     * Create a Fena Open Banking hosted payment for an existing order.
     * {ORDER_REF} in the redirect URL template is replaced with the actual ref.
     * Returns ['payment_link' => string, 'api_payment_id' => string].
     */
    public function createFenaPayment(string $orderRef, string $successUrl): array
    {
        $order = $this->findByRef($orderRef);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new ValidationException('This order has already been paid.');
        }
        if (!$this->fena || !$this->fena->enabled()) {
            throw new ValidationException('Fena payments are not configured.');
        }

        // Reuse an existing in-progress Fena payment for this order, if it's
        // still payable, instead of creating (and linking) a new one on every click.
        $reused = $this->reuseFenaPayment($order['id']);
        if ($reused !== null) {
            return $reused;
        }

        $result = $this->fena->createPayment([
            'reference'           => $orderRef,
            'amount'              => (float) $order['total_amount'],
            'description'         => 'Order ' . $orderRef,
            'customer_email'      => $order['customer_email'] ?? '',
            'custom_redirect_url' => str_replace('{ORDER_REF}', rawurlencode($orderRef), $successUrl),
        ]);

        // Record a pending payment so the api_payment_id is traceable
        $this->db->insert(
            "INSERT INTO payments (order_id, method, amount, tx_id, status)
             VALUES (:oid, 'fena', :amt, :txid, 'submitted')",
            [':oid' => $order['id'], ':amt' => $order['total_amount'], ':txid' => $result['api_payment_id']]
        );

        return [
            'payment_link'   => $result['payment_link'],
            'api_payment_id' => $result['api_payment_id'],
        ];
    }

    /**
     * If this order already has a submitted Fena payment that's still payable
     * (checked live against Fena), return its link instead of creating a new
     * one. If it's no longer payable, mark it rejected locally so a fresh
     * payment gets created. Returns null when there's nothing to reuse.
     */
    private function reuseFenaPayment(int $orderId): ?array
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            "SELECT id, tx_id FROM payments
              WHERE order_id = :oid AND method = 'fena' AND status = 'submitted'
              ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute([':oid' => $orderId]);
        $pending = $stmt->fetch();
        if (!$pending || empty($pending['tx_id'])) {
            return null;
        }

        try {
            $status = $this->fena->getStatus($pending['tx_id']);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] fena getStatus check failed: ' . $e->getMessage());
            return null; // fall through and create a new payment
        }

        if (in_array($status['status'] ?? '', ['sent', 'pending', 'overdue'], true) && !empty($status['link'])) {
            return ['payment_link' => $status['link'], 'api_payment_id' => $pending['tx_id']];
        }

        // No longer payable (rejected/cancelled/paid/etc.) — clear it so a fresh
        // payment can be created.
        $pdo->prepare("UPDATE payments SET status = 'rejected' WHERE id = :id")
            ->execute([':id' => $pending['id']]);
        return null;
    }

    /**
     * Mark an order paid via Fena (called from the webhook). Idempotent.
     */
    public function confirmFenaPayment(string $orderRef, string $paymentId, ?float $amount = null): void
    {
        $order = $this->findByRef($orderRef);
        if (!$order || $order['payment_status'] === 'paid') {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Update the submitted payment row if it exists, otherwise insert a verified one
            $upd = $pdo->prepare(
                "UPDATE payments SET status = 'verified', verified_at = UTC_TIMESTAMP()
                 WHERE order_id = :oid AND method = 'fena' AND tx_id = :txid LIMIT 1"
            );
            $upd->execute([':oid' => $order['id'], ':txid' => $paymentId]);

            if ($upd->rowCount() === 0) {
                $this->db->insert(
                    "INSERT INTO payments (order_id, method, amount, tx_id, status, verified_at)
                     VALUES (:oid, 'fena', :amt, :txid, 'verified', UTC_TIMESTAMP())",
                    [':oid' => $order['id'], ':amt' => $amount ?? $order['total_amount'], ':txid' => $paymentId]
                );
            }

            $pdo->prepare(
                "UPDATE orders SET payment_method = 'fena', payment_status = 'paid',
                 paid_at = UTC_TIMESTAMP() WHERE id = :id"
            )->execute([':id' => $order['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $order = $this->findByRef($orderRef);
        try {
            $this->mailer->notifyAdminPaymentSubmitted($order, [
                'method' => 'fena', 'amount' => $amount ?? $order['total_amount'],
                'tx_id' => $paymentId, 'receipt_path' => null,
                'note' => 'Paid via Fena Open Banking (auto-confirmed by webhook).',
            ]);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] fena-paid admin email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->notifyCustomerPaymentVerified($order);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] fena-paid customer email failed: ' . $e->getMessage());
        }
    }

    /**
     * Create a Wallid hosted payment session for an existing order.
     * {ORDER_REF} in the URL templates is replaced with the actual ref.
     * Returns ['payment_link' => string, 'api_payment_id' => string].
     */
    public function createWallidPayment(string $orderRef, string $successUrl, string $failUrl): array
    {
        $order = $this->findByRef($orderRef);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new ValidationException('This order has already been paid.');
        }
        if (!$this->wallid || !$this->wallid->enabled()) {
            throw new ValidationException('Wallid payments are not configured.');
        }

        $pdo = $this->db->pdo();
        $itemsStmt = $pdo->prepare(
            'SELECT product_name, unit_price, quantity, image_url FROM order_items WHERE order_id = :id'
        );
        $itemsStmt->execute([':id' => $order['id']]);
        $dbItems = $itemsStmt->fetchAll();

        $logoUrl    = $this->config['brand']['logo_url']    ?? '';
        $websiteUrl = $this->config['brand']['website_url'] ?? '';

        $wallidItems = array_map(fn($item) => [
            'name'        => $item['product_name'],
            'category'    => 'Health Supplement',
            'price_minor' => (int) round((float) $item['unit_price'] * 100),
            'image_url'   => !empty($item['image_url']) ? $item['image_url'] : $logoUrl,
            'product_url' => $websiteUrl,
        ], $dbItems);

        if (empty($wallidItems)) {
            $wallidItems = [[
                'name'        => 'Order ' . $orderRef,
                'category'    => 'Health Supplement',
                'price_minor' => (int) round((float) $order['total_amount'] * 100),
                'image_url'   => $logoUrl,
                'product_url' => $websiteUrl,
            ]];
        }

        $result = $this->wallid->createPayment([
            'order_id'       => $orderRef,
            'amount'         => (int) round((float) $order['total_amount'] * 100),
            'currency'       => $order['currency'] ?? 'GBP',
            'success_url'    => str_replace('{ORDER_REF}', rawurlencode($orderRef), $successUrl),
            'fail_url'       => $failUrl,
            'items'          => $wallidItems,
            'customer_email' => $order['customer_email'] ?? '',
            'customer_id'    => $orderRef,
            'description'    => 'Order ' . $orderRef,
            'locale'         => 'en',
            'country'        => 'GB',
        ]);

        // Record a pending payment so the api_payment_id is traceable
        $this->db->insert(
            "INSERT INTO payments (order_id, method, amount, tx_id, status)
             VALUES (:oid, 'wallid', :amt, :txid, 'submitted')",
            [':oid' => $order['id'], ':amt' => $order['total_amount'], ':txid' => $result['api_payment_id']]
        );

        return [
            'payment_link'   => $result['payment_link'],
            'api_payment_id' => $result['api_payment_id'],
        ];
    }

    /**
     * Mark an order paid via Wallid (called from the webhook). Idempotent.
     */
    public function confirmWallidPayment(string $orderRef, string $apiPaymentId, ?float $amount = null): void
    {
        $order = $this->findByRef($orderRef);
        if (!$order || $order['payment_status'] === 'paid') {
            return;
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Update the submitted payment row if it exists, otherwise insert a verified one
            $upd = $pdo->prepare(
                "UPDATE payments SET status = 'verified', verified_at = UTC_TIMESTAMP()
                 WHERE order_id = :oid AND method = 'wallid' AND tx_id = :txid LIMIT 1"
            );
            $upd->execute([':oid' => $order['id'], ':txid' => $apiPaymentId]);

            if ($upd->rowCount() === 0) {
                $this->db->insert(
                    "INSERT INTO payments (order_id, method, amount, tx_id, status, verified_at)
                     VALUES (:oid, 'wallid', :amt, :txid, 'verified', UTC_TIMESTAMP())",
                    [':oid' => $order['id'], ':amt' => $amount ?? $order['total_amount'], ':txid' => $apiPaymentId]
                );
            }

            $pdo->prepare(
                "UPDATE orders SET payment_method = 'wallid', payment_status = 'paid',
                 paid_at = UTC_TIMESTAMP() WHERE id = :id"
            )->execute([':id' => $order['id']]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $order = $this->findByRef($orderRef);
        try {
            $this->mailer->notifyAdminPaymentSubmitted($order, [
                'method' => 'wallid', 'amount' => $amount ?? $order['total_amount'],
                'tx_id' => $apiPaymentId, 'receipt_path' => null,
                'note' => 'Paid via Wallid (auto-confirmed by webhook).',
            ]);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] wallid-paid admin email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->notifyCustomerPaymentVerified($order);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] wallid-paid customer email failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Accounts — register / login (email + password)
    // =========================================================================

    private const MIN_PASSWORD = 8;

    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => strtolower(trim($email))]);
        return $stmt->fetch() ?: null;
    }

    /** Create a user account with a hashed password. Returns the new user id. */
    public function registerUser(string $email, string $password, array $profile = []): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email is required.');
        }
        if (strlen($password) < self::MIN_PASSWORD) {
            throw new ValidationException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        if ($this->findUserByEmail($email)) {
            throw new ConflictException('An account with this email already exists.');
        }

        $id = (int) $this->db->insert(
            'INSERT INTO users (email, password_hash, phone, first_name, last_name, address1, address2, city, postcode, country)
             VALUES (:e, :ph, :phone, :fn, :ln, :a1, :a2, :city, :pc, :country)',
            [
                ':e'      => $email,
                ':ph'     => password_hash($password, PASSWORD_DEFAULT),
                ':phone'  => ($profile['phone'] ?? '') ?: null,
                ':fn'     => ($profile['first_name'] ?? '') ?: null,
                ':ln'     => ($profile['last_name'] ?? '') ?: null,
                ':a1'     => ($profile['address1'] ?? '') ?: null,
                ':a2'     => ($profile['address2'] ?? '') ?: null,
                ':city'   => ($profile['city'] ?? '') ?: null,
                ':pc'     => ($profile['postcode'] ?? '') ?: null,
                ':country'=> ($profile['country'] ?? '') ?: null,
            ]
        );

        return $id;
    }

    /** Register from a request body and return a session token. */
    public function register(array $d): array
    {
        $this->registerUser(
            $this->first($d, ['email']),
            (string) ($d['password'] ?? ''),
            $this->profileFromBody($d)
        );
        return $this->issueSession(strtolower($this->first($d, ['email'])));
    }

    /** Verify credentials and return a session token. */
    public function login(string $email, string $password): array
    {
        $user = $this->findUserByEmail($email);
        if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            throw new AuthException('Incorrect email or password.');
        }
        return $this->issueSession($user['email']);
    }

    /**
     * Resolve the acting user for an order:
     *  - if already logged in (session email) -> use it;
     *  - else require email+password: log in if the account exists (correct
     *    password), or create the account inline. Returns the email.
     */
    public function authenticateForOrder(?string $sessionEmail, array $d): string
    {
        if ($sessionEmail) {
            return strtolower($sessionEmail);
        }
        $email = strtolower($this->first($d, ['email', 'customer_email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email is required.');
        }
        $password = (string) ($d['password'] ?? '');
        $user = $this->findUserByEmail($email);
        if ($user) {
            if (empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
                throw new AuthException('Incorrect email or password.');
            }
        } else {
            $this->registerUser($email, $password, $this->profileFromBody($d));
        }
        return $email;
    }

    private function issueSession(string $email): array
    {
        $s = $this->auth->issueSessionToken($email);
        return [
            'session_token' => $s['session_token'],   // new frontend
            'token'         => $s['session_token'],   // old frontend / backward compat
            'expires_at'    => $s['expires_at'],
        ];
    }

    // --- Contact / "request more info" -----------------------------------------

    public function submitContact(array $d): array
    {
        $email = strtolower($this->first($d, ['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email is required.');
        }
        $first = $this->first($d, ['first_name', 'name']);
        if ($first === '') {
            throw new ValidationException('First name is required.');
        }

        $data = [
            'first_name'       => $first,
            'last_name'        => $this->first($d, ['last_name']),
            'email'            => $email,
            'phone'            => $this->first($d, ['phone']),
            'country'          => $this->first($d, ['country']),
            'company'          => $this->first($d, ['company', 'company_name']),
            'message'          => $this->first($d, ['message', 'notes']),
            'product_interest' => $this->first($d, ['product_interest']),
        ];

        try {
            $this->db->insert(
                'INSERT INTO contact_submissions
                    (first_name, last_name, email, phone, company, country, message, product_interest)
                 VALUES (:first, :last, :email, :phone, :company, :country, :message, :product)',
                [
                    ':first'   => $data['first_name'], ':last' => $data['last_name'] ?: null,
                    ':email'   => $data['email'], ':phone' => $data['phone'] ?: null,
                    ':company' => $data['company'] ?: null, ':country' => $data['country'] ?: null,
                    ':message' => $data['message'] ?: null, ':product' => $data['product_interest'] ?: null,
                ]
            );
        } catch (\Throwable $e) {
            error_log('[nadgo-api] contact_submissions insert failed: ' . $e->getMessage());
        }

        $sent = false;
        try {
            $sent = $this->mailer->sendContactInquiry($data);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] contact email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->sendContactConfirmation($data);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] contact confirmation email failed: ' . $e->getMessage());
        }
        return ['sent' => $sent];
    }

    /** Admin: list contact-form submissions, most recent first. */
    public function adminListContactSubmissions(): array
    {
        return $this->db->pdo()
            ->query('SELECT * FROM contact_submissions ORDER BY id DESC')
            ->fetchAll();
    }

    // --- Product review (emails admins/contact, no DB) ------------------------

    public function submitProductReview(array $d): array
    {
        $email = strtolower($this->first($d, ['email']));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid email is required.');
        }
        $first = $this->first($d, ['first_name', 'name']);
        if ($first === '') {
            throw new ValidationException('First name is required.');
        }
        $product = $this->first($d, ['product_name', 'product']);
        if ($product === '') {
            throw new ValidationException('product_name is required.');
        }
        $rating = isset($d['rating']) ? (int) $d['rating'] : 0;
        if ($rating !== 0 && ($rating < 1 || $rating > 5)) {
            throw new ValidationException('rating must be between 1 and 5.');
        }

        $data = [
            'first_name'   => $first,
            'last_name'    => $this->first($d, ['last_name']),
            'email'        => $email,
            'product_name' => $product,
            'rating'       => $rating ?: null,
            'title'        => $this->first($d, ['title', 'review_title']),
            'review'       => $this->first($d, ['review', 'message', 'body']),
        ];

        $sent = false;
        try {
            $sent = $this->mailer->sendProductReview($data);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] product-review email failed: ' . $e->getMessage());
        }
        return ['sent' => $sent];
    }

    // --- Stripe card payment --------------------------------------------------

    /** Create a Stripe Checkout session for an existing order; returns checkout_url. */
    public function createStripeCheckout(string $orderRef): array
    {
        if (!$this->stripe || !$this->stripe->enabled()) {
            throw new ValidationException('Card payments are not available.');
        }
        $order = $this->findByRef($orderRef);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new ConflictException('This order is already paid.');
        }

        $itemCount = (int) $this->db->pdo()->query(
            'SELECT COALESCE(SUM(quantity),0) FROM order_items WHERE order_id = ' . (int) $order['id']
        )->fetchColumn();

        $session = $this->stripe->createCheckoutSession([
            'order_ref'   => $order['order_ref'],
            'email'       => $order['customer_email'],
            'currency'    => $order['currency'],
            'amount'      => (float) $order['total_amount'],
            'description' => 'Order ' . $order['order_ref'] . ' (' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ')',
            'success_url' => $this->stripe->successUrl($order['order_ref']),
            'cancel_url'  => $this->stripe->cancelUrl($order['order_ref']),
        ]);

        return ['checkout_url' => $session['url'], 'checkout_session_id' => $session['id']];
    }

    /** Mark an order paid by card (called from the verified Stripe webhook). Idempotent. */
    public function markCardPaid(string $orderRef, string $txnId, ?float $amount): void
    {
        $order = $this->findByRef($orderRef);
        if (!$order || $order['payment_status'] === 'paid') {
            return; // unknown or already paid
        }
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $this->db->insert(
                "INSERT INTO payments (order_id, method, amount, tx_id, status, verified_at)
                 VALUES (:oid, 'card', :amt, :tx, 'verified', UTC_TIMESTAMP())",
                [':oid' => $order['id'], ':amt' => $amount, ':tx' => $txnId ?: null]
            );
            $pdo->prepare(
                "UPDATE orders SET payment_method='card', payment_status='paid', paid_at=UTC_TIMESTAMP()
                 WHERE id = :id"
            )->execute([':id' => $order['id']]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $order = $this->findByRef($orderRef);
        try {
            $this->mailer->notifyAdminPaymentSubmitted($order, [
                'method' => 'card', 'amount' => $amount, 'tx_id' => $txnId,
                'receipt_path' => null, 'note' => 'Paid by card via Stripe (auto-confirmed).',
            ]);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] card-paid admin email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->notifyCustomerPaymentVerified($order);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] card-paid customer email failed: ' . $e->getMessage());
        }
    }

    /** Create a Stripe PaymentIntent for direct card entry (Stripe Elements on frontend). */
    public function createCardPaymentIntent(string $orderRef): array
    {
        if (!$this->stripe || !$this->stripe->enabled()) {
            throw new ValidationException('Card payments are not available.');
        }
        $order = $this->findByRef($orderRef);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }
        if ($order['payment_status'] === 'paid') {
            throw new ConflictException('This order is already paid.');
        }

        // Reuse the stored PaymentIntent if it is still actionable (survives page refreshes,
        // different devices, and declined-card retries on the same PI).
        $existingPiId = $order['stripe_pi_id'] ?? null;
        if ($existingPiId) {
            $pi     = $this->stripe->retrievePaymentIntent($existingPiId);
            $status = $pi['status'] ?? 'canceled';
            if (in_array($status, ['requires_payment_method', 'requires_confirmation', 'requires_action'], true)) {
                return [
                    'client_secret'     => (string) $pi['client_secret'],
                    'publishable_key'   => $this->stripe->publishableKey(),
                    'payment_intent_id' => (string) $pi['id'],
                ];
            }
            // PI is expired, canceled, or already succeeded — clear it and create a fresh one
            $this->db->pdo()->prepare('UPDATE orders SET stripe_pi_id = NULL WHERE id = :id')
                ->execute([':id' => $order['id']]);
        }

        $pi = $this->stripe->createPaymentIntent([
            'order_ref'   => $order['order_ref'],
            'email'       => $order['customer_email'],
            'currency'    => $order['currency'],
            'amount'      => (float) $order['total_amount'],
            'description' => 'Order ' . $order['order_ref'],
        ]);

        // Persist the PI ID so every subsequent request for this order reuses it
        $this->db->pdo()->prepare('UPDATE orders SET stripe_pi_id = :piid WHERE id = :id')
            ->execute([':piid' => $pi['id'], ':id' => $order['id']]);

        return [
            'client_secret'     => $pi['client_secret'],
            'publishable_key'   => $this->stripe->publishableKey(),
            'payment_intent_id' => $pi['id'],
        ];
    }

    /** Confirm a PaymentIntent succeeded, mark the order paid. */
    public function confirmCardPayment(string $orderRef, string $paymentIntentId): array
    {
        $order = $this->findByRef($orderRef);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }
        if ($order['payment_status'] === 'paid') {
            return ['payment_status' => 'paid'];
        }

        if (!$this->stripe || !$this->stripe->enabled()) {
            throw new ValidationException('Card payments are not available.');
        }

        $pi = $this->stripe->retrievePaymentIntent($paymentIntentId);
        $status = $pi['status'] ?? 'unknown';

        if ($status === 'succeeded') {
            $amt = isset($pi['amount']) ? ((int) $pi['amount']) / 100 : null;
            $this->markCardPaid($orderRef, $paymentIntentId, $amt);
            return ['payment_status' => 'paid'];
        }

        // Payment not yet succeeded — don't mark as paid.
        return ['payment_status' => $pi['status'] ?? 'unknown'];
    }

    // --- Password reset (emailed code) ----------------------------------------

    /** Email a reset code if the account exists. Always silent (no enumeration). */
    public function requestPasswordReset(string $email): void
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !$this->findUserByEmail($email)) {
            return;
        }
        $cfg  = $this->config['password_reset'] ?? [];
        $len  = (int) ($cfg['code_length'] ?? 6);
        $ttl  = (int) ($cfg['ttl_seconds'] ?? 900);
        $code = $this->auth->generateCode($len);

        $this->db->insert(
            'INSERT INTO password_resets (email, code_hash, expires_at)
             VALUES (:e, :h, (UTC_TIMESTAMP() + INTERVAL :ttl SECOND))',
            [':e' => $email, ':h' => $this->auth->hashCode($code), ':ttl' => $ttl]
        );

        try {
            $this->mailer->sendPasswordReset($email, $code, (int) round($ttl / 60));
        } catch (\Throwable $e) {
            error_log('[nadgo-api] password-reset email failed: ' . $e->getMessage());
        }
    }

    /** Verify the code and set a new password; returns a session token (auto-login). */
    public function resetPassword(string $email, string $code, string $newPassword): array
    {
        $email = strtolower(trim($email));
        if (strlen($newPassword) < self::MIN_PASSWORD) {
            throw new ValidationException('Password must be at least ' . self::MIN_PASSWORD . ' characters.');
        }
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT * FROM password_resets
              WHERE email = :e AND consumed_at IS NULL AND expires_at > UTC_TIMESTAMP()
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new ValidationException('Reset code is invalid or has expired. Request a new one.');
        }
        if ((int) $row['attempts'] >= (int) ($this->config['password_reset']['max_attempts'] ?? 5)) {
            throw new ValidationException('Too many attempts. Request a new code.');
        }
        if (!hash_equals($row['code_hash'], $this->auth->hashCode(trim($code)))) {
            $pdo->prepare('UPDATE password_resets SET attempts = attempts + 1 WHERE id = :id')
                ->execute([':id' => $row['id']]);
            throw new ValidationException('Incorrect reset code.');
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :ph WHERE email = :e')
                ->execute([':ph' => password_hash($newPassword, PASSWORD_DEFAULT), ':e' => $email]);
            // Burn all outstanding codes for this email.
            $pdo->prepare('UPDATE password_resets SET consumed_at = UTC_TIMESTAMP()
                           WHERE email = :e AND consumed_at IS NULL')->execute([':e' => $email]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        return $this->issueSession($email);
    }

    /** Pull profile fields out of a request body (for seeding a new account). */
    private function profileFromBody(array $d): array
    {
        return [
            'phone'      => $this->first($d, ['phone', 'customer_phone']),
            'first_name' => $this->first($d, ['first_name', 'ship_first_name']),
            'last_name'  => $this->first($d, ['last_name', 'ship_last_name']),
            'address1'   => $this->first($d, ['address', 'address1', 'ship_address1']),
            'address2'   => $this->first($d, ['apartment', 'address2', 'ship_address2']),
            'city'       => $this->first($d, ['city', 'ship_city']),
            'postcode'   => $this->first($d, ['postcode', 'postal_code', 'zip', 'ship_postcode']),
            'country'    => $this->first($d, ['country', 'ship_country']),
        ];
    }

    /** @return array list of orders for the logged-in email. */
    public function listOrdersByEmail(string $email): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM orders WHERE customer_email = :e ORDER BY id DESC'
        );
        $stmt->execute([':e' => strtolower(trim($email))]);
        $orders = $stmt->fetchAll();

        return array_map(fn($o) => $this->presentOrder($o, true), $orders);
    }

    // =========================================================================
    // Users / profile (login-session authenticated)
    // =========================================================================

    private function isUkCountry(string $country): bool
    {
        $normalized = strtolower(trim($country));
        return in_array($normalized, ['united kingdom', 'uk', 'gb', 'great britain'], true);
    }

    /** Find a user by email, or create one (profile seeded from the billing address). */
    private function findOrCreateUser(string $email, string $phone, array $ship): int
    {
        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id, address1, phone FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $row = $stmt->fetch();
        if ($row) {
            // Backfill address when the profile is empty (e.g. registered without an address,
            // then returning to place a first order). Never overwrites existing address data.
            if (empty($row['address1'])) {
                $pdo->prepare(
                    'UPDATE users
                        SET first_name = COALESCE(NULLIF(first_name, ""), :fn),
                            last_name  = COALESCE(NULLIF(last_name,  ""), :ln),
                            address1   = :a1,
                            address2   = :a2,
                            city       = :city,
                            postcode   = :pc,
                            country    = :country,
                            phone      = COALESCE(NULLIF(phone, ""), :phone)
                     WHERE id = :id'
                )->execute([
                    ':fn'     => $ship['first_name'] ?: null,
                    ':ln'     => $ship['last_name']  ?: null,
                    ':a1'     => $ship['address1']   ?: null,
                    ':a2'     => $ship['address2']   ?: null,
                    ':city'   => $ship['city']       ?: null,
                    ':pc'     => $ship['postcode']   ?: null,
                    ':country'=> $ship['country']    ?: null,
                    ':phone'  => $phone              ?: null,
                    ':id'     => (int) $row['id'],
                ]);
            }
            return (int) $row['id'];
        }

        return (int) $this->db->insert(
            'INSERT INTO users (email, phone, first_name, last_name, address1, address2, city, postcode, country)
             VALUES (:e, :phone, :fn, :ln, :a1, :a2, :city, :pc, :country)',
            [
                ':e'      => $email,
                ':phone'  => $phone ?: null,
                ':fn'     => $ship['first_name'] ?: null,
                ':ln'     => $ship['last_name']  ?: null,
                ':a1'     => $ship['address1']   ?: null,
                ':a2'     => $ship['address2']   ?: null,
                ':city'   => $ship['city']       ?: null,
                ':pc'     => $ship['postcode']   ?: null,
                ':country'=> $ship['country']    ?: null,
            ]
        );
    }

    /** Return the editable profile for a verified email. */
    public function getProfile(string $email): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT email, phone, first_name, last_name, address1, address2, city, postcode, country, created_at
               FROM users WHERE email = :e LIMIT 1'
        );
        $stmt->execute([':e' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        if (!$row) {
            throw new NotFoundException('Profile not found.');
        }
        return $row;
    }

    /** Update the profile (contact + address). Email is the identity and is not changed. */
    public function updateProfile(string $email, array $d): array
    {
        $email   = strtolower(trim($email));
        $current = $this->getProfile($email); // throws if missing

        // Merge provided values over current; only these fields are editable.
        $merged = [
            'phone'      => $this->firstOr($d, ['phone'], $current['phone']),
            'first_name' => $this->firstOr($d, ['first_name'], $current['first_name']),
            'last_name'  => $this->firstOr($d, ['last_name'], $current['last_name']),
            'address1'   => $this->firstOr($d, ['address', 'address1'], $current['address1']),
            'address2'   => $this->firstOr($d, ['apartment', 'address2'], $current['address2']),
            'city'       => $this->firstOr($d, ['city'], $current['city']),
            'postcode'   => $this->firstOr($d, ['postcode', 'postal_code', 'zip'], $current['postcode']),
            'country'    => $this->firstOr($d, ['country'], $current['country']),
        ];

        $stmt = $this->db->pdo()->prepare(
            'UPDATE users SET phone=:phone, first_name=:fn, last_name=:ln, address1=:a1,
                    address2=:a2, city=:city, postcode=:pc, country=:country
              WHERE email = :e'
        );
        $stmt->execute([
            ':phone' => $merged['phone'], ':fn' => $merged['first_name'], ':ln' => $merged['last_name'],
            ':a1' => $merged['address1'], ':a2' => $merged['address2'], ':city' => $merged['city'],
            ':pc' => $merged['postcode'], ':country' => $merged['country'], ':e' => $email,
        ]);

        return $this->getProfile($email);
    }

    // =========================================================================
    // Lookups & access control
    // =========================================================================

    public function findByRef(string $ref): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM orders WHERE order_ref = :r LIMIT 1');
        $stmt->execute([':r' => $ref]);
        return $stmt->fetch() ?: null;
    }

    private function findById(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /** True if the requester proved access to this order. */
    public function authorizeOrderAccess(array $order, ?string $providedToken, ?string $sessionEmail): bool
    {
        if ($providedToken && hash_equals($order['tracking_token'], $providedToken)) {
            return true;
        }
        if ($sessionEmail && strtolower($sessionEmail) === strtolower($order['customer_email'])) {
            return true;
        }
        return false;
    }

    /** Full customer-facing view of one order (items + payment history). */
    public function presentOrder(array $o, bool $includeToken = false): array
    {
        $pdo = $this->db->pdo();

        $itemsStmt = $pdo->prepare('SELECT product_name, dosage, purchase_type, variant, sku, image_url, unit_price, quantity, line_total
                                    FROM order_items WHERE order_id = :id');
        $itemsStmt->execute([':id' => $o['id']]);

        $payStmt = $pdo->prepare('SELECT id, method, amount, tx_id, receipt_path, status,
                                  reject_reason, submitted_at, verified_at, crypto_rate, crypto_network
                                  FROM payments WHERE order_id = :id ORDER BY id DESC');
        $payStmt->execute([':id' => $o['id']]);
        $payments = array_map(function ($p) {
            $p['has_receipt'] = !empty($p['receipt_path']);
            unset($p['receipt_path']); // never expose the stored path to customers
            return $p;
        }, $payStmt->fetchAll());

        $view = [
            'order_ref'        => $o['order_ref'],
            'customer_name'    => trim($o['ship_first_name'] . ' ' . $o['ship_last_name']),
            'customer_email'   => $o['customer_email'],
            'customer_phone'   => $o['customer_phone'],
            'billing_address' => [
                'first_name' => $o['bill_first_name'] ?? $o['ship_first_name'],
                'last_name'  => $o['bill_last_name'] ?? $o['ship_last_name'],
                'address1'   => $o['bill_address1'] ?? $o['ship_address1'],
                'address2'   => $o['bill_address2'] ?? $o['ship_address2'],
                'city'       => $o['bill_city'] ?? $o['ship_city'],
                'postcode'   => $o['bill_postcode'] ?? $o['ship_postcode'],
                'country'    => $o['bill_country'] ?? $o['ship_country'],
            ],
            'shipping_address' => [
                'first_name' => $o['ship_first_name'],
                'last_name'  => $o['ship_last_name'],
                'address1'   => $o['ship_address1'],
                'address2'   => $o['ship_address2'],
                'city'       => $o['ship_city'],
                'postcode'   => $o['ship_postcode'],
                'country'    => $o['ship_country'],
            ],
            'order_notes'      => $o['order_notes'] ?? null,
            'payment_status'   => $o['payment_status'],
            'paid_at'          => $o['paid_at'],
            'delivery_status'  => $o['delivery_status'],
            'payment_method'   => $o['payment_method'],
            'order_source'     => $o['order_source'] ?? 'web',
            'currency'         => $o['currency'],
            'subtotal_amount'  => $o['subtotal_amount'],
            'shipping_amount'  => $o['shipping_amount'],
            'tax_amount'       => $o['tax_amount'],
            'tax_rate'         => $o['tax_rate'],
            'coupon_code'      => $o['coupon_code']     ?? null,
            'discount_amount'  => $o['discount_amount'] ?? 0,
            'total_amount'     => $o['total_amount'],
            'shipping_carrier'     => $o['shipping_carrier'] ?? null,
            'shipping_method_code' => $o['shipping_method_code'] ?? null,
            'shipping_method_name' => $o['shipping_method_name'] ?? null,
            'shipping_tracking_no' => $o['shipping_tracking_no'] ?? null,
            'rm_order_id'          => $o['rm_order_id'] ?? null,
            'delivery_note'        => $o['delivery_note'],
            'created_at'           => $o['created_at'],
            'items'            => $itemsStmt->fetchAll(),
            'payments'         => $payments,
        ];
        if ($includeToken) {
            $view['tracking_token'] = $o['tracking_token'];
        }
        return $view;
    }

    // =========================================================================
    // Admin
    // =========================================================================

    public function adminListOrders(?string $paymentStatus, ?string $deliveryStatus, int $limit = 100): array
    {
        $sql  = 'SELECT * FROM orders';
        $args = [];
        $where = [];
        if ($paymentStatus)  { $where[] = 'payment_status = :ps';  $args[':ps'] = $paymentStatus; }
        if ($deliveryStatus) { $where[] = 'delivery_status = :ds'; $args[':ds'] = $deliveryStatus; }
        if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);
        return array_map(fn($o) => $this->presentOrder($o, true), $stmt->fetchAll());
    }

    // =========================================================================
    // Admin — manual orders (created by staff, no payment step)
    // =========================================================================

    /** Column names that really exist in `orders` (so the insert works on older DBs too). */
    private function orderColumns(): array
    {
        if ($this->orderColumnsCache === null) {
            $rows = $this->db->pdo()->query('SHOW COLUMNS FROM orders')->fetchAll();
            $this->orderColumnsCache = array_column($rows, 'Field');
        }
        return $this->orderColumnsCache;
    }

    /** Search customers (users table) by email, name or phone. Empty query = latest customers. */
    public function adminSearchCustomers(string $q, int $limit = 10): array
    {
        $limit = max(1, min(25, $limit));
        $sql = 'SELECT id, email, phone, first_name, last_name, address1, address2, city, postcode, country
                  FROM users';
        $args = [];
        $q = trim($q);
        if ($q !== '') {
            // Escape LIKE wildcards. Each placeholder is used once (native prepares).
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $sql .= ' WHERE email LIKE :q1 OR first_name LIKE :q2 OR last_name LIKE :q3
                         OR CONCAT_WS(" ", first_name, last_name) LIKE :q4 OR phone LIKE :q5';
            $args = [':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like];
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . $limit;
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    }

    /**
     * Create an order on behalf of a customer from the admin portal.
     *  - No payment proof / payment instructions / customer emails on creation.
     *  - Customer is looked up by email; if new, a customer record is created (unless save_customer=false).
     *  - Prices are whatever the admin typed (special deals allowed); stock is only touched if deduct_stock=true.
     */
    public function adminCreateManualOrder(array $d, string $ip): array
    {
        // ---- customer ------------------------------------------------------
        $email = strtolower($this->first($d, ['email', 'customer_email']));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ValidationException('A valid customer email is required.');
        }
        $phone = $this->first($d, ['phone', 'customer_phone']);

        $bill = [
            'first_name' => $this->first($d, ['first_name']),
            'last_name'  => $this->first($d, ['last_name']),
            'address1'   => $this->first($d, ['address1', 'address']),
            'address2'   => $this->first($d, ['address2']),
            'city'       => $this->first($d, ['city']),
            'postcode'   => $this->first($d, ['postcode']),
            'country'    => $this->first($d, ['country']),
        ];
        foreach (['first_name', 'last_name', 'address1', 'city', 'postcode', 'country'] as $req) {
            if ($bill[$req] === '') {
                throw new ValidationException('Customer ' . str_replace(['address1', '_'], ['address', ' '], $req) . ' is required.');
            }
        }

        // Shipping defaults to the customer address; optional different address.
        $ship = $bill;
        if (!empty($d['ship_to_different_address'])) {
            $ship = [
                'first_name' => $this->first($d, ['ship_first_name']),
                'last_name'  => $this->first($d, ['ship_last_name']),
                'address1'   => $this->first($d, ['ship_address1']),
                'address2'   => $this->first($d, ['ship_address2']),
                'city'       => $this->first($d, ['ship_city']),
                'postcode'   => $this->first($d, ['ship_postcode']),
                'country'    => $this->first($d, ['ship_country']),
            ];
            foreach (['first_name', 'last_name', 'address1', 'city', 'postcode', 'country'] as $req) {
                if ($ship[$req] === '') {
                    throw new ValidationException('Shipping ' . str_replace(['address1', '_'], ['address', ' '], $req) . ' is required.');
                }
            }
        }

        // ---- items & money -------------------------------------------------
        $items = $this->validateItems($d['items'] ?? []);
        $subtotal = round(array_sum(array_column($items, 'line_total')), 2);
        $shipping = max(0.0, $this->money($d['shipping_amount'] ?? 0));
        $discount = min($subtotal, max(0.0, $this->money($d['discount_amount'] ?? 0)));

        // Same rule as web checkout: UK prices already include 20% VAT (informational only).
        $isUk    = $this->isUkCountry($ship['country']);
        $vatBase = max(0.0, round($subtotal - $discount + $shipping, 2));
        $tax     = $isUk ? round($vatBase * (self::VAT_RATE / (1 + self::VAT_RATE)), 2) : 0.0;
        $taxRate = $isUk ? self::VAT_RATE : 0.0;
        $total   = max(0.0, round($subtotal + $shipping - $discount, 2));

        // ---- statuses & misc ----------------------------------------------
        $payStatus = $this->str($d, 'payment_status') ?: 'paid';
        if (!in_array($payStatus, ['paid', 'pending'], true)) {
            throw new ValidationException("payment_status must be 'paid' or 'pending'.");
        }
        $delStatus = $this->str($d, 'delivery_status') ?: 'pending';
        if (!in_array($delStatus, self::DELIVERY_STATES, true)) {
            throw new ValidationException('delivery_status must be one of: ' . implode(', ', self::DELIVERY_STATES));
        }
        $notes = trim((string) ($d['order_notes'] ?? ''));
        if (strlen($notes) > 2000) {
            throw new ValidationException('Order notes must be 2000 characters or fewer.');
        }
        $carrier    = $this->str($d, 'shipping_carrier');
        $methodName = $this->str($d, 'shipping_method_name');
        $currency   = strtoupper($this->str($d, 'currency')) ?: 'GBP';
        $orderRef   = $this->str($d, 'order_ref') ?: ('NG-' . strtoupper(bin2hex(random_bytes(4))));
        $token      = bin2hex(random_bytes(16));
        $saveCustomer = !array_key_exists('save_customer', $d) || !empty($d['save_customer']);
        $deductStock  = !empty($d['deduct_stock']);

        // ---- write ---------------------------------------------------------
        $customerCreated = false;
        $stockReport = [];
        $this->db->beginTransaction();
        try {
            $userId = null;
            $existing = $this->findUserByEmail($email);
            if ($existing) {
                $userId = $this->findOrCreateUser($email, $phone, $bill); // backfills empty profile only
            } elseif ($saveCustomer) {
                $userId = $this->findOrCreateUser($email, $phone, $bill);
                $customerCreated = true;
            }

            $row = [
                'order_ref'       => $orderRef,
                'tracking_token'  => $token,
                'user_id'         => $userId,
                'customer_email'  => $email,
                'customer_phone'  => $phone,
                'bill_first_name' => $bill['first_name'],
                'bill_last_name'  => $bill['last_name'],
                'bill_address1'   => $bill['address1'],
                'bill_address2'   => $bill['address2'] ?: null,
                'bill_city'       => $bill['city'],
                'bill_postcode'   => $bill['postcode'],
                'bill_country'    => $bill['country'],
                'ship_first_name' => $ship['first_name'],
                'ship_last_name'  => $ship['last_name'],
                'ship_address1'   => $ship['address1'],
                'ship_address2'   => $ship['address2'] ?: null,
                'ship_city'       => $ship['city'],
                'ship_postcode'   => $ship['postcode'],
                'ship_country'    => $ship['country'],
                'order_notes'     => $notes !== '' ? $notes : null,
                'currency'        => $currency,
                'subtotal_amount' => $subtotal,
                'shipping_amount' => $shipping,
                'tax_amount'      => $tax,
                'tax_rate'        => $taxRate,
                'discount_amount' => $discount,
                'total_amount'    => $total,
                'payment_method'  => 'manual',
                'order_source'    => 'manual',
                'payment_status'  => $payStatus,
                'paid_at'         => $payStatus === 'paid' ? gmdate('Y-m-d H:i:s') : null,
                'delivery_status' => $delStatus,
                'shipping_carrier'     => $carrier !== '' ? $carrier : null,
                'shipping_method_name' => $methodName !== '' ? $methodName : null,
                'raw_payload'     => json_encode($d, JSON_UNESCAPED_UNICODE),
                'source_ip'       => $ip,
            ];
            // Keep only columns that exist in this database.
            $row = array_intersect_key($row, array_flip($this->orderColumns()));

            $cols = array_keys($row);
            $sql  = 'INSERT INTO orders (' . implode(', ', $cols) . ') VALUES ('
                  . implode(', ', array_map(fn($c) => ':' . $c, $cols)) . ')';
            $params = [];
            foreach ($row as $c => $v) $params[':' . $c] = $v;

            $orderId = (int) $this->db->insert($sql, $params);
            $this->insertItems($orderId, $items);
            if ($deductStock) {
                // Checks stock AND subtracts in one atomic step; throws if not enough.
                $stockReport = $this->productService?->reserveStock($items) ?? [];
            }
            $this->db->commit();
        } catch (\PDOException $e) {
            $this->db->rollBack();
            if ($e->getCode() === '23000') {
                throw new ConflictException('An order with this order_ref already exists.');
            }
            throw $e;
        } catch (\Throwable $e) {
            // e.g. "out of stock" -> undo the whole order
            $this->db->rollBack();
            throw $e;
        }

        return [
            'order_ref'        => $orderRef,
            'tracking_token'   => $token,
            'customer_created' => $customerCreated,
            'stock_deducted'   => $deductStock,
            'stock_report'     => $stockReport,
            'order'            => $this->presentOrder($this->findById($orderId), true),
        ];
    }

    public function adminVerifyPayment(string $ref, string $decision, ?string $reason): array
    {
        $order = $this->findByRef($ref);
        if (!$order) throw new NotFoundException('Order not found.');
        if (!in_array($decision, ['verified', 'rejected', 'partial'], true)) {
            throw new ValidationException("decision must be 'verified', 'partial', or 'rejected'.");
        }

        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            // Update the most recent submitted payment, if any.
            $latest = $pdo->prepare("SELECT id FROM payments
                WHERE order_id = :id AND status = 'submitted' ORDER BY id DESC LIMIT 1");
            $latest->execute([':id' => $order['id']]);
            $paymentId = $latest->fetchColumn();

            if ($decision === 'verified') {
                if ($paymentId) {
                    $pdo->prepare("UPDATE payments SET status='verified', verified_at=UTC_TIMESTAMP()
                                   WHERE id = :pid")->execute([':pid' => $paymentId]);
                }
                $pdo->prepare("UPDATE orders SET payment_status='paid', paid_at=UTC_TIMESTAMP()
                                WHERE id = :id")->execute([':id' => $order['id']]);
            } elseif ($decision === 'partial') {
                if ($paymentId) {
                    $pdo->prepare("UPDATE payments SET status='partial', verified_at=UTC_TIMESTAMP()
                                   WHERE id = :pid")->execute([':pid' => $paymentId]);
                }
                $pdo->prepare("UPDATE orders SET payment_status='partial' WHERE id = :id")
                    ->execute([':id' => $order['id']]);
            } else {
                if ($paymentId) {
                    $pdo->prepare("UPDATE payments SET status='rejected', reject_reason=:r
                                   WHERE id = :pid")->execute([':r' => $reason, ':pid' => $paymentId]);
                }
                $pdo->prepare("UPDATE orders SET payment_status='rejected' WHERE id = :id")
                    ->execute([':id' => $order['id']]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $order = $this->findByRef($ref);

        try {
            if ($decision === 'verified') {
                $this->mailer->notifyCustomerPaymentVerified($order);
            } elseif ($decision === 'partial') {
                $this->mailer->notifyCustomerPaymentPartial($order);
            } else {
                $this->mailer->notifyCustomerPaymentRejected($order, $reason ?? '');
            }
        } catch (\Throwable $e) {
            error_log('[nadgo-api] customer payment notify failed: ' . $e->getMessage());
        }

        return ['order_ref' => $ref, 'payment_status' => $order['payment_status']];
    }

    public function adminUpdateDelivery(string $ref, array $d): array
    {
        $order = $this->findByRef($ref);
        if (!$order) throw new NotFoundException('Order not found.');

        $status = $this->str($d, 'delivery_status');
        if ($status === '' || !in_array($status, self::DELIVERY_STATES, true)) {
            throw new ValidationException('delivery_status must be one of: ' . implode(', ', self::DELIVERY_STATES));
        }

        $stmt = $this->db->pdo()->prepare(
            'UPDATE orders SET delivery_status = :s, shipping_carrier = :c,
                    shipping_tracking_no = :t, delivery_note = :n WHERE id = :id'
        );
        $stmt->execute([
            ':s'  => $status,
            ':c'  => $this->str($d, 'shipping_carrier') ?: $order['shipping_carrier'],
            ':t'  => $this->str($d, 'shipping_tracking_no') ?: $order['shipping_tracking_no'],
            ':n'  => $this->str($d, 'delivery_note') ?: $order['delivery_note'],
            ':id' => $order['id'],
        ]);

        $order = $this->findByRef($ref);

        try {
            if ($status === 'shipped') {
                $this->mailer->notifyCustomerOrderShipped($order);
            } elseif ($status === 'delivered') {
                $this->mailer->notifyCustomerOrderDelivered($order);
            } elseif ($status === 'cancelled') {
                $this->mailer->notifyCustomerOrderCancelled($order);
            }
        } catch (\Throwable $e) {
            error_log('[nadgo-api] customer delivery notify failed: ' . $e->getMessage());
        }

        return ['order_ref' => $ref, 'delivery_status' => $status];
    }

    /** Resolve a receipt file for admin download. */
    public function adminReceiptPath(int $paymentId): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT receipt_path FROM payments WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $paymentId]);
        $name = $stmt->fetchColumn();
        if (!$name) throw new NotFoundException('Receipt not found.');

        $path = $this->uploads->absolutePath($name);
        if (!is_file($path)) throw new NotFoundException('Receipt file is missing on disk.');
        return ['path' => $path, 'filename' => $name];
    }

    /** Save Royal Mail shipment data after successful API creation. */
    public function saveRmShipment(string $ref, string $rmOrderId, ?string $trackingNumber): void
    {
        $order = $this->findByRef($ref);
        if (!$order) return;
        $newStatus = $order['delivery_status'] === 'pending' ? 'processing' : $order['delivery_status'];
        $tracking  = $trackingNumber ?: ($order['shipping_tracking_no'] ?? '');
        $this->db->pdo()->prepare(
            "UPDATE orders
             SET rm_order_id          = :rmid,
                 shipping_carrier     = 'Royal Mail',
                 shipping_tracking_no = :tracking,
                 delivery_status      = :status
             WHERE order_ref = :ref"
        )->execute([':rmid' => $rmOrderId, ':tracking' => $tracking, ':status' => $newStatus, ':ref' => $ref]);
    }

    /** Save a successfully-created FedEx shipment against the order. */
    public function saveFedExShipment(string $ref, string $trackingNumber): void
    {
        $order = $this->findByRef($ref);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }

        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw new ValidationException('FedEx tracking number is required.');
        }

        $newStatus = $order['delivery_status'] === 'pending'
            ? 'processing'
            : $order['delivery_status'];

        $this->db->pdo()->prepare(
            "UPDATE orders
             SET shipping_carrier     = 'FedEx',
                 shipping_tracking_no = :tracking,
                 delivery_status      = :status
             WHERE order_ref = :ref"
        )->execute([
            ':tracking' => $trackingNumber,
            ':status'   => $newStatus,
            ':ref'      => $ref,
        ]);
    }

    /** Clear FedEx shipment data after FedEx confirms cancellation. */
    public function clearFedExShipment(string $ref): void
    {
        $order = $this->findByRef($ref);
        if (!$order) {
            throw new NotFoundException('Order not found.');
        }

        $newStatus = $order['delivery_status'] === 'processing'
            ? 'pending'
            : $order['delivery_status'];

        $this->db->pdo()->prepare(
            "UPDATE orders
             SET shipping_tracking_no = NULL,
                 delivery_status      = :status
             WHERE order_ref = :ref"
        )->execute([
            ':status' => $newStatus,
            ':ref'    => $ref,
        ]);
    }

    /** Clear Royal Mail shipment data (after cancellation). */
    public function clearRmShipment(string $ref): void
    {
        $this->db->pdo()->prepare(
            "UPDATE orders SET rm_order_id = NULL WHERE order_ref = :ref"
        )->execute([':ref' => $ref]);
    }

    // -------------------------------------------------------------------------
    // Order documents
    // -------------------------------------------------------------------------

    public function addDocument(string $ref, string $name, array $file): array
    {
        $stored = $this->uploads->storeDocument($file, $ref);
        $pdo    = $this->db->pdo();
        $pdo->prepare(
            "INSERT INTO order_documents (order_ref, document_name, file_name, file_path, mime_type, file_size)
             VALUES (:ref, :name, :fname, :fpath, :mime, :size)"
        )->execute([
            ':ref'   => $ref,
            ':name'  => trim($name),
            ':fname' => basename($file['name'] ?? $stored['filename']),
            ':fpath' => $stored['filename'],
            ':mime'  => $stored['mime'],
            ':size'  => $stored['size'],
        ]);
        $id = (int) $pdo->lastInsertId();
        return $this->getDocument($id);
    }

    public function getDocument(int $id): array
    {
        $stmt = $this->db->pdo()->prepare('SELECT * FROM order_documents WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $doc = $stmt->fetch();
        if (!$doc) throw new NotFoundException('Document not found.');
        return $doc;
    }

    public function deleteDocument(int $id): void
    {
        $doc = $this->getDocument($id);
        $path = $this->uploads->documentPath($doc['file_path']);
        $this->db->pdo()->prepare('DELETE FROM order_documents WHERE id = :id')->execute([':id' => $id]);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public function listDocuments(string $ref): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, order_ref, document_name, file_name, mime_type, file_size, created_at
             FROM order_documents WHERE order_ref = :ref ORDER BY id ASC'
        );
        $stmt->execute([':ref' => $ref]);
        return $stmt->fetchAll();
    }

    public function documentPath(int $id): array
    {
        $doc  = $this->getDocument($id);
        $path = $this->uploads->documentPath($doc['file_path']);
        if (!is_file($path)) throw new NotFoundException('Document file is missing on disk.');
        return ['path' => $path, 'filename' => $doc['file_name'], 'mime' => $doc['mime_type']];
    }

    // =========================================================================
    // Validation helpers
    // =========================================================================

    private function validateItems($items): array
    {
        if (!is_array($items) || $items === []) {
            throw new ValidationException('At least one order item is required.');
        }
        $out = [];
        foreach ($items as $i => $it) {
            if (!is_array($it)) throw new ValidationException("items[$i] must be an object.");
            $product = $this->str($it, 'product_name');
            if ($product === '') throw new ValidationException("items[$i].product_name is required.");
            $price = $this->money($it['price'] ?? $it['unit_price'] ?? 0);
            $qty   = max(1, (int) ($it['quantity'] ?? 1));

            // Two variant axes: dosage (e.g. 500mg) and purchase type
            // (Subscribe & Save / One-time). Build a combined label if none given.
            $dosage   = $this->first($it, ['dosage', 'strength']);
            $purchase = $this->first($it, ['purchase_type', 'plan', 'option']);
            $variant  = $this->first($it, ['variant']);
            if ($variant === '') {
                $variant = implode(' · ', array_filter([$dosage, $purchase]));
            }

            $out[] = [
                'product_name' => $product,
                'dosage'       => $dosage ?: null,
                'purchase_type'=> $purchase ?: null,
                'variant'      => $variant ?: null,
                'sku'          => $this->str($it, 'sku') ?: null,
                'image_url'    => $this->first($it, ['image_url', 'image', 'thumbnail']) ?: null,
                'unit_price'   => $price,
                'quantity'     => $qty,
                'line_total'   => round($price * $qty, 2),
            ];
        }
        return $out;
    }

    private function insertItems(int $orderId, array $items): void
    {
        foreach ($items as $it) {
            $this->db->insert(
                'INSERT INTO order_items
                    (order_id, product_name, dosage, purchase_type, variant, sku, image_url, unit_price, quantity, line_total)
                 VALUES (:oid, :name, :dosage, :ptype, :variant, :sku, :img, :price, :qty, :total)',
                [
                    ':oid'     => $orderId,
                    ':name'    => $it['product_name'],
                    ':dosage'  => $it['dosage'],
                    ':ptype'   => $it['purchase_type'],
                    ':variant' => $it['variant'],
                    ':sku'     => $it['sku'],
                    ':img'     => $it['image_url'],
                    ':price'   => $it['unit_price'],
                    ':qty'     => $it['quantity'],
                    ':total'   => $it['line_total'],
                ]
            );
        }
    }

    private function generateRef(): string
    {
        return 'NG-' . strtoupper(bin2hex(random_bytes(4)));
    }

    private function str(array $a, string $k): string
    {
        return isset($a[$k]) && is_scalar($a[$k]) ? trim((string) $a[$k]) : '';
    }

    /** First non-empty value among the given keys (supports field aliases). */
    private function first(array $a, array $keys): string
    {
        foreach ($keys as $k) {
            $v = $this->str($a, $k);
            if ($v !== '') return $v;
        }
        return '';
    }

    /** Like first(), but returns $default when none of the keys are provided. */
    private function firstOr(array $a, array $keys, $default)
    {
        $v = $this->first($a, $keys);
        return $v !== '' ? $v : $default;
    }

    private function money($v): float
    {
        return round((float) $v, 2);
    }
}