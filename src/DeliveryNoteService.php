<?php

declare(strict_types=1);

/**
 * Generates NadGo warehouse delivery notes as A4 PDF files.
 * Uses the Imagick extension already required by the FedEx 4x6 label workflow.
 */
class DeliveryNoteService
{
    private string $fontRegular = 'Helvetica';
    private string $fontBold = 'Helvetica-Bold';
    private const PAGE_W = 1240; // A4 @ 150 DPI
    private const PAGE_H = 1754;
    private const MARGIN = 70;

    public function generate(array $order, string $trackingNumber, string $directory): string
    {
        if (!extension_loaded('imagick')) {
            throw new RuntimeException('PHP Imagick extension is not available.');
        }

        $ref = trim((string) ($order['order_ref'] ?? ''));
        if ($ref === '') {
            throw new InvalidArgumentException('Order reference is required for the delivery note.');
        }

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create delivery note directory.');
        }

        $safeRef = preg_replace('/[^A-Za-z0-9_-]/', '', $ref) ?: date('Ymd_His');
        $path = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'delivery_note_' . $safeRef . '.pdf';

        $pages = new Imagick();
        $page = $this->newPage();
        $draw = $this->newDraw();
        $y = self::MARGIN;

        // Header
        $this->centeredText($page, 'DELIVERY NOTE', self::PAGE_W / 2, $y + 42, 38, true);
        $y += 72;
        $this->centeredText($page, 'This document confirms goods dispatched. No payment is due on this note.', self::PAGE_W / 2, $y + 24, 20, false);
        $y += 62;

        $date = date('d/m/Y');
        $this->threeFieldRow($page, $draw, $y, [
            ['Date', $date],
            ['Delivery Note No.', $ref],
            ['Client Ref No.', ''],
        ]);
        $y += 92;

        // Customer Details
        $this->sectionTitle($page, $draw, 'Customer Details', $y);
        $y += 46;
        $name = trim((string) ($order['customer_name'] ?? ''));
        $address = $this->formatAddress((array) ($order['shipping_address'] ?? []));
        $customerRows = [
            ['Name', $name],
            ['Address', $address],
            ['Email', (string) ($order['customer_email'] ?? '')],
            ['Phone', (string) ($order['customer_phone'] ?? '')],
        ];
        foreach ($customerRows as [$label, $value]) {
            $height = $label === 'Address' ? 96 : 54;
            $this->labelValueRow($page, $draw, $y, $label, $value, $height);
            $y += $height;
        }
        $y += 28;

        // Delivery Details
        $this->sectionTitle($page, $draw, 'Delivery Details', $y);
        $y += 46;
        $deliveryMethod = trim((string) ($order['shipping_method_name'] ?? ''));
        if ($deliveryMethod === '') {
            $deliveryMethod = trim((string) ($order['shipping_carrier'] ?? 'FedEx'));
        }
        $deliveryRows = [
            ['Delivery Date', ''],
            ['Method of Delivery', $deliveryMethod],
            ['Tracking No(s)', $trackingNumber],
        ];
        foreach ($deliveryRows as [$label, $value]) {
            $this->labelValueRow($page, $draw, $y, $label, $value, 54);
            $y += 54;
        }
        $y += 28;

        // Items Dispatched
        $this->sectionTitle($page, $draw, 'Items Dispatched', $y);
        $y += 46;
        $items = is_array($order['items'] ?? null) ? $order['items'] : [];
        [$page, $draw, $y] = $this->itemsTable($pages, $page, $draw, $y, $items);
        $y += 34;

        // If acknowledgement won't fit, move it to a clean page.
        if ($y + 300 > self::PAGE_H - self::MARGIN) {
            $pages->addImage($page);
            $page->clear();
            $page->destroy();
            $page = $this->newPage();
            $draw = $this->newDraw();
            $y = self::MARGIN;
        }

        $this->sectionTitle($page, $draw, 'Delivery Acknowledgement', $y);
        $y += 46;
        $this->acknowledgementTable($page, $draw, $y);

        $pages->addImage($page);
        $page->clear();
        $page->destroy();

        $pages->setImageFormat('pdf');
        $pages->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
        $pages->setImageResolution(150, 150);

        if (!$pages->writeImages($path, true)) {
            throw new RuntimeException('Unable to save delivery note PDF.');
        }
        $pages->clear();
        $pages->destroy();

        return $path;
    }

    private function newPage(): Imagick
    {
        $page = new Imagick();
        $page->newImage(self::PAGE_W, self::PAGE_H, new ImagickPixel('white'));
        $page->setImageFormat('png');
        $page->setImageUnits(Imagick::RESOLUTION_PIXELSPERINCH);
        $page->setImageResolution(150, 150);
        return $page;
    }

    private function newDraw(): ImagickDraw
    {
        $draw = new ImagickDraw();
        $draw->setFillColor('black');
        $draw->setStrokeColor('black');
        $draw->setStrokeWidth(1.5);
        $draw->setFont($this->fontRegular);
        return $draw;
    }

    private function text(Imagick $page, ImagickDraw $draw, string $text, float $x, float $y, int $size = 20, bool $bold = false): void
    {
        $draw->setFont($bold ? $this->fontBold : $this->fontRegular);
        $draw->setFontSize($size);
        $draw->setFillColor('black');
        $draw->setStrokeColor('transparent');
        $page->annotateImage($draw, $x, $y, 0, $text);
    }

    private function centeredText(Imagick $page, string $value, float $centerX, float $baselineY, int $size = 18, bool $bold = false): void
    {
        if ($value === '') return;
        // Use a fresh draw object: no retained rectangle/vector commands.
        $measure = $this->newDraw();
        $measure->setFont($bold ? $this->fontBold : $this->fontRegular);
        $measure->setFontSize($size);
        $metrics = $page->queryFontMetrics($measure, $value, false);
        $width = (float) ($metrics['textWidth'] ?? 0);
        $this->text($page, $measure, $value, $centerX - ($width / 2), $baselineY, $size, $bold);
        $measure->clear();
    }

    private function rect(Imagick $page, ImagickDraw $draw, float $x1, float $y1, float $x2, float $y2, string $fill = 'white'): void
    {
        // ImagickDraw retains vector primitives after drawImage(). Reusing it
        // redraws previous rectangles over previously annotated text.
        $rectDraw = new ImagickDraw();
        $rectDraw->setStrokeColor('black');
        $rectDraw->setStrokeWidth(1.5);
        $rectDraw->setFillColor($fill);
        $rectDraw->rectangle($x1, $y1, $x2, $y2);
        $page->drawImage($rectDraw);
        $rectDraw->clear();
    }

    private function sectionTitle(Imagick $page, ImagickDraw $draw, string $title, float $y): void
    {
        $this->text($page, $draw, $title, self::MARGIN, $y + 28, 25, true);
    }

    private function threeFieldRow(Imagick $page, ImagickDraw $draw, float $y, array $fields): void
    {
        $usable = self::PAGE_W - (self::MARGIN * 2);
        $w = $usable / 3;
        $headerH = 34;
        $valueH = 42;
        foreach ($fields as $i => [$label, $value]) {
            $x = self::MARGIN + ($i * $w);
            $this->rect($page, $draw, $x, $y, $x + $w, $y + $headerH, '#f2f2f2');
            $this->centeredText($page, (string) $label, $x + $w / 2, $y + 24, 17, true);
            $this->rect($page, $draw, $x, $y + $headerH, $x + $w, $y + $headerH + $valueH);
            $this->centeredText($page, (string) $value, $x + $w / 2, $y + $headerH + 28, 18);
        }
    }

    private function labelValueRow(Imagick $page, ImagickDraw $draw, float $y, string $label, string $value, float $height): void
    {
        $x = self::MARGIN;
        $w = self::PAGE_W - (self::MARGIN * 2);
        $labelW = 270;
        $this->rect($page, $draw, $x, $y, $x + $labelW, $y + $height, '#f2f2f2');
        $this->rect($page, $draw, $x + $labelW, $y, $x + $w, $y + $height);
        $this->text($page, $draw, $label, $x + 12, $y + 34, 18, true);
        $lines = $this->wrap($value, $height > 60 ? 82 : 95);
        $lineY = $y + 31;
        foreach (array_slice($lines, 0, $height > 60 ? 3 : 1) as $line) {
            $this->text($page, $draw, $line, $x + $labelW + 14, $lineY, 18, false);
            $lineY += 26;
        }
    }

    private function itemsTable(Imagick $pages, Imagick $page, ImagickDraw $draw, float $y, array $items): array
    {
        $x = self::MARGIN;
        $usable = self::PAGE_W - (self::MARGIN * 2);
        $cols = [90, 640, 190, 180];
        $headerH = 52;
        $rowH = 66;

        $drawHeader = function () use ($page, $draw, $x, &$y, $cols, $headerH): void {
            $headers = ['Item No.', 'Item Description', 'Unit', 'Total Qty'];
            $cx = $x;
            foreach ($headers as $i => $header) {
                $this->rect($page, $draw, $cx, $y, $cx + $cols[$i], $y + $headerH, '#f2f2f2');
                $this->centeredText($page, $header, $cx + $cols[$i] / 2, $y + 33, 17, true);
                $cx += $cols[$i];
            }
            $y += $headerH;
        };

        $drawHeader();

        if (!$items) {
            $this->rect($page, $draw, $x, $y, $x + $usable, $y + $rowH);
            $this->text($page, $draw, 'No order items found.', $x + 12, $y + 40, 18, false);
            return [$page, $draw, $y + $rowH];
        }

        foreach (array_values($items) as $i => $item) {
            if ($y + $rowH > self::PAGE_H - self::MARGIN - 80) {
                $pages->addImage($page);
                $page->clear();
                $page->destroy();
                $page = $this->newPage();
                $draw = $this->newDraw();
                $y = self::MARGIN;

                // Repeat title + headers on continuation pages.
                $this->sectionTitle($page, $draw, 'Items Dispatched (continued)', $y);
                $y += 46;
                $cx = $x;
                foreach (['Item No.', 'Item Description', 'Unit', 'Total Qty'] as $j => $header) {
                    $this->rect($page, $draw, $cx, $y, $cx + $cols[$j], $y + $headerH, '#f2f2f2');
                    $this->centeredText($page, $header, $cx + $cols[$j] / 2, $y + 33, 17, true);
                    $cx += $cols[$j];
                }
                $y += $headerH;
            }

            $product = trim((string) ($item['product_name'] ?? ''));
            $variant = trim((string) ($item['variant'] ?? ''));
            $purchaseType = trim((string) ($item['purchase_type'] ?? ''));
            $description = $product;
            $extras = array_values(array_filter([$variant, $purchaseType], static fn($v) => $v !== ''));
            if ($extras) $description .= ' - ' . implode(' / ', $extras);
            $unit = 'Pcs';
            $qty = (string) ($item['quantity'] ?? '');

            $values = [(string) ($i + 1), $description, $unit, $qty];
            $cx = $x;
            foreach ($values as $j => $value) {
                $this->rect($page, $draw, $cx, $y, $cx + $cols[$j], $y + $rowH);
                $lines = $this->wrap($value, $j === 1 ? 55 : 16);
                $ly = $y + 28;
                foreach (array_slice($lines, 0, 2) as $line) {
                    if ($j === 1) {
                        $this->text($page, $draw, $line, $cx + 10, $ly, 17, false);
                    } else {
                        $this->centeredText($page, $line, $cx + $cols[$j] / 2, $ly, 17, false);
                    }
                    $ly += 24;
                }
                $cx += $cols[$j];
            }
            $y += $rowH;
        }

        return [$page, $draw, $y];
    }

    private function acknowledgementTable(Imagick $page, ImagickDraw $draw, float $y): void
    {
        $x = self::MARGIN;
        $cols = [230, 370, 190, 310];
        $headers = ['', 'Name', 'Date', 'Signature'];
        $headerH = 48;
        $rowH = 72;
        $cx = $x;
        foreach ($headers as $i => $header) {
            $this->rect($page, $draw, $cx, $y, $cx + $cols[$i], $y + $headerH, '#f2f2f2');
            if ($header !== '') $this->centeredText($page, $header, $cx + $cols[$i] / 2, $y + 31, 17, true);
            $cx += $cols[$i];
        }
        $y += $headerH;

        foreach (['Picked By', 'Checked By', 'Packed By'] as $role) {
            $cx = $x;
            foreach ($cols as $i => $w) {
                $this->rect($page, $draw, $cx, $y, $cx + $w, $y + $rowH, $i === 0 ? '#f2f2f2' : 'white');
                if ($i === 0) $this->text($page, $draw, $role, $cx + 12, $y + 43, 18, true);
                $cx += $w;
            }
            $y += $rowH;
        }
    }

    private function formatAddress(array $address): string
    {
        return implode(', ', array_values(array_filter([
            trim((string) ($address['address1'] ?? '')),
            trim((string) ($address['address2'] ?? '')),
            trim((string) ($address['city'] ?? '')),
            trim((string) ($address['postcode'] ?? '')),
            trim((string) ($address['country'] ?? '')),
        ], static fn($v) => $v !== '')));
    }

    private function wrap(string $text, int $maxChars): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($text === '') return [''];
        return explode("\n", wordwrap($text, $maxChars, "\n", true));
    }
}
