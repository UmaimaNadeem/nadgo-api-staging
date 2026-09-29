<?php
/**
 * Coupon / promo-code validation and management.
 */
class CouponService
{
    public function __construct(private Database $db) {}

    // =========================================================================
    // Public (customer-facing)
    // =========================================================================

    /**
     * Validate a coupon code for the given cart.
     * Returns an array matching the ValidateCouponResponse shape.
     */
    public function validate(string $code, float $subtotal, array $items = [], string $customerEmail = ''): array
    {
        $code = strtoupper(trim($code));
        if ($code === '') {
            return $this->invalid('Please enter a coupon code.');
        }

        $stmt = $this->db->pdo()->prepare(
            "SELECT * FROM coupons WHERE code = :code AND is_active = 1 LIMIT 1"
        );
        $stmt->execute([':code' => $code]);
        $coupon = $stmt->fetch();

        if (!$coupon) {
            return $this->invalid('This coupon code is invalid or has expired.');
        }

        $now = time();
        if ($coupon['active_from'] && strtotime((string) $coupon['active_from']) > $now) {
            return $this->invalid('This coupon is not yet active.');
        }
        if ($coupon['active_until'] && strtotime((string) $coupon['active_until']) < $now) {
            return $this->invalid('This coupon has expired.');
        }
        if ($coupon['max_uses'] !== null && (int) $coupon['used_count'] >= (int) $coupon['max_uses']) {
            return $this->invalid('This coupon has reached its usage limit.');
        }
        if ($coupon['min_order_amount'] !== null && $subtotal < (float) $coupon['min_order_amount']) {
            $min = number_format((float) $coupon['min_order_amount'], 2);
            return $this->invalid("This coupon requires a minimum order of £{$min}.");
        }
        // Per-customer limit check
        if ($coupon['per_customer_limit'] !== null && $customerEmail !== '') {
            $uStmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM coupon_usages WHERE coupon_code = :code AND customer_email = :email"
            );
            $uStmt->execute([':code' => $code, ':email' => strtolower(trim($customerEmail))]);
            if ((int) $uStmt->fetchColumn() >= (int) $coupon['per_customer_limit']) {
                return $this->invalid('You have already used this coupon.');
            }
        }

        $discount = $this->computeDiscount($coupon, $subtotal);
        if ($discount <= 0) {
            return $this->invalid('This coupon is not applicable to your order.');
        }

        return [
            'ok'             => true,
            'valid'          => true,
            'code'           => $coupon['code'],
            'discount_type'  => $coupon['discount_type'],
            'discount_value' => (float) $coupon['discount_value'],
            'discount_amount'=> round($discount, 2),
            'message'        => $coupon['description'] ?: $this->buildMessage($coupon),
        ];
    }

    /**
     * Compute the discount amount for a given coupon + subtotal.
     * Called during validate() and inside createOrder() for server-side recompute.
     */
    public function computeDiscount(array $coupon, float $subtotal): float
    {
        if ($coupon['discount_type'] === 'percent') {
            return round($subtotal * ((float) $coupon['discount_value'] / 100.0), 2);
        }
        // fixed — cap at subtotal so total never goes negative
        return min((float) $coupon['discount_value'], $subtotal);
    }

    /**
     * Increment used_count for a coupon after a successful order.
     * Silent on failure so it never blocks the order response.
     */
    public function recordUsage(string $code, string $customerEmail = '', string $orderRef = ''): void
    {
        $code = strtoupper(trim($code));
        try {
            $pdo = $this->db->pdo();
            $pdo->prepare(
                "UPDATE coupons SET used_count = used_count + 1 WHERE code = :code"
            )->execute([':code' => $code]);
            if ($customerEmail !== '') {
                $pdo->prepare(
                    "INSERT INTO coupon_usages (coupon_code, customer_email, order_ref)
                     VALUES (:code, :email, :ref)"
                )->execute([
                    ':code'  => $code,
                    ':email' => strtolower(trim($customerEmail)),
                    ':ref'   => $orderRef ?: null,
                ]);
            }
        } catch (\Throwable $e) {
            error_log('[nadgo-api] coupon usage record failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // Admin
    // =========================================================================

    public function adminList(): array
    {
        return $this->db->pdo()
            ->query("SELECT * FROM coupons ORDER BY id DESC")
            ->fetchAll();
    }

    public function adminSave(array $d, ?int $id = null): array
    {
        $code = strtoupper(trim($d['code'] ?? ''));
        if ($code === '') throw new ValidationException('code is required.');

        $type = $d['discount_type'] ?? 'percent';
        if (!in_array($type, ['percent', 'fixed'], true)) {
            throw new ValidationException('discount_type must be percent or fixed.');
        }

        $value = (float) ($d['discount_value'] ?? 0);
        if ($value <= 0) throw new ValidationException('discount_value must be greater than zero.');
        if ($type === 'percent' && $value > 100) {
            throw new ValidationException('Percent discount cannot exceed 100.');
        }

        $minOrder       = ($d['min_order_amount'] ?? '') !== '' && $d['min_order_amount'] !== null
            ? (float) $d['min_order_amount'] : null;
        $maxUses        = ($d['max_uses'] ?? '') !== '' && $d['max_uses'] !== null
            ? (int) $d['max_uses'] : null;
        $perCustomer    = ($d['per_customer_limit'] ?? '') !== '' && $d['per_customer_limit'] !== null
            ? (int) $d['per_customer_limit'] : null;
        $activeFrom  = ($d['active_from']  ?? '') ?: null;
        $activeUntil = ($d['active_until'] ?? '') ?: null;
        $isActive    = isset($d['is_active']) ? (int)(bool) $d['is_active'] : 1;
        $desc        = trim($d['description'] ?? '') ?: null;

        $pdo = $this->db->pdo();

        if ($id) {
            $pdo->prepare(
                "UPDATE coupons SET code=:code, description=:desc, discount_type=:type,
                 discount_value=:val, min_order_amount=:min, max_uses=:max,
                 per_customer_limit=:pcl,
                 active_from=:af, active_until=:au, is_active=:active
                 WHERE id=:id"
            )->execute([
                ':code'   => $code,    ':desc'   => $desc,
                ':type'   => $type,    ':val'    => $value,
                ':min'    => $minOrder, ':max'   => $maxUses,
                ':pcl'    => $perCustomer,
                ':af'     => $activeFrom, ':au'  => $activeUntil,
                ':active' => $isActive, ':id'   => $id,
            ]);
        } else {
            $pdo->prepare(
                "INSERT INTO coupons
                    (code, description, discount_type, discount_value,
                     min_order_amount, max_uses, per_customer_limit,
                     active_from, active_until, is_active)
                 VALUES
                    (:code, :desc, :type, :val, :min, :max, :pcl, :af, :au, :active)"
            )->execute([
                ':code'   => $code,    ':desc'   => $desc,
                ':type'   => $type,    ':val'    => $value,
                ':min'    => $minOrder, ':max'   => $maxUses,
                ':pcl'    => $perCustomer,
                ':af'     => $activeFrom, ':au'  => $activeUntil,
                ':active' => $isActive,
            ]);
        }

        $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = :code LIMIT 1");
        $stmt->execute([':code' => $code]);
        return $stmt->fetch();
    }

    public function adminDelete(int $id): void
    {
        $this->db->pdo()->prepare("DELETE FROM coupons WHERE id = :id")
            ->execute([':id' => $id]);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function invalid(string $message): array
    {
        return [
            'ok'             => true,
            'valid'          => false,
            'code'           => '',
            'discount_amount'=> 0.0,
            'message'        => $message,
        ];
    }

    private function buildMessage(array $coupon): string
    {
        if ($coupon['discount_type'] === 'percent') {
            return (int) $coupon['discount_value'] . '% off your order';
        }
        return '£' . number_format((float) $coupon['discount_value'], 2) . ' off your order';
    }
}
