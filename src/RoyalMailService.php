<?php
/**
 * Royal Mail Click & Drop API integration.
 * https://api.parcel.royalmail.com/
 *
 * Auth: Bearer <api_key> in Authorization header.
 * No sandbox — all calls hit the live endpoint.
 */
class RoyalMailService
{
    private const BASE = 'https://api.parcel.royalmail.com/api/v1';

    public function __construct(private array $config) {}

    // =========================================================================
    // Public
    // =========================================================================

    /**
     * Create a shipment order in Click & Drop.
     *
     * $opts:
     *   weight_grams     int     (default 500)
     *   package_format   string  (default 'smallParcel')
     *   service_code     string  (e.g. 'TM24', 'TM48', '' = auto-select by account rules)
     *   address_override array   overrides individual address fields
     *
     * Returns:
     *   rm_order_id      int
     *   tracking_number  string|null
     *   label_base64     string|null   (OBA accounts only)
     */
    public function createShipment(array $order, array $opts = []): array
    {
        $this->requireKey();

        $addr = is_array($order['shipping_address'] ?? null)
            ? $order['shipping_address']
            : $order;

        if (!empty($opts['address_override']) && is_array($opts['address_override'])) {
            $addr = array_merge($addr, $opts['address_override']);
        }

        $fullName = trim(
            ($addr['first_name']  ?? $order['ship_first_name'] ?? '') . ' ' .
            ($addr['last_name']   ?? $order['ship_last_name']  ?? '')
        );
        if ($fullName === '') {
            $fullName = $order['customer_name'] ?? '';
        }

        // Support both the nested shipping_address shape (address1/city/…) and the flat
        // DB row shape returned by findByRef() (ship_address1/ship_city/…).
        $line1    = trim($addr['address1']    ?? $addr['address_line1']  ?? $addr['ship_address1']  ?? '');
        $line2    = trim($addr['address2']    ?? $addr['address_line2']  ?? $addr['ship_address2']  ?? '') ?: null;
        $city     = trim($addr['city']        ?? $addr['ship_city']      ?? '');
        $postcode = trim($addr['postcode']    ?? $addr['ship_postcode']  ?? '');
        $country  = $this->toAlpha3(trim($addr['country'] ?? $addr['ship_country'] ?? ''));

        $subtotal = round((float) ($order['subtotal_amount'] ?? $order['total_amount'] ?? 0), 2);
        // $shipping = round((float) ($order['shipping_amount'] ?? 0), 2);
        $shipping = 0;
        $total    = round((float) ($order['total_amount'] ?? $subtotal), 2);

        $recipientAddress = ['addressLine1' => $line1, 'city' => $city, 'countryCode' => $country];
        if ($line2 !== null && $line2 !== '')     $recipientAddress['addressLine2'] = $line2;
        if ($postcode !== '')                      $recipientAddress['postcode']     = $postcode;
        if ($fullName !== '')                      $recipientAddress['fullName']     = $fullName;

        $serviceCode = trim($opts['service_code'] ?? '');
        $postageDetails = ['receiveEmailNotification' => !empty($order['customer_email'])];
        if ($serviceCode !== '') $postageDetails['serviceCode'] = $serviceCode;
        if (!empty($order['customer_email'])) $postageDetails['sendNotificationsTo'] = 'recipient';

        $item = [
            'orderReference'      => $order['order_ref'] ?? '',
            'orderDate'           => (new \DateTime())->format(\DateTime::ATOM),
            'subtotal'            => $subtotal,
            'shippingCostCharged' => $shipping,
            'total'               => $total,
            'currencyCode'        => $order['currency'] ?? 'GBP',
            'recipient'           => [
                'address'      => $recipientAddress,
                'emailAddress' => $order['customer_email'] ?? null,
                'phoneNumber'  => $order['customer_phone']  ?? null,
            ],
            'packages' => [[
                'weightInGrams'           => max(1, (int) ($opts['weight_grams'] ?? 500)),
                'packageFormatIdentifier' => $opts['package_format'] ?? 'smallParcel',
            ]],
            'postageDetails' => $postageDetails,
        ];

        // Remove null values from recipient
        $item['recipient'] = array_filter($item['recipient'], fn($v) => $v !== null && $v !== '');

        $response = $this->request('POST', '/orders', ['items' => [$item]]);

        // Check for per-order errors (HTTP 200 but failedOrders populated)
        if (!empty($response['failedOrders'])) {
            $errs = $response['failedOrders'][0]['errors'] ?? [];
            $msg  = $errs[0]['errorMessage'] ?? 'Royal Mail rejected the shipment.';
            // Include field context if available
            if (!empty($errs[0]['fields'])) {
                $fields = implode(', ', array_column($errs[0]['fields'], 'fieldName'));
                $msg .= ' (fields: ' . $fields . ')';
            }
            throw new \RuntimeException($msg);
        }

        $created = $response['createdOrders'][0] ?? null;
        if (!$created) {
            throw new \RuntimeException('Royal Mail returned an empty response. Check Click & Drop for order status.');
        }

        return [
            'rm_order_id'    => $created['orderIdentifier'] ?? null,
            'tracking_number'=> $created['trackingNumber']  ?? null,
            'label_base64'   => $created['label']           ?? null,
        ];
    }

    /**
     * Cancel a Click & Drop order by its orderIdentifier.
     * Silently succeeds if the order is already gone (404).
     */
    public function cancelOrder(string $rmOrderId): void
    {
        $this->requireKey();
        try {
            $this->request('DELETE', '/orders/' . rawurlencode($rmOrderId));
        } catch (\RuntimeException $e) {
            if (!str_contains($e->getMessage(), '404')) {
                throw $e;
            }
        }
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function requireKey(): void
    {
        if (empty($this->config['api_key'])) {
            throw new \RuntimeException('Royal Mail API key is not configured (royal_mail.api_key in config).');
        }
    }

    private function request(string $method, string $path, array $payload = []): array
    {
        $url = self::BASE . $path;

        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->config['api_key'],
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ];
        if ($method !== 'GET' && !empty($payload)) {
            $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        curl_setopt_array($ch, $opts);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new \RuntimeException('Network error communicating with Royal Mail: ' . $err);
        }

        // DELETE 204/200 with empty body is success
        if (($method === 'DELETE') && ($code === 200 || $code === 204 || $code === 404)) {
            return [];
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Royal Mail returned an unexpected response (HTTP ' . $code . ').');
        }

        if ($code === 404) {
            throw new \RuntimeException('Royal Mail order not found (HTTP 404).');
        }
        if ($code === 401) {
            throw new \RuntimeException('Royal Mail API key is invalid or expired. Check your Click & Drop API credentials.');
        }
        if ($code === 400) {
            $msg = $data['message'] ?? $data['details'] ?? $data['error'] ?? 'Bad request.';
            throw new \RuntimeException('Royal Mail validation error: ' . $msg);
        }
        if ($code === 429) {
            throw new \RuntimeException('Royal Mail rate limit exceeded. Please wait a moment and try again.');
        }
        if ($code >= 500) {
            throw new \RuntimeException('Royal Mail API is currently unavailable (HTTP ' . $code . '). Please try again.');
        }

        return $data;
    }

    /**
     * Convert any country representation to ISO 3166-1 alpha-3.
     * Accepts: country name, alpha-2, or alpha-3.
     * Defaults to GBR if unrecognised.
     */
    private function toAlpha3(string $country): string
    {
        static $map = [
            // alpha-2
            'GB' => 'GBR', 'US' => 'USA', 'CA' => 'CAN', 'AU' => 'AUS', 'NZ' => 'NZL',
            'DE' => 'DEU', 'FR' => 'FRA', 'ES' => 'ESP', 'IT' => 'ITA', 'NL' => 'NLD',
            'BE' => 'BEL', 'PT' => 'PRT', 'AT' => 'AUT', 'CH' => 'CHE', 'SE' => 'SWE',
            'NO' => 'NOR', 'DK' => 'DNK', 'FI' => 'FIN', 'IE' => 'IRL', 'PL' => 'POL',
            'GR' => 'GRC', 'CZ' => 'CZE', 'RO' => 'ROU', 'HU' => 'HUN', 'SK' => 'SVK',
            'HR' => 'HRV', 'BG' => 'BGR', 'SI' => 'SVN', 'LT' => 'LTU', 'LV' => 'LVA',
            'EE' => 'EST', 'AE' => 'ARE', 'SA' => 'SAU', 'QA' => 'QAT', 'KW' => 'KWT',
            'BH' => 'BHR', 'OM' => 'OMN', 'SG' => 'SGP', 'HK' => 'HKG', 'JP' => 'JPN',
            'CN' => 'CHN', 'IN' => 'IND', 'ZA' => 'ZAF', 'BR' => 'BRA', 'MX' => 'MEX',
            // country names
            'United Kingdom'             => 'GBR', 'UK' => 'GBR',
            'United States'              => 'USA', 'United States of America' => 'USA',
            'Canada'                     => 'CAN', 'Australia'      => 'AUS',
            'New Zealand'                => 'NZL', 'Germany'        => 'DEU',
            'France'                     => 'FRA', 'Spain'          => 'ESP',
            'Italy'                      => 'ITA', 'Netherlands'    => 'NLD',
            'Belgium'                    => 'BEL', 'Portugal'       => 'PRT',
            'Austria'                    => 'AUT', 'Switzerland'    => 'CHE',
            'Sweden'                     => 'SWE', 'Norway'         => 'NOR',
            'Denmark'                    => 'DNK', 'Finland'        => 'FIN',
            'Ireland'                    => 'IRL', 'Poland'         => 'POL',
            'Greece'                     => 'GRC', 'Czech Republic' => 'CZE',
            'Czechia'                    => 'CZE', 'Romania'        => 'ROU',
            'Hungary'                    => 'HUN', 'Singapore'      => 'SGP',
            'Hong Kong'                  => 'HKG', 'Japan'          => 'JPN',
            'China'                      => 'CHN', 'India'          => 'IND',
            'South Africa'               => 'ZAF', 'Brazil'         => 'BRA',
            'Mexico'                     => 'MEX', 'United Arab Emirates' => 'ARE',
            'UAE'                        => 'ARE', 'Saudi Arabia'   => 'SAU',
            'Qatar'                      => 'QAT', 'Kuwait'         => 'KWT',
            'Bahrain'                    => 'BHR', 'Oman'           => 'OMN',
        ];

        $key = trim($country);
        if (isset($map[$key])) return $map[$key];
        // Already alpha-3
        if (preg_match('/^[A-Za-z]{3}$/', $key)) return strtoupper($key);
        return 'GBR';
    }
}
