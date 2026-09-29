<?php
/**
 * SMTP mailer built on PHPMailer (composer's copy if autoloaded, else the
 * bundled lib/PHPMailer). Exposes one method per transactional email.
 */

use PHPMailer\PHPMailer\PHPMailer;

// Use composer's PHPMailer if it's already autoloaded; otherwise fall back to
// the bundled copy in lib/PHPMailer (no composer needed).
if (!class_exists(PHPMailer::class)) {
    require_once __DIR__ . '/../lib/PHPMailer/Exception.php';
    require_once __DIR__ . '/../lib/PHPMailer/PHPMailer.php';
    require_once __DIR__ . '/../lib/PHPMailer/SMTP.php';
}

class Mailer
{
    private ?string $lastError = null;

    public function __construct(private array $cfg, private array $brand = []) {}

    /** Last transport error (for the admin test endpoint). */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** Send a branded test email; returns false and sets lastError() on failure. */
    public function sendTest(string $to): bool
    {
        $this->lastError = null;
        $transport = $this->cfg['transport'] ?? 'smtp';
        $body = '<h2>Email is working ✔</h2>'
            . '<p>This is a test message from the Nadgo API using the <strong>'
            . $this->e($transport) . '</strong> transport.</p>'
            . '<p>If you can read this, your configuration is correct.</p>';
        try {
            $ok = $this->send($to, 'Nadgo email test', $body);
            if (!$ok && $this->lastError === null) {
                $this->lastError = 'Transport returned false (check server error log).';
            }
            return $ok;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            return false;
        }
    }

    // --- public API ------------------------------------------------------------

    /** New order placed (step 1) -> notify admin. */
    public function notifyAdminNewOrder(array $order, array $items): bool
    {
        $subject = sprintf('New order %s — %s %s (payment pending)',
            $order['order_ref'],
            number_format((float) $order['total_amount'], 2),
            $order['currency']);

        $body = '<h2>New order: ' . $this->e($order['order_ref']) . '</h2>'
            . $this->customerBlock($order)
            . $this->itemsTable($items)
            . $this->totalsBlock($order)
            . '<p>Payment status: pending</p>';

        return $this->send($this->adminRecipients(), $subject, $body, $order['customer_email'] ?? null);
    }

    /** Customer submitted a payment proof (step 2) -> notify admin to verify. */
    public function notifyAdminPaymentSubmitted(array $order, array $payment): bool
    {
        $subject = sprintf('Payment submitted for %s — please verify', $order['order_ref']);

        $isCrypto  = ($payment['method'] ?? '') === 'crypto';
        $amountVal = number_format((float) ($payment['amount'] ?? $order['total_amount']), 2);
        $amountCur = $isCrypto ? 'USDT' : $this->e($order['currency']);

        $body = '<h2>Payment submitted: ' . $this->e($order['order_ref']) . '</h2>'
            . $this->customerBlock($order)
            . '<p>Method: ' . $this->e($payment['method']) . '<br>'
            . 'Amount: ' . $amountVal . ' ' . $amountCur . '<br>'
            . ($isCrypto && !empty($payment['crypto_network']) ? 'Network: ' . $this->e($payment['crypto_network']) . '<br>' : '')
            . ($isCrypto && !empty($payment['crypto_rate'])    ? 'Rate: 1 USDT = £' . number_format((float) $payment['crypto_rate'], 4) . '<br>' : '')
            . 'Transaction / reference: ' . $this->e($payment['tx_id'] ?? '—') . '<br>'
            . 'Receipt file: ' . ($payment['receipt_path'] ? 'attached (view in admin)' : 'none') . '<br>'
            . 'Note: ' . $this->e($payment['note'] ?? '—') . '</p>'
            . '<p>Verify it in the admin portal.</p>';

        return $this->send($this->adminRecipients(), $subject, $body, $order['customer_email'] ?? null);
    }

    /** Contact / "request more info" form -> admins (no DB; reply goes to sender). */
    public function sendContactInquiry(array $d): bool
    {
        $name = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        $subject = 'New enquiry' . ($name !== '' ? ' from ' . $name : '');

        $company  = trim($d['company'] ?? '');
        $product  = trim($d['product_interest'] ?? '');
        $rows = [
            'Name'    => $name,
            'Company' => $company,
            'Email'   => $d['email']   ?? '',
            'Phone'   => $d['phone']   ?? '',
            'Country' => $d['country'] ?? '',
        ];
        $isWholesale = $company !== '';
        $body = '<h2>' . ($isWholesale ? 'Wholesale application' : 'New partner / info request') . '</h2>';
        if ($product !== '') {
            $body .= '<p>User inquired about product: <strong>' . $this->e($product) . '</strong></p>';
        }
        foreach ($rows as $k => $v) {
            if (trim((string) $v) !== '') {
                $body .= '<p><strong>' . $this->e($k) . ':</strong> ' . $this->e($v) . '</p>';
            }
        }
        if (!empty($d['message'])) {
            $body .= '<h3>Message</h3><p>' . nl2br($this->e($d['message'])) . '</p>';
        }

        return $this->send($this->contactRecipients(), $subject, $body, ($d['email'] ?? '') ?: null);
    }

    /** Contact form auto-reply -> the submitter confirming their message was received. */
    public function sendContactConfirmation(array $d): bool
    {
        $email = trim($d['email'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $name    = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        $company = trim($d['company'] ?? '');
        $product = trim($d['product_interest'] ?? '');
        $support = $this->brand['support_email'] ?? ($this->adminRecipients()[0] ?? 'admin@nad-go.com');
        $color   = $this->brand['color'] ?? '#0F2057';

        $isWholesale = $company !== '';
        $isProduct   = !$isWholesale && $product !== '';
        $subject = $isWholesale ? 'We received your wholesale application' : ($isProduct ? 'Thank you for your interest in ' . $product : 'We received your message');

        $body = '<h2>' . ($isWholesale ? 'Thank you for your wholesale application' : ($isProduct ? 'Thank you for your interest' : 'Thank you for getting in touch')) . '</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>';

        if ($isProduct) {
            $body .= '<p>Thank you for showing interest in this product <strong>' . $this->e($product) . '</strong>. Our team will notify you as soon as the product is available.</p>';
        } else {
            $body .= '<p>We have received your ' . ($isWholesale ? 'wholesale application' : 'message') . ' and will get back to you as soon as possible.</p>';
        }

        if (!empty($d['message'])) {
            $body .= '<h3>Your message</h3>'
                . '<p style="background:#f5f6f8;border-left:3px solid ' . $color . ';padding:10px 14px;border-radius:0 4px 4px 0;margin:0">'
                . nl2br($this->e($d['message'])) . '</p>';
        }

        $body .= '<p style="margin-top:20px">If you have any questions in the meantime, reply to this email or contact us at '
            . '<a href="mailto:' . $this->e($support) . '" style="color:' . $color . ';text-decoration:none;">' . $this->e($support) . '</a>.</p>';

        return $this->send($email, $subject, $body, $support);
    }

    /** Product review submitted by a customer -> contact recipients (reply goes to reviewer). */
    public function sendProductReview(array $d): bool
    {
        $name    = trim(($d['first_name'] ?? '') . ' ' . ($d['last_name'] ?? ''));
        $product = $d['product_name'] ?? '';
        $rating  = (int) ($d['rating'] ?? 0);
        $subject = 'New product review' . ($product !== '' ? ' — ' . $product : '')
            . ($name !== '' ? ' from ' . $name : '');

        $stars = $rating > 0
            ? str_repeat('★', min($rating, 5)) . str_repeat('☆', max(0, 5 - $rating)) . ' (' . $rating . '/5)'
            : '';

        $rows = [
            'Reviewer' => $name,
            'Email'    => $d['email']  ?? '',
            'Product'  => $product,
            'Rating'   => $stars,
        ];
        $body = '<h2>New product review</h2>';
        foreach ($rows as $k => $v) {
            if (trim((string) $v) !== '') {
                $body .= '<p><strong>' . $this->e($k) . ':</strong> ' . $this->e($v) . '</p>';
            }
        }
        if (!empty($d['title'])) {
            $body .= '<p><strong>Title:</strong> ' . $this->e($d['title']) . '</p>';
        }
        if (!empty($d['review'])) {
            $body .= '<h3>Review</h3><p>' . nl2br($this->e($d['review'])) . '</p>';
        }

        return $this->send($this->contactRecipients(), $subject, $body, ($d['email'] ?? '') ?: null);
    }

    /** Forgot-password reset code -> the account's email. */
    public function sendPasswordReset(string $email, string $code, int $ttlMinutes): bool
    {
        $color = $this->brand['color'] ?? '#0F2057';
        $body = '<h2>Reset your password</h2>'
            . '<p>Use this code to reset your password:</p>'
            . '<p style="font-size:28px;font-weight:bold;letter-spacing:6px;color:' . $color . '">'
            . $this->e($code) . '</p>'
            . '<p>It expires in ' . (int) $ttlMinutes . ' minutes. If you did not request this, '
            . 'you can safely ignore this email.</p>';

        return $this->send($email, 'Your password reset code', $body);
    }

    /** Order confirmed — sends confirmation + T&C notice + payment link to customer. */
    public function sendOrderConfirmation(array $order, array $items): bool
    {
        $ref   = $order['order_ref'] ?? '';
        $total = number_format((float) ($order['total_amount'] ?? 0), 2);
        $cur   = $order['currency'] ?? 'GBP';
        $name  = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $color = $this->brand['color'] ?? '#0F2057';
        $site  = rtrim($this->brand['website_url'] ?? '', '/');

        $subject = 'Your order ' . $ref . ' is confirmed — ' . $total . ' ' . $cur;

        $payLink   = '';
        $termsAlert = '';
        if ($site !== '' && !empty($order['tracking_token'])) {
            $url = $site . '/order/' . rawurlencode($ref) . '?token=' . rawurlencode($order['tracking_token']);
            $payLink = '<p style="text-align:center;margin:16px 0 24px">'
                . '<a href="' . $this->e($url) . '" style="display:inline-block;padding:12px 28px;background:' . $color . ';color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">Complete Payment &rarr;</a>'
                . '</p>';
        }

        if ($site !== '') {
            $termsUrl   = $this->e($site . '/terms-conditions');
            $termsAlert = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:20px 0 0"><tr>'
                . '<td style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 16px">'
                . '<p style="margin:0 0 6px;font-weight:bold;color:#92400e">⚠&nbsp; Before you pay — please read our Terms &amp; Conditions</p>'
                . '<p style="margin:0;font-size:13px;color:#78350f;line-height:1.55">'
                . 'All NadGo™ products are supplied strictly for <strong>research use only</strong> and are not approved for human or veterinary administration. '
                . 'By completing your payment you confirm that you have read and accepted our '
                . '<a href="' . $termsUrl . '" style="color:#b45309;font-weight:bold;text-decoration:underline;">Terms &amp; Conditions</a>.'
                . '</p>'
                . '</td></tr></table>';
        }

        $body = '<h2>Order confirmed: ' . $this->e($ref) . '</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Thank you for your order! We have received it and will process it as soon as your payment is confirmed.</p>'
            . $this->itemsTable($items)
            . $this->totalsBlock($order)
            . $this->customerBlock($order)
            . '<h3>Next steps</h3>'
            . '<p>Please submit your payment using the button below. Once we verify your payment, your order will be dispatched. <em>Ignore this message if you have already paid.</em></p>'
            . $termsAlert
            . $payLink
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0"><tr><td style="background:#f5f5f7;border:1px solid #dddde3;border-radius:6px;padding:12px 14px">'
            . '<p style="margin:0 0 4px 0;font-size:13px;font-weight:bold;color:#1a1a2e">Card Payment Processing</p>'
            . '<p style="margin:0;font-size:12px;color:#6b7280">Card payments are securely processed and received on our behalf by our authorized payment partner, <strong style="color:#1a1a2e">Black Horse Consultancy Management FZE</strong>.</p>'
            . '</td></tr></table>'
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong> &mdash; keep this for your records.</p>';

        return $this->send($order['customer_email'] ?? '', $subject, $body);
    }

    // --- customer order status notifications -----------------------------------

    /** Payment verified by admin (or Stripe card) → order now processing. */
    public function notifyCustomerPaymentVerified(array $order): bool
    {
        $ref   = $order['order_ref'] ?? '';
        $name  = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $total = number_format((float) ($order['total_amount'] ?? 0), 2);
        $cur   = $order['currency'] ?? 'GBP';
        $link  = $this->orderLink($order);

        $body = '<h2>Payment confirmed ✓</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Great news — your payment of <strong>' . $total . ' ' . $this->e($cur) . '</strong> for order <strong>' . $this->e($ref) . '</strong> has been verified. Your order is now being prepared for dispatch.</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#f0faf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#15803d">What happens next?</p>'
            . '<ol style="margin:4px 0 0;padding-left:18px;color:#166534;line-height:1.7">'
            . '<li>Our team is preparing your order</li>'
            . '<li>You will receive a shipping confirmation with tracking details once dispatched</li>'
            . '<li>Estimated delivery will be shared at the time of shipping</li>'
            . '</ol></td></tr></table>'
            . $this->totalsBlock($order)
            . $link
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong> &mdash; keep this for your records.</p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Payment confirmed — Order ' . $ref . ' is being processed',
            $body
        );
    }

    /** Admin marked payment as partial — customer needs to complete the balance. */
    public function notifyCustomerPaymentPartial(array $order): bool
    {
        $ref   = $order['order_ref'] ?? '';
        $name  = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $total   = number_format((float) ($order['total_amount'] ?? 0), 2);
        $cur     = $order['currency'] ?? 'GBP';
        $ctaLink = $this->orderLink($order, 'Continue to Payment &rarr;', '#d97706');

        $body = '<h2>Partial payment received</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>We have received a partial payment for your order <strong>' . $this->e($ref) . '</strong>. The full order total is <strong>' . $total . ' ' . $this->e($cur) . '</strong>.</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#92400e">Action required</p>'
            . '<p style="margin:0;color:#78350f">Please submit the remaining balance to complete your order. '
            . 'Your order will not be dispatched until the full amount is received.</p>'
            . '</td></tr></table>'
            . $this->totalsBlock($order)
            . $ctaLink
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong></p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Action required — Partial payment received for Order ' . $ref,
            $body
        );
    }

    /** Admin rejected the payment proof — customer needs to resubmit. */
    public function notifyCustomerPaymentRejected(array $order, string $reason = ''): bool
    {
        $ref  = $order['order_ref'] ?? '';
        $name = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));

        $reasonBlock = $reason !== ''
            ? '<table width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0"><tr>'
              . '<td style="background:#fff5f5;border-left:3px solid #f87171;border-radius:0 6px 6px 0;padding:10px 14px">'
              . '<p style="margin:0;font-size:13px;color:#991b1b"><strong>Reason:</strong> ' . $this->e($reason) . '</p>'
              . '</td></tr></table>'
            : '';

        $ctaLink = $this->orderLink($order, 'Continue to Payment &rarr;', '#dc2626');

        $body = '<h2>Payment not accepted</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Unfortunately we were unable to verify your payment for order <strong>' . $this->e($ref) . '</strong>.</p>'
            . $reasonBlock
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#fef2f2;border:1px solid #fca5a5;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#991b1b">What to do next</p>'
            . '<ol style="margin:4px 0 0;padding-left:18px;color:#7f1d1d;line-height:1.7">'
            . '<li>Review your payment details and ensure the correct amount was sent</li>'
            . '<li>If you paid via bank transfer, double-check the reference number matches your order</li>'
            . '<li>Resubmit your payment proof using the button below</li>'
            . '<li>Contact us if you need assistance</li>'
            . '</ol></td></tr></table>'
            . $ctaLink
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong></p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Payment not accepted — Action required for Order ' . $ref,
            $body
        );
    }

    /** Order has been shipped — includes carrier and tracking number. */
    public function notifyCustomerOrderShipped(array $order): bool
    {
        $ref     = $order['order_ref'] ?? '';
        $name    = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $carrier = $order['shipping_carrier'] ?? '';
        $trackNo = $order['shipping_tracking_no'] ?? '';
        $note    = $order['delivery_note'] ?? '';
        $link    = $this->orderLink($order);

        $trackingBlock = '';
        if ($carrier !== '' || $trackNo !== '') {
            $trackingBlock = '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
                . '<td style="background:#eff6ff;border:1px solid #93c5fd;border-radius:8px;padding:14px 16px">'
                . '<p style="margin:0 0 8px;font-weight:bold;color:#1d4ed8">Tracking information</p>';
            if ($carrier !== '') {
                $trackingBlock .= '<p style="margin:0 0 4px;color:#1e40af"><strong>Carrier:</strong> ' . $this->e($carrier) . '</p>';
            }
            if ($trackNo !== '') {
                $trackingBlock .= '<p style="margin:0;color:#1e40af"><strong>Tracking number:</strong> '
                    . '<span style="font-family:monospace;font-size:15px">' . $this->e($trackNo) . '</span></p>';
            }
            $trackingBlock .= '</td></tr></table>';
        }

        $noteBlock = $note !== ''
            ? '<p style="background:#f9fafb;border-radius:6px;padding:10px 14px;font-size:13px;color:#4b5563">'
              . '<strong>Note from our team:</strong> ' . $this->e($note) . '</p>'
            : '';

        $body = '<h2>Your order is on its way! 🚚</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Your order <strong>' . $this->e($ref) . '</strong> has been dispatched and is now on its way to you.</p>'
            . $trackingBlock
            . $noteBlock
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#f0faf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#15803d">What to expect</p>'
            . '<ul style="margin:4px 0 0;padding-left:18px;color:#166534;line-height:1.7">'
            . '<li>Use your tracking number to follow the shipment progress</li>'
            . '<li>Ensure someone is available to receive the package</li>'
            . '<li>Contact us immediately if there are any delivery issues</li>'
            . '</ul></td></tr></table>'
            . $link
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong></p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Your order ' . $ref . ' has been shipped',
            $body
        );
    }

    /** Order has been delivered. */
    public function notifyCustomerOrderDelivered(array $order): bool
    {
        $ref     = $order['order_ref'] ?? '';
        $name    = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $support = $this->e($this->brand['support_email'] ?? ($this->adminRecipients()[0] ?? 'info@nad-go.com'));
        $color   = $this->brand['color'] ?? '#0F2057';
        $link    = $this->orderLink($order);

        $body = '<h2>Order delivered ✓</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Your order <strong>' . $this->e($ref) . '</strong> has been marked as delivered. We hope everything arrived in perfect condition!</p>'
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#f0faf4;border:1px solid #86efac;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#15803d">Thank you for your order</p>'
            . '<p style="margin:0;color:#166534">If you have any questions about your products or need assistance, '
            . 'please do not hesitate to reach out to our support team at '
            . '<a href="mailto:' . $support . '" style="color:' . $color . ';text-decoration:none;">' . $support . '</a>.</p>'
            . '</td></tr></table>'
            . $link
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong></p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Your order ' . $ref . ' has been delivered',
            $body,
            null,
            $this->trustpilotBcc()
        );
    }

    /** Order has been cancelled. */
    public function notifyCustomerOrderCancelled(array $order): bool
    {
        $ref     = $order['order_ref'] ?? '';
        $name    = trim(($order['ship_first_name'] ?? '') . ' ' . ($order['ship_last_name'] ?? ''));
        $support = $this->e($this->brand['support_email'] ?? ($this->adminRecipients()[0] ?? 'info@nad-go.com'));
        $color   = $this->brand['color'] ?? '#0F2057';
        $note    = $order['delivery_note'] ?? '';

        $noteBlock = $note !== ''
            ? '<table width="100%" cellpadding="0" cellspacing="0" style="margin:12px 0"><tr>'
              . '<td style="background:#fff5f5;border-left:3px solid #f87171;border-radius:0 6px 6px 0;padding:10px 14px">'
              . '<p style="margin:0;font-size:13px;color:#991b1b"><strong>Reason:</strong> ' . $this->e($note) . '</p>'
              . '</td></tr></table>'
            : '';

        $body = '<h2>Order cancelled</h2>'
            . '<p>Hi ' . ($name !== '' ? $this->e($name) : 'there') . ',</p>'
            . '<p>Your order <strong>' . $this->e($ref) . '</strong> has been cancelled.</p>'
            . $noteBlock
            . '<table width="100%" cellpadding="0" cellspacing="0" style="margin:16px 0"><tr>'
            . '<td style="background:#fffbeb;border:1px solid #fcd34d;border-radius:8px;padding:14px 16px">'
            . '<p style="margin:0 0 4px;font-weight:bold;color:#92400e">Questions about your cancellation?</p>'
            . '<p style="margin:0;color:#78350f">If you believe this is an error or would like to discuss your order, '
            . 'please contact us at <a href="mailto:' . $support . '" style="color:' . $color . ';text-decoration:none;">' . $support . '</a> '
            . 'or reply to this email and we will be happy to assist you.</p>'
            . '</td></tr></table>'
            . '<p style="color:#8a93a6;font-size:13px">Order reference: <strong>' . $this->e($ref) . '</strong></p>';

        return $this->send(
            $order['customer_email'] ?? '',
            'Your order ' . $ref . ' has been cancelled',
            $body
        );
    }

    // --- core ------------------------------------------------------------------

    /** Admin notification recipients (one or many). */
    private function adminRecipients(): array
    {
        return $this->recipientList($this->cfg['admin_emails'] ?? []);
    }

    /** Contact-form recipients; falls back to admin recipients if unset. */
    private function contactRecipients(): array
    {
        $list = $this->recipientList($this->cfg['contact_emails'] ?? []);
        return $list ?: $this->adminRecipients();
    }

    /** Trustpilot's AFS invite address (BCC'd to trigger a review request), if configured. */
    private function trustpilotBcc(): ?string
    {
        $addr = trim((string) ($this->cfg['trustpilot_bcc'] ?? ''));
        return $addr !== '' ? $addr : null;
    }

    private function recipientList($v): array
    {
        if (!is_array($v)) {
            $v = [$v];
        }
        return array_values(array_filter(array_map('trim', $v)));
    }

    /**
     * Send a branded email. $to may be a single address or an array.
     * Dispatches to the configured transport (Resend/Brevo API, or SMTP).
     */
    private function send($to, string $subject, string $htmlBody, ?string $replyTo = null, ?string $bcc = null): bool
    {
        $recipients = array_values(array_filter(array_map('trim', is_array($to) ? $to : [$to])));
        if (!$recipients) {
            return false;
        }

        $html = $this->template($subject, $htmlBody);
        $text = trim(strip_tags(str_replace(['<br>', '</p>', '</h2>', '</h3>', '</tr>'], "\n", $htmlBody)));

        return match ($this->cfg['transport'] ?? 'smtp') {
            'resend' => $this->sendViaResend($recipients, $subject, $html, $text, $replyTo, $bcc),
            'brevo'  => $this->sendViaBrevo($recipients, $subject, $html, $text, $replyTo, $bcc),
            'log'    => $this->sendViaLog($recipients, $subject),
            default  => $this->sendViaSmtp($recipients, $subject, $html, $text, $replyTo, $bcc),
        };
    }

    /** Dev-only transport: write email to the PHP error log instead of sending. */
    private function sendViaLog(array $to, string $subject): bool
    {
        error_log(sprintf('[nadgo-mailer:log] To: %s | Subject: %s', implode(', ', $to), $subject));
        return true;
    }

    /** Send through the Resend transactional HTTP API (https://resend.com). */
    private function sendViaResend(array $to, string $subject, string $html, string $text, ?string $replyTo, ?string $bcc = null): bool
    {
        $cfg = $this->cfg['resend'] ?? [];
        $key = (string) ($cfg['api_key'] ?? '');
        if ($key === '' || $key === 'CHANGE_ME_RESEND_API_KEY') {
            $this->lastError = 'Resend api_key not configured.';
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }

        $from = ($this->cfg['from_name'] ?? '') !== ''
            ? sprintf('%s <%s>', $this->cfg['from_name'], $this->cfg['from_email'])
            : (string) $this->cfg['from_email'];

        $payload = [
            'from'    => $from,
            'to'      => array_values($to),
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text !== '' ? $text : strip_tags($html),
        ];
        if ($replyTo) {
            $payload['reply_to'] = $replyTo;
        }
        if ($bcc) {
            $payload['bcc'] = [$bcc];
        }

        $url = $cfg['api_url'] ?? 'https://api.resend.com/emails';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $key,
                'Content-Type: application/json',
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);

        if ($res === false) {
            $this->lastError = 'Resend request failed: ' . $err;
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $this->lastError = 'Resend API error ' . $code . ': ' . $res;
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }
        return true;
    }

    /** Send through the Brevo (Sendinblue) transactional HTTP API. */
    private function sendViaBrevo(array $to, string $subject, string $html, string $text, ?string $replyTo, ?string $bcc = null): bool
    {
        $cfg = $this->cfg['brevo'] ?? [];
        $key = (string) ($cfg['api_key'] ?? '');
        if ($key === '' || $key === 'CHANGE_ME_BREVO_API_KEY') {
            $this->lastError = 'Brevo api_key not configured.';
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }

        $payload = [
            'sender'      => ['email' => $this->cfg['from_email'], 'name' => $this->cfg['from_name']],
            'to'          => array_map(fn($e) => ['email' => $e], $to),
            'subject'     => $subject,
            'htmlContent' => $html,
            'textContent' => $text !== '' ? $text : strip_tags($html),
        ];
        if ($replyTo) {
            $payload['replyTo'] = ['email' => $replyTo];
        }
        if ($bcc) {
            $payload['bcc'] = [['email' => $bcc]];
        }

        $url = $cfg['api_url'] ?? 'https://api.brevo.com/v3/smtp/email';
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'accept: application/json',
                'content-type: application/json',
                'api-key: ' . $key,
            ],
        ]);
        $res  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err  = curl_error($ch);

        if ($res === false) {
            $this->lastError = 'Brevo request failed: ' . $err;
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }
        if ($code < 200 || $code >= 300) {
            $this->lastError = 'Brevo API error ' . $code . ': ' . $res;
            error_log('[nadgo-api] ' . $this->lastError);
            return false;
        }
        return true;
    }

    /** Send through SMTP via PHPMailer. */
    private function sendViaSmtp(array $to, string $subject, string $html, string $text, ?string $replyTo, ?string $bcc = null): bool
    {
        $s = $this->cfg['smtp'] ?? [];
        $mail = new PHPMailer(true);

        $mail->isSMTP();
        $mail->Host       = $s['host'] ?? '';
        $mail->SMTPAuth   = true;
        $mail->Username   = $s['username'] ?? '';
        $mail->Password   = $s['password'] ?? '';
        $mail->Port       = (int) ($s['port'] ?? 587);
        $mail->SMTPSecure = ($s['encryption'] ?? 'tls') === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom($this->cfg['from_email'], $this->cfg['from_name']);
        foreach ($to as $addr) {
            $mail->addAddress($addr);
        }
        if ($replyTo) {
            $mail->addReplyTo($replyTo);
        }
        if ($bcc) {
            $mail->addBCC($bcc);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = $text;

        return $mail->send();
    }

    /** Wrap content in the branded, responsive email shell (header logo + footer). */
    private function template(string $subject, string $content): string
    {
        $name    = $this->e($this->brand['name'] ?? 'Nadgo');
        $color   = $this->brand['color'] ?? '#0F2057';
        $logo    = $this->brand['logo_url'] ?? '';
        $support = $this->e($this->brand['support_email'] ?? ($this->adminRecipients()[0] ?? 'admin@nad-go.com'));
        $site    = $this->brand['website_url'] ?? '';

        // White header so a dark logo stays visible; brand colour is a top accent bar.
        $header = $logo !== ''
            ? '<img src="' . $this->e($logo) . '" alt="' . $name . '" height="40" style="height:40px;max-height:40px;display:block;border:0;">'
            : '<span style="color:' . $color . ';font-size:22px;font-weight:bold;">' . $name . '</span>';

        $displaySite = $site !== '' ? $site : 'https://nad-go.com';
        $footerSite  = ' &middot; <a href="' . $this->e($displaySite) . '" style="color:' . $color . ';text-decoration:none;">' . $this->e($displaySite) . '</a>'
            . ' &middot; <a href="https://wa.me/+447440779864" style="color:' . $color . ';text-decoration:none;">WhatsApp</a>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<style>'
            . 'body{margin:0;padding:0;background:#eef0f4;}'
            . '.nx-card h2{font-size:20px;margin:0 0 12px;color:' . $color . ';}'
            . '.nx-card h3{font-size:13px;margin:22px 0 8px;color:' . $color . ';text-transform:uppercase;letter-spacing:.04em;}'
            . '.nx-card p{margin:0 0 12px;line-height:1.55;}'
            . '.nx-items{width:100%;border-collapse:collapse;margin:6px 0;}'
            . '.nx-items th{font-size:12px;text-align:left;color:#8a93a6;border-bottom:2px solid #eef0f4;padding:8px 6px;}'
            . '.nx-items td{font-size:14px;border-bottom:1px solid #eef0f4;padding:8px 6px;}'
            . '.nx-tot td{padding:4px 6px;font-size:14px;}'
            . '.nx-badge{display:inline-block;padding:3px 12px;border-radius:20px;background:' . $color . ';color:#fff;font-size:12px;font-weight:bold;}'
            . '</style></head><body>'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . $this->e($subject) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef0f4;padding:24px 0;"><tr><td align="center">'
            . '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:92%;background:#ffffff;border-radius:12px;overflow:hidden;font-family:Arial,Helvetica,sans-serif;color:#222;">'
            . '<tr><td style="height:4px;background:' . $color . ';font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="background:#ffffff;padding:22px 28px 16px;border-bottom:1px solid #eef0f4;" align="left">' . $header . '</td></tr>'
            . '<tr><td class="nx-card" style="padding:24px 28px 28px;">' . $content . '</td></tr>'
            . '<tr><td style="padding:18px 28px;background:#fafbfc;border-top:1px solid #eef0f4;color:#9aa3b2;font-size:12px;line-height:1.5;">'
            . 'You are receiving this email about your order with ' . $name . '.<br>'
            . 'Questions? <a href="mailto:' . $support . '" style="color:' . $color . ';text-decoration:none;">' . $support . '</a>' . $footerSite
            . '</td></tr></table></td></tr></table></body></html>';
    }

    // --- view helpers ----------------------------------------------------------

    private function customerBlock(array $o): string
    {
        $name = trim(($o['ship_first_name'] ?? '') . ' ' . ($o['ship_last_name'] ?? ''));
        $addr = array_filter([
            $o['ship_address1'] ?? '', $o['ship_address2'] ?? '',
            trim(($o['ship_city'] ?? '') . ' ' . ($o['ship_postcode'] ?? '')),
            $o['ship_country'] ?? '',
        ], fn($l) => trim((string) $l) !== '');

        return '<h3>Customer</h3><p><strong>' . $this->e($name) . '</strong><br>'
            . $this->e($o['customer_email'] ?? '') . '<br>'
            . $this->e($o['customer_phone'] ?? '') . '</p>'
            . '<h3>Shipping address</h3><p>' . implode('<br>', array_map([$this, 'e'], $addr)) . '</p>';
    }

    private function itemsTable(array $items): string
    {
        $rows = '';
        foreach ($items as $it) {
            $img = !empty($it['image_url'])
                ? '<img src="' . $this->e($it['image_url']) . '" alt="" width="40" height="40" style="object-fit:cover;border-radius:4px;vertical-align:middle"> '
                : '';
            $name = $this->e($it['product_name'])
                . (!empty($it['variant']) ? '<br><small style="color:#777">' . $this->e($it['variant']) . '</small>' : '');
            $rows .= '<tr><td style="vertical-align:middle">' . $img . $name . '</td>'
                . '<td align="right">' . (int) $it['quantity']
                . '</td><td align="right">' . number_format((float) $it['unit_price'], 2)
                . '</td><td align="right">' . number_format((float) $it['line_total'], 2) . '</td></tr>';
        }
        return '<h3>Items</h3><table class="nx-items"><tr><th>Product</th>'
            . '<th align="right">Qty</th><th align="right">Unit</th><th align="right">Total</th></tr>'
            . $rows . '</table>';
    }

    private function totalsBlock(array $o): string
    {
        $cur  = $this->e($o['currency']);
        $line = fn($label, $val) => '<tr><td style="color:#8a93a6">' . $label
            . '</td><td align="right">' . number_format((float) $val, 2) . ' ' . $cur . '</td></tr>';

        return '<table class="nx-tot" align="right" style="margin-top:10px;min-width:240px">'
            . $line('Subtotal', $o['subtotal_amount'] ?? 0)
            . $line('Shipping', $o['shipping_amount'] ?? 0)
            . '<tr><td style="border-top:2px solid #eef0f4"><strong>Total</strong></td>'
            . '<td align="right" style="border-top:2px solid #eef0f4"><strong>'
            . number_format((float) $o['total_amount'], 2) . ' ' . $cur . '</strong></td></tr>'
            . '</table><div style="clear:both"></div>';
    }

    /** CTA button linking the customer to their order page. */
    private function orderLink(array $order, string $label = 'View My Order &rarr;', ?string $btnColor = null): string
    {
        $site  = rtrim($this->brand['website_url'] ?? '', '/');
        $color = $btnColor ?? ($this->brand['color'] ?? '#0F2057');
        $ref   = $order['order_ref'] ?? '';
        if ($site === '' || $ref === '') return '';
        $url = $site . '/order/' . rawurlencode($ref);
        if (!empty($order['tracking_token'])) {
            $url .= '?token=' . rawurlencode($order['tracking_token']);
        }
        return '<p style="text-align:center;margin:24px 0">'
            . '<a href="' . $this->e($url) . '" style="display:inline-block;padding:12px 28px;background:' . $color . ';color:#fff;text-decoration:none;border-radius:6px;font-weight:bold;">' . $label . '</a>'
            . '</p>';
    }

    private function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }
}
