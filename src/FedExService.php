<?php

/**
 * Minimal FedEx REST client.
 *
 * Stage 1 implements OAuth only. The same client will be extended for Rates,
 * Ship and Tracking so credentials/tokens never leave the PHP backend.
 */

class FedExApiException extends RuntimeException
{
    private array $diagnosticData;

    public function __construct(string $message, int $httpStatus, string $responseBody, string $contentType, string $requestPath)
    {
        parent::__construct($message);
        $this->diagnosticData = [
            'fedex_http_status' => $httpStatus,
            'fedex_content_type' => $contentType,
            'fedex_response' => $responseBody,
            'request_path' => $requestPath,
        ];
    }

    public function diagnostics(): array
    {
        return $this->diagnosticData;
    }
}

class FedExService
{
    private array $config;
    private string $baseUrl;

    public function __construct(array $config = [])
    {
        $this->config = $config;
        $env = strtolower((string) ($config['environment'] ?? 'sandbox'));
        $this->baseUrl = $env === 'production'
            ? 'https://apis.fedex.com'
            : 'https://apis-sandbox.fedex.com';
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['enabled'])
            && trim((string) ($this->config['api_key'] ?? '')) !== ''
            && trim((string) ($this->config['secret_key'] ?? '')) !== ''
            && trim((string) ($this->config['account_number'] ?? '')) !== '';
    }

    /** Obtain a server-side OAuth token. Tokens are cached until shortly before expiry. */
    public function getAccessToken(bool $forceRefresh = false): string
    {
        $this->requireConfigured();

        if (!$forceRefresh) {
            $cached = $this->readCachedToken();
            if ($cached !== null) return $cached;
        }

        $ch = curl_init($this->baseUrl . '/oauth/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type'    => 'client_credentials',
                'client_id'     => (string) $this->config['api_key'],
                'client_secret' => (string) $this->config['secret_key'],
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new RuntimeException('Network error communicating with FedEx: ' . $err);
        }

        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new RuntimeException('FedEx returned an unexpected OAuth response (HTTP ' . $code . ').');
        }

        if ($code < 200 || $code >= 300 || empty($data['access_token'])) {
            $message = $data['errors'][0]['message'] ?? $data['error_description'] ?? $data['error'] ?? 'Authentication failed.';
            throw new RuntimeException('FedEx OAuth error (HTTP ' . $code . '): ' . $message);
        }

        $token = (string) $data['access_token'];
        $ttl   = max(60, (int) ($data['expires_in'] ?? 3600));
        $this->writeCachedToken($token, time() + $ttl);
        return $token;
    }

    /** Safe diagnostic for admin testing; never exposes the access token. */
    public function testAuthentication(): array
    {
        $token = $this->getAccessToken(true);
        return [
            'authenticated' => $token !== '',
            'environment'   => strtolower((string) ($this->config['environment'] ?? 'sandbox')),
            'account_number'=> $this->maskedAccountNumber(),
        ];
    }


    /**
     * Request account/list rate quotes from FedEx for one package.
     * Origin is server-configured; destination and package data are supplied per request.
     */
    public function getRates(array $destination, array $package): array
    {
        $this->requireConfigured();

        $origin = $this->config['origin'] ?? [];
        $originCountry = strtoupper(trim((string) ($origin['country_code'] ?? 'GB')));
        $originPostal = $this->normalizePostalCode((string) ($origin['postal_code'] ?? ''));
        if ($originPostal === '') {
            throw new InvalidArgumentException('FedEx origin postal_code is not configured.');
        }

        $destinationCountry = strtoupper(trim((string) ($destination['country_code'] ?? '')));
        $destinationPostal = $this->normalizePostalCode((string) ($destination['postal_code'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/', $destinationCountry)) {
            throw new InvalidArgumentException('A valid two-letter destination country_code is required.');
        }
        if ($destinationPostal === '') {
            throw new InvalidArgumentException('Destination postal_code is required.');
        }

        $weight = (float) ($package['weight'] ?? 0);
        if ($weight <= 0) {
            throw new InvalidArgumentException('Package weight must be greater than zero.');
        }
        $weightUnit = strtoupper(trim((string) ($package['weight_unit'] ?? 'KG')));
        if (!in_array($weightUnit, ['KG', 'LB'], true)) {
            throw new InvalidArgumentException('weight_unit must be KG or LB.');
        }

        $lineItem = [
            'groupPackageCount' => 1,
            'weight' => [
                'units' => $weightUnit,
                'value' => $weight,
            ],
        ];

        $dimensions = $package['dimensions'] ?? null;
        if (is_array($dimensions)) {
            $length = (int) ($dimensions['length'] ?? 0);
            $width  = (int) ($dimensions['width'] ?? 0);
            $height = (int) ($dimensions['height'] ?? 0);
            $units  = strtoupper(trim((string) ($dimensions['units'] ?? 'CM')));
            if ($length > 0 && $width > 0 && $height > 0 && in_array($units, ['CM', 'IN'], true)) {
                $lineItem['dimensions'] = compact('length', 'width', 'height', 'units');
            }
        }

        $payload = [
            'accountNumber' => ['value' => $this->getAccountNumber()],
            'requestedShipment' => [
                'shipper' => [
                    'address' => [
                        'postalCode' => $originPostal,
                        'countryCode' => $originCountry,
                    ],
                ],
                'recipient' => [
                    'address' => [
                        'postalCode' => $destinationPostal,
                        'countryCode' => $destinationCountry,
                        'residential' => (bool) ($destination['residential'] ?? false),
                    ],
                ],
                'pickupType' => (string) ($this->config['pickup_type'] ?? 'DROPOFF_AT_FEDEX_LOCATION'),
                'packagingType' => (string) ($this->config['packaging_type'] ?? 'YOUR_PACKAGING'),
                'rateRequestType' => ['ACCOUNT', 'LIST'],
                'requestedPackageLineItems' => [$lineItem],
            ],
        ];

        $data = $this->apiRequest('/rate/v1/rates/quotes', $payload);
        return [
            'origin' => ['country_code' => $originCountry, 'postal_code' => $originPostal],
            'destination' => ['country_code' => $destinationCountry, 'postal_code' => $destinationPostal],
            'package' => ['weight' => $weight, 'weight_unit' => $weightUnit] + (isset($lineItem['dimensions']) ? ['dimensions' => $lineItem['dimensions']] : []),
            'rates' => $this->normalizeRates($data),
            'transaction_id' => (string) ($data['transactionId'] ?? ''),
            'alerts' => $data['output']['alerts'] ?? [],
        ];
    }

    /**
     * Create a single-package domestic FedEx shipment and return its label.
     * The service type is deliberately supplied per request so checkout/admin can
     * book the exact FedEx service selected for the order.
     */
    public function createShipment(array $recipient, array $package, string $serviceType): array
    {
        $this->requireConfigured();

        $serviceType = strtoupper(trim($serviceType));
        if ($serviceType === '' || !preg_match('/^[A-Z0-9_]+$/', $serviceType)) {
            throw new InvalidArgumentException('A valid FedEx service_type is required.');
        }

        $origin = $this->config['origin'] ?? [];
        $shipper = $this->buildContactAndAddress($origin, 'FedEx origin');
        $recipientParty = $this->buildContactAndAddress($recipient, 'Recipient');

        // NadGo is currently certifying UK domestic shipping only.
        $originCountry = strtoupper((string) ($shipper['address']['countryCode'] ?? ''));
        $destinationCountry = strtoupper((string) ($recipientParty['address']['countryCode'] ?? ''));
        if ($originCountry !== 'GB' || $destinationCountry !== 'GB') {
            throw new InvalidArgumentException('This FedEx shipment test currently supports UK domestic shipments only (GB to GB).');
        }

        $weight = (float) ($package['weight'] ?? 0);
        if ($weight <= 0) {
            throw new InvalidArgumentException('Package weight must be greater than zero.');
        }
        $weightUnit = strtoupper(trim((string) ($package['weight_unit'] ?? 'KG')));
        if (!in_array($weightUnit, ['KG', 'LB'], true)) {
            throw new InvalidArgumentException('weight_unit must be KG or LB.');
        }

        $packageLine = [
            'weight' => [
                'units' => $weightUnit,
                'value' => $weight,
            ],
        ];

        $dimensions = $package['dimensions'] ?? null;
        if (is_array($dimensions)) {
            $length = (int) ($dimensions['length'] ?? 0);
            $width  = (int) ($dimensions['width'] ?? 0);
            $height = (int) ($dimensions['height'] ?? 0);
            $units  = strtoupper(trim((string) ($dimensions['units'] ?? 'CM')));
            if ($length > 0 && $width > 0 && $height > 0 && in_array($units, ['CM', 'IN'], true)) {
                $packageLine['dimensions'] = compact('length', 'width', 'height', 'units');
            }
        }

        $payload = [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => ['value' => $this->getAccountNumber()],
            'requestedShipment' => [
                'shipDatestamp' => date('Y-m-d'),
                'shipper' => $shipper,
                'recipients' => [$recipientParty],
                'pickupType' => (string) ($this->config['pickup_type'] ?? 'DROPOFF_AT_FEDEX_LOCATION'),
                'serviceType' => $serviceType,
                'packagingType' => (string) ($this->config['packaging_type'] ?? 'YOUR_PACKAGING'),
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                ],
                'labelSpecification' => [
                    'imageType' => (string) ($this->config['label_image_type'] ?? 'PDF'),
                    'labelStockType' => (string) ($this->config['label_stock_type'] ?? 'PAPER_4X6'),
                    'labelFormatType' => 'COMMON2D',
                ],
                'requestedPackageLineItems' => [$packageLine],
            ],
        ];

        $data = $this->apiRequest('/ship/v1/shipments', $payload);
        $detail = $data['output']['transactionShipments'][0] ?? [];
        if (!is_array($detail)) $detail = [];

        $completed = $detail['completedShipmentDetail'] ?? [];
        if (!is_array($completed)) $completed = [];
        $packageDetail = $completed['completedPackageDetails'][0] ?? [];
        if (!is_array($packageDetail)) $packageDetail = [];

        $trackingNumber = '';
        $trackingIds = $packageDetail['trackingIds'] ?? [];
        if (is_array($trackingIds) && isset($trackingIds[0]) && is_array($trackingIds[0])) {
            $trackingNumber = (string) ($trackingIds[0]['trackingNumber'] ?? '');
        }
        if ($trackingNumber === '') {
            $master = $completed['masterTrackingId'] ?? [];
            if (is_array($master)) $trackingNumber = (string) ($master['trackingNumber'] ?? '');
        }

        $labelBase64 = '';

        // FedEx Ship API returns the shipping label under:
        // transactionShipments[0] -> pieceResponses[0] -> packageDocuments[]
        $pieceResponses = $detail['pieceResponses'] ?? [];

        if (is_array($pieceResponses) && isset($pieceResponses[0]) && is_array($pieceResponses[0])) {
            $piece = $pieceResponses[0];
            $docs = $piece['packageDocuments'] ?? [];

            if (is_array($docs)) {
                foreach ($docs as $doc) {
                    if (!is_array($doc)) {
                        continue;
                    }

                    // Prefer the actual shipping LABEL document.
                    if (strtoupper((string) ($doc['contentType'] ?? '')) === 'LABEL') {
                        $labelBase64 = (string) ($doc['encodedLabel'] ?? $doc['contents'] ?? '');

                        if ($labelBase64 !== '') {
                            break;
                        }
                    }
                }
            }
        }

        return [
            'transaction_id' => (string) ($data['transactionId'] ?? ''),
            'service_type' => $serviceType,
            'tracking_number' => $trackingNumber,
            'label_image_type' => (string) ($this->config['label_image_type'] ?? 'PDF'),
            'label_base64' => $labelBase64,
            'alerts' => $data['output']['alerts'] ?? [],
        ];
    }

    /**
     * Cancel a FedEx shipment by tracking number.
     */
    public function cancelShipment(string $trackingNumber): array
    {
        $this->requireConfigured();

        $trackingNumber = trim($trackingNumber);

        if ($trackingNumber === '' || !preg_match('/^[A-Za-z0-9]+$/', $trackingNumber)) {
            throw new InvalidArgumentException('A valid FedEx tracking number is required.');
        }

        $payload = [
            'accountNumber' => [
                'value' => $this->getAccountNumber(),
            ],
            'trackingNumber' => $trackingNumber,
            'deletionControl' => 'DELETE_ALL_PACKAGES',
        ];

        $data = $this->apiRequest('/ship/v1/shipments/cancel', $payload, 'PUT');

        return [
            'transaction_id' => (string) ($data['transactionId'] ?? ''),
            'tracking_number' => $trackingNumber,
            'cancelled' => (bool) ($data['output']['cancelledShipment'] ?? false),
            'message' => (string) ($data['output']['message'] ?? ''),
            'alerts' => $data['output']['alerts'] ?? [],
        ];
    }

    /** Save a returned Base64 label to disk and return the saved absolute path. */
    public function saveLabel(string $base64, string $directory, string $trackingNumber = ''): string
    {
        if ($base64 === '') throw new RuntimeException('FedEx did not return label data.');
        $binary = base64_decode($base64, true);
        if ($binary === false || $binary === '') throw new RuntimeException('FedEx returned invalid label data.');

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create FedEx label directory.');
        }
        $safeTracking = preg_replace('/[^A-Za-z0-9_-]/', '', $trackingNumber) ?: date('Ymd_His');
        $ext = strtolower((string) ($this->config['label_image_type'] ?? 'PDF')) === 'png' ? 'png' : 'pdf';
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'fedex_' . $safeTracking . '.' . $ext;
        if (@file_put_contents($path, $binary, LOCK_EX) === false) {
            throw new RuntimeException('Unable to save FedEx label file.');
        }

        // FedEx can return PAPER_4X6 content on a larger PDF canvas.
        // Normalize PDF labels to a true 4 x 6 inch page for the thermal printer.
        if ($ext === 'pdf') {
            try {
                $this->normalizePdfLabelTo4x6($path);
            } catch (\Throwable $e) {
                @unlink($path);
                throw new RuntimeException('Unable to convert FedEx label to 4x6 PDF: ' . $e->getMessage(), 0, $e);
            }
        }

        return $path;
    }

    /** Normalize a FedEx PDF label to an exact 4 x 6 inch PDF at 300 DPI. */
    private function normalizePdfLabelTo4x6(string $path): void
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('PHP Imagick extension is not available.');
        }

        $source = new Imagick();
        $source->setResolution(300, 300);
        $source->readImage($path . '[0]');
        $source->setImageBackgroundColor('white');
        $source = $source->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
        $source->setImageFormat('png');

        // Trim the oversized white canvas while tolerating near-white antialiasing.
        $source->trimImage(0.04 * Imagick::getQuantum());
        $source->borderImage(new ImagickPixel('white'), 20, 20);

        // Exact 4 x 6 inches at 300 DPI.
        $pageWidth = 1200;
        $pageHeight = 1800;
        $margin = 18; // 0.06 inch.
        $source->thumbnailImage(
            $pageWidth - ($margin * 2),
            $pageHeight - ($margin * 2),
            true,
            true
        );

        $canvas = new Imagick();
        $canvas->newImage($pageWidth, $pageHeight, new ImagickPixel('white'));
        $canvas->setImageFormat('pdf');
        $canvas->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
        $canvas->setImageResolution(300, 300);

        $x = (int) floor(($pageWidth - $source->getImageWidth()) / 2);
        $y = (int) floor(($pageHeight - $source->getImageHeight()) / 2);
        $canvas->compositeImage($source, Imagick::COMPOSITE_OVER, $x, $y);

        if (!$canvas->writeImage($path)) {
            throw new RuntimeException('Imagick could not write the converted label.');
        }

        $source->clear();
        $source->destroy();
        $canvas->clear();
        $canvas->destroy();
    }

    private function buildContactAndAddress(array $party, string $label): array
    {
        $name = trim((string) ($party['contact_name'] ?? $party['name'] ?? ''));
        $company = trim((string) ($party['company_name'] ?? $party['company'] ?? ''));
        $phone = trim((string) ($party['phone'] ?? ''));
        $street = $party['street_lines'] ?? $party['street'] ?? [];
        if (is_string($street)) $street = [$street];
        if (!is_array($street)) $street = [];
        $street = array_values(array_filter(array_map(static fn($v): string => trim((string) $v), $street), static fn($v): bool => $v !== ''));
        $city = trim((string) ($party['city'] ?? ''));
        $postal = $this->normalizePostalCode((string) ($party['postal_code'] ?? ''));
        $country = strtoupper(trim((string) ($party['country_code'] ?? 'GB')));

        if ($name === '') throw new InvalidArgumentException($label . ' contact_name is required.');
        if ($phone === '') throw new InvalidArgumentException($label . ' phone is required.');
        if (!$street) throw new InvalidArgumentException($label . ' street_lines is required.');
        if ($city === '') throw new InvalidArgumentException($label . ' city is required.');
        if ($postal === '') throw new InvalidArgumentException($label . ' postal_code is required.');
        if (!preg_match('/^[A-Z]{2}$/', $country)) throw new InvalidArgumentException($label . ' country_code must be a two-letter code.');

        $contact = [
            'personName' => $name,
            'phoneNumber' => $phone,
        ];
        if ($company !== '') $contact['companyName'] = $company;

        return [
            'contact' => $contact,
            'address' => [
                'streetLines' => $street,
                'city' => $city,
                'postalCode' => $postal,
                'countryCode' => $country,
            ],
        ];
    }

    private function apiRequest(string $path, array $payload, string $method = 'POST'): array
    {
        $token = $this->getAccessToken();
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode FedEx request.');
        }

        $method = strtoupper(trim($method));
        if (!in_array($method, ['POST', 'PUT'], true)) {
            throw new InvalidArgumentException('Unsupported FedEx HTTP method.');
        }

        $ch = curl_init($this->baseUrl . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
                'Accept: application/json',
                'X-locale: en_GB',
            ],
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err !== '') {
            throw new RuntimeException('Network error communicating with FedEx: ' . $err);
        }

        $rawText = (string) $raw;
        $data = json_decode($rawText, true);

        // For the admin-only diagnostic endpoint, preserve the FedEx HTTP status and
        // response body. Never include our Authorization header, token, API key or secret.
        if ($code < 200 || $code >= 300) {
            $messages = [];
            if (is_array($data)) {
                foreach (($data['errors'] ?? []) as $error) {
                    if (is_array($error) && !empty($error['message'])) $messages[] = (string) $error['message'];
                }
            }
            $message = $messages ? implode(' | ', $messages) : 'FedEx request failed.';
            throw new FedExApiException(
                'FedEx API error (HTTP ' . $code . '): ' . $message,
                $code,
                $rawText,
                $contentType,
                $path
            );
        }

        if (!is_array($data)) {
            throw new FedExApiException(
                'FedEx returned an unexpected API response (HTTP ' . $code . ').',
                $code,
                $rawText,
                $contentType,
                $path
            );
        }
        return $data;
    }

    private function normalizeRates(array $data): array
    {
        $rates = [];
        foreach (($data['output']['rateReplyDetails'] ?? []) as $reply) {
            if (!is_array($reply)) continue;
            $rated = $reply['ratedShipmentDetails'] ?? [];
            if (!is_array($rated) || !$rated) continue;

            // Prefer the negotiated ACCOUNT quote; otherwise use the first quote returned.
            $selected = null;
            foreach ($rated as $candidate) {
                if (is_array($candidate) && strtoupper((string) ($candidate['rateType'] ?? '')) === 'ACCOUNT') {
                    $selected = $candidate;
                    break;
                }
            }
            if ($selected === null) $selected = $rated[0] ?? null;
            if (!is_array($selected)) continue;

            $charge = $selected['totalNetCharge'] ?? null;
            $amount = is_array($charge) ? ($charge['amount'] ?? null) : $charge;
            $currency = is_array($charge) ? ($charge['currency'] ?? null) : null;
            if ($amount === null && isset($selected['shipmentRateDetail']['totalNetCharge'])) {
                $nested = $selected['shipmentRateDetail']['totalNetCharge'];
                $amount = is_array($nested) ? ($nested['amount'] ?? null) : $nested;
                $currency = is_array($nested) ? ($nested['currency'] ?? $currency) : $currency;
            }
            if ($amount === null || !is_numeric($amount)) continue;

            $deliveryDate = $reply['operationalDetail']['deliveryDate']
                ?? $reply['commit']['dateDetail']['dayFormat']
                ?? $reply['commit']['dateDetail']['date']
                ?? null;
            $transitTime = $reply['operationalDetail']['transitTime']
                ?? $reply['commit']['transitDays']['description']
                ?? null;

            $rates[] = [
                'service_type' => (string) ($reply['serviceType'] ?? ''),
                'service_name' => (string) ($reply['serviceName'] ?? $reply['serviceType'] ?? 'FedEx'),
                'packaging_type' => (string) ($reply['packagingType'] ?? ''),
                'rate_type' => (string) ($selected['rateType'] ?? ''),
                'amount' => round((float) $amount, 2),
                'currency'       => (string) ($currency ?: 'GBP'),
                'delivery_date' => $deliveryDate,
                'transit_time' => $transitTime,
            ];
        }

        usort($rates, static fn(array $a, array $b): int => $a['amount'] <=> $b['amount']);
        return $rates;
    }

    private function normalizePostalCode(string $postalCode): string
    {
        return strtoupper(trim(preg_replace('/\\s+/', ' ', $postalCode) ?? ''));
    }

    public function getAccountNumber(): string
    {
        $this->requireConfigured();
        return trim((string) $this->config['account_number']);
    }

    private function requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('FedEx is not configured. Enable fedex and set api_key, secret_key and account_number in config.php.');
        }
    }

    private function cachePath(): string
    {
        $key = hash('sha256', (string) ($this->config['api_key'] ?? 'unset'));
        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nadgo_fedex_' . $key . '.json';
    }

    private function readCachedToken(): ?string
    {
        $path = $this->cachePath();
        if (!is_file($path)) return null;
        $data = json_decode((string) @file_get_contents($path), true);
        if (!is_array($data) || empty($data['access_token']) || empty($data['expires_at'])) return null;
        // Refresh five minutes early so a token cannot expire mid-transaction.
        if ((int) $data['expires_at'] <= time() + 300) return null;
        return (string) $data['access_token'];
    }

    private function writeCachedToken(string $token, int $expiresAt): void
    {
        $path = $this->cachePath();
        @file_put_contents($path, json_encode([
            'access_token' => $token,
            'expires_at'   => $expiresAt,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($path, 0600);
    }

    private function maskedAccountNumber(): string
    {
        $account = trim((string) ($this->config['account_number'] ?? ''));
        if ($account === '') return '';
        if (strlen($account) <= 4) return str_repeat('*', strlen($account));
        return str_repeat('*', strlen($account) - 4) . substr($account, -4);
    }
}
