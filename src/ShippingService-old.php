<?php
require_once __DIR__ . '/Database.php';

class ShippingService
{
    public function __construct(private Database $db) {}

    public function listRates(): array
    {
        $stmt = $this->db->pdo()->query(
            "SELECT * FROM shipping_rates
             ORDER BY (country = 'default') DESC, country ASC, sort_order ASC, rate ASC, id ASC"
        );
        return $stmt->fetchAll();
    }

    /**
     * Return all active shipping methods for a country, falling back to 'default'.
     * Each row contains method_code, method_name, rate and free_threshold.
     */
    public function getRatesForCountry(string $country): array
    {
        $rows = $this->fetchActiveRates($country);
        if (!$rows && $country !== 'default') {
            $rows = $this->fetchActiveRates('default');
        }

        if (!$rows) {
            return [[
                'method_code'    => 'standard',
                'method_name'    => 'Standard shipping',
                'rate'           => '9.99',
                'free_threshold' => '50.00',
                'sort_order'     => 0,
            ]];
        }

        return $rows;
    }

    /**
     * Backwards-compatible single-rate helper. Returns the first active method.
     */
    public function getRateForCountry(string $country): array
    {
        $rows = $this->getRatesForCountry($country);
        return $rows[0];
    }

    /**
     * Resolve one active shipping method by code for a country.
     */
    public function getMethodForCountry(string $country, string $methodCode): ?array
    {
        foreach ($this->getRatesForCountry($country) as $row) {
            if ((string) ($row['method_code'] ?? '') === $methodCode) {
                return $row;
            }
        }
        return null;
    }

    public function saveRate(array $d, ?int $id = null): array
    {
        $country    = trim((string) ($d['country'] ?? ''));
        $methodCode = strtolower(trim((string) ($d['method_code'] ?? 'standard')));
        $methodCode = preg_replace('/[^a-z0-9_-]+/', '_', $methodCode) ?: 'standard';
        $methodName = trim((string) ($d['method_name'] ?? 'Standard shipping'));
        $rate       = round((float) ($d['rate'] ?? 0), 2);
        $thresh     = (isset($d['free_threshold']) && $d['free_threshold'] !== '' && $d['free_threshold'] !== null)
            ? round((float) $d['free_threshold'], 2) : null;
        $isActive   = isset($d['is_active']) ? (int) (bool) $d['is_active'] : 1;
        $sortOrder  = isset($d['sort_order']) ? (int) $d['sort_order'] : 0;

        if ($country === '') throw new ValidationException('country is required.');
        if ($methodName === '') throw new ValidationException('method_name is required.');
        if ($rate < 0) throw new ValidationException('rate must be 0 or greater.');

        if ($id) {
            $stmt = $this->db->pdo()->prepare(
                'UPDATE shipping_rates
                    SET country = :c, method_code = :mc, method_name = :mn,
                        rate = :r, free_threshold = :ft, is_active = :a, sort_order = :so
                  WHERE id = :id'
            );
            $stmt->execute([
                ':c' => $country, ':mc' => $methodCode, ':mn' => $methodName,
                ':r' => $rate, ':ft' => $thresh, ':a' => $isActive, ':so' => $sortOrder, ':id' => $id,
            ]);
        } else {
            $id = (int) $this->db->insert(
                'INSERT INTO shipping_rates
                    (country, method_code, method_name, rate, free_threshold, is_active, sort_order)
                 VALUES (:c, :mc, :mn, :r, :ft, :a, :so)',
                [
                    ':c' => $country, ':mc' => $methodCode, ':mn' => $methodName,
                    ':r' => $rate, ':ft' => $thresh, ':a' => $isActive, ':so' => $sortOrder,
                ]
            );
        }

        $stmt = $this->db->pdo()->prepare('SELECT * FROM shipping_rates WHERE id = :id');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }

    public function deleteRate(int $id): void
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT country FROM shipping_rates WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) throw new NotFoundException('Shipping rate not found.');
        if ($row['country'] === 'default') {
            throw new ValidationException('The default rate cannot be deleted.');
        }
        $del = $this->db->pdo()->prepare('DELETE FROM shipping_rates WHERE id = :id');
        $del->execute([':id' => $id]);
    }

    private function fetchActiveRates(string $country): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT method_code, method_name, rate, free_threshold, sort_order
               FROM shipping_rates
              WHERE country = :c AND is_active = 1
              ORDER BY sort_order ASC, rate ASC, id ASC"
        );
        $stmt->execute([':c' => $country]);
        return $stmt->fetchAll();
    }
}
