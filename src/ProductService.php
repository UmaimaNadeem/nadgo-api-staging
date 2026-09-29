<?php
/**
 * Product catalogue, pricing, and inventory management.
 */
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Mailer.php';

class ProductService
{
    public function __construct(private Database $db, private ?Mailer $mailer = null) {}

    // =========================================================================
    // PUBLIC — storefront
    // =========================================================================

    /**
     * Return one active product with its active variants.
     * effective_price = sale_price ?? price
     */
    public function getProductById(int $id): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, slug, name, category, image_url, currency
               FROM products
              WHERE id = :id AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $product = $stmt->fetch();
        if (!$product) throw new NotFoundException("Product not found.");

        $product['variants'] = $this->fetchVariants($id, true);
        return $product;
    }

    /**
     * Fetch one active variant by SKU (used during order validation).
     */
    public function getVariantBySku(string $sku): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT *, COALESCE(sale_price, price) AS effective_price
               FROM product_variants
              WHERE sku = :sku AND is_active = 1
              LIMIT 1'
        );
        $stmt->execute([':sku' => $sku]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Validate item prices and stock against the DB.
     * Items without a SKU or with unknown SKU pass through unchanged.
     */
    public function validateItems(array $items): void
    {
        foreach ($items as $item) {
            $sku = trim($item['sku'] ?? '');
            if ($sku === '') continue;

            $variant = $this->getVariantBySku($sku);
            if (!$variant) continue;

            $submitted = round((float)($item['unit_price'] ?? $item['price'] ?? 0), 2);
            $expected  = round((float)$variant['effective_price'], 2);

            if (abs($submitted - $expected) > 0.01) {
                throw new ValidationException(
                    "Price mismatch for SKU {$sku}. Expected £{$expected}."
                );
            }

            $qty   = max(1, (int)($item['quantity'] ?? 1));
            $stock = $variant['stock_quantity'] !== null ? (int)$variant['stock_quantity'] : null;

            if ($stock !== null && $stock < $qty) {
                $name = $variant['label'] ?? $item['product_name'] ?? $sku;
                throw new ValidationException(
                    $stock === 0
                        ? "{$name} is currently out of stock."
                        : "Only {$stock} unit(s) of {$name} available."
                );
            }
        }
    }

    /**
     * Decrement tracked stock for each item. Must be called inside an open transaction.
     */
    public function decrementStock(array $items): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE product_variants
                SET stock_quantity = GREATEST(0, stock_quantity - :qty)
              WHERE sku = :sku AND stock_quantity IS NOT NULL AND is_active = 1'
        );
        foreach ($items as $item) {
            $sku = trim($item['sku'] ?? '');
            if ($sku === '') continue;
            $stmt->execute([':qty' => max(1, (int)($item['quantity'] ?? 1)), ':sku' => $sku]);
        }
    }

    // =========================================================================
    // "Notify me" interest (logged-in customers only)
    // =========================================================================

    /** Create or flip a customer's notify-me request for a coming-soon product. */
    public function setNotifyRequest(int $productId, string $email, bool $active): array
    {
        $email = strtolower(trim($email));
        if ($productId <= 0) throw new ValidationException('product_id is required.');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ValidationException('A valid email is required.');

        $stmt = $this->db->pdo()->prepare(
            'SELECT id, is_active FROM product_notify_requests WHERE product_id = :pid AND email = :email LIMIT 1'
        );
        $stmt->execute([':pid' => $productId, ':email' => $email]);
        $existing = $stmt->fetch();
        $wasActive = $existing ? (bool) $existing['is_active'] : false;

        if ($existing) {
            $upd = $this->db->pdo()->prepare(
                'UPDATE product_notify_requests SET is_active = :active WHERE id = :id'
            );
            $upd->execute([':active' => (int) $active, ':id' => $existing['id']]);
        } else {
            $this->db->insert(
                'INSERT INTO product_notify_requests (product_id, email, is_active)
                 VALUES (:pid, :email, :active)',
                [':pid' => $productId, ':email' => $email, ':active' => (int) $active]
            );
        }

        if ($active && !$wasActive) {
            $this->sendNotifyMeEmails($productId, $email);
        }

        return ['product_id' => $productId, 'email' => $email, 'is_active' => $active];
    }

    /** Newly-activated notify-me request -> confirmation to the customer + heads-up to admins. */
    private function sendNotifyMeEmails(int $productId, string $email): void
    {
        if (!$this->mailer) return;

        $stmt = $this->db->pdo()->prepare('SELECT name FROM products WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productId]);
        $productName = (string) ($stmt->fetchColumn() ?: 'this product');

        $stmt = $this->db->pdo()->prepare('SELECT first_name, last_name FROM users WHERE email = :e LIMIT 1');
        $stmt->execute([':e' => $email]);
        $user = $stmt->fetch() ?: [];

        $data = [
            'first_name'       => $user['first_name'] ?? '',
            'last_name'        => $user['last_name'] ?? '',
            'email'            => $email,
            'product_interest' => $productName,
        ];

        try {
            $this->mailer->sendContactInquiry($data);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] notify-me admin email failed: ' . $e->getMessage());
        }
        try {
            $this->mailer->sendContactConfirmation($data);
        } catch (\Throwable $e) {
            error_log('[nadgo-api] notify-me confirmation email failed: ' . $e->getMessage());
        }
    }

    /** Whether this customer currently has an active notify-me request for a product. */
    public function getNotifyStatus(int $productId, string $email): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT is_active FROM product_notify_requests WHERE product_id = :pid AND email = :email LIMIT 1'
        );
        $stmt->execute([':pid' => $productId, ':email' => strtolower(trim($email))]);
        $row = $stmt->fetch();
        return $row ? (bool) $row['is_active'] : false;
    }

    /** Admin: every notify-me request, most recent first, with the product name joined in. */
    public function adminListNotifyRequests(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT n.id, n.product_id, p.name AS product_name, n.email, n.is_active, n.created_at
               FROM product_notify_requests n
               LEFT JOIN products p ON p.id = n.product_id
              ORDER BY n.created_at DESC'
        );
        return $stmt->fetchAll();
    }

    // =========================================================================
    // ADMIN
    // =========================================================================

    public function adminListProducts(): array
    {
        $stmt = $this->db->pdo()->query(
            'SELECT id, slug, name, category, image_url, currency, is_active, created_at
               FROM products
              ORDER BY id ASC'
        );
        $products = $stmt->fetchAll();
        foreach ($products as &$p) {
            $p['variants'] = $this->fetchVariants((int)$p['id'], false);
        }
        return $products;
    }

    /** Create or update a product row. Pass $id to update, null to create. */
    public function adminSaveProduct(array $d, ?int $id = null): array
    {
        $slug     = trim($d['slug']     ?? '');
        $name     = trim($d['name']     ?? '');
        $category = trim($d['category'] ?? '');
        if ($slug === '') throw new ValidationException('slug is required.');
        if ($name === '') throw new ValidationException('name is required.');

        $isActive = isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1;
        $imageUrl = ($d['image_url'] ?? '') ?: null;
        $currency = strtoupper(trim($d['currency'] ?? 'GBP'));

        if ($id) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE products
                    SET slug = :slug, name = :name, category = :category,
                        image_url = :img, currency = :cur, is_active = :active
                  WHERE id = :id'
            );
            $stmt->execute([
                ':slug'   => $slug, ':name' => $name, ':category' => $category,
                ':img'    => $imageUrl, ':cur' => $currency,
                ':active' => $isActive, ':id' => $id,
            ]);
        } else {
            $id = (int)$this->db->insert(
                'INSERT INTO products (slug, name, category, image_url, currency, is_active)
                 VALUES (:slug, :name, :category, :img, :cur, :active)',
                [
                    ':slug'   => $slug, ':name' => $name, ':category' => $category,
                    ':img'    => $imageUrl, ':cur' => $currency, ':active' => $isActive,
                ]
            );
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM products WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /** Create or update a product_variants row. */
    public function adminSaveVariant(array $d, ?int $id = null): array
    {
        $sku       = trim($d['sku']        ?? '');
        $productId = (int)($d['product_id'] ?? 0);
        $price     = round((float)($d['price'] ?? 0), 2);

        if ($sku       === '') throw new ValidationException('sku is required.');
        if ($productId === 0)  throw new ValidationException('product_id is required.');
        if ($price     <= 0)   throw new ValidationException('price must be greater than 0.');

        $salePrice = (isset($d['sale_price']) && $d['sale_price'] !== '' && $d['sale_price'] !== null)
            ? round((float)$d['sale_price'], 2) : null;

        $stock = (isset($d['stock_quantity']) && $d['stock_quantity'] !== '')
            ? max(0, (int)$d['stock_quantity']) : null;

        $isActive     = isset($d['is_active']) ? (int)(bool)$d['is_active'] : 1;
        $dosage       = ($d['dosage']        ?? '') ?: null;
        $purchaseType = ($d['purchase_type'] ?? '') ?: null;
        $label        = ($d['label']         ?? '') ?: null;

        if ($id) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE product_variants
                    SET product_id = :pid, sku = :sku, dosage = :dosage,
                        purchase_type = :pt, label = :label, price = :price,
                        sale_price = :sale, stock_quantity = :stock, is_active = :active
                  WHERE id = :id'
            );
            $stmt->execute([
                ':pid' => $productId, ':sku' => $sku, ':dosage' => $dosage,
                ':pt'  => $purchaseType, ':label' => $label, ':price' => $price,
                ':sale'=> $salePrice, ':stock' => $stock, ':active' => $isActive, ':id' => $id,
            ]);
        } else {
            $id = (int)$this->db->insert(
                'INSERT INTO product_variants
                    (product_id, sku, dosage, purchase_type, label, price, sale_price, stock_quantity, is_active)
                 VALUES (:pid, :sku, :dosage, :pt, :label, :price, :sale, :stock, :active)',
                [
                    ':pid' => $productId, ':sku' => $sku, ':dosage' => $dosage,
                    ':pt'  => $purchaseType, ':label' => $label, ':price' => $price,
                    ':sale'=> $salePrice, ':stock' => $stock, ':active' => $isActive,
                ]
            );
        }

        $stmt = $this->db->pdo()->prepare(
            'SELECT *, COALESCE(sale_price, price) AS effective_price
               FROM product_variants WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    /** Add or subtract from stock_quantity (clamps to >= 0). */
    public function adminAdjustStock(int $variantId, int $delta): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, stock_quantity FROM product_variants WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $variantId]);
        $variant = $stmt->fetch();
        if (!$variant) throw new NotFoundException("Variant not found.");

        $newQty = $variant['stock_quantity'] !== null
            ? max(0, (int)$variant['stock_quantity'] + $delta)
            : max(0, $delta);

        $upd = $this->db->pdo()->prepare(
            'UPDATE product_variants SET stock_quantity = :qty WHERE id = :id'
        );
        $upd->execute([':qty' => $newQty, ':id' => $variantId]);

        $stmt = $this->db->pdo()->prepare(
            'SELECT *, COALESCE(sale_price, price) AS effective_price
               FROM product_variants WHERE id = :id'
        );
        $stmt->execute([':id' => $variantId]);
        return $stmt->fetch();
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fetchVariants(int $productId, bool $activeOnly): array
    {
        $sql = 'SELECT id, product_id, sku, dosage, purchase_type, label, price, sale_price,
                       COALESCE(sale_price, price) AS effective_price,
                       stock_quantity, is_active
                  FROM product_variants
                 WHERE product_id = :pid'
             . ($activeOnly ? ' AND is_active = 1' : '')
             . ' ORDER BY id ASC';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([':pid' => $productId]);
        return $stmt->fetchAll();
    }
}
