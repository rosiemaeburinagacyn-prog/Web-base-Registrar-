<?php

namespace App\Services;

use App\Models\DocumentRequestItem;
use App\Models\Payment;
use Illuminate\Support\Facades\Storage;

class OfficialReceiptGenerator
{
    public function ensure(Payment $payment, bool $force = false): Payment
    {
        $payment->loadMissing([
            'cashier',
            'verifier',
            'documentRequest.items',
        ]);

        if (! $payment->receipt_number) {
            $payment->receipt_number = $this->receiptNumber($payment);
        }

        if ($payment->official_receipt_path && ! $force && Storage::disk('public')->exists($payment->official_receipt_path)) {
            if ($payment->isDirty('receipt_number')) {
                $payment->save();
            }

            return $payment;
        }

        $path = 'official-receipts/'.$payment->receipt_number.'.pdf';

        Storage::disk('public')->put($path, $this->pdf($payment));

        $payment->official_receipt_path = $path;
        $payment->generated_at = now();
        $payment->metadata = array_merge($payment->metadata ?? [], [
            'official_receipt' => $this->receiptSnapshot($payment),
        ]);
        $payment->save();

        return $payment;
    }

    private function receiptNumber(Payment $payment): string
    {
        return 'OR-'.now()->format('Ymd').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * @return array<string, mixed>
     */
    private function receiptSnapshot(Payment $payment): array
    {
        $documentRequest = $payment->documentRequest;
        $receiptDate = $payment->paid_at ?? $payment->verified_at ?? $payment->created_at;

        return [
            'receipt_number' => $payment->receipt_number,
            'student_name' => $documentRequest?->student_name,
            'student_id' => $documentRequest?->student_id,
            'requested_documents' => ($documentRequest?->itemSummary() ?? collect())
                ->map(fn (DocumentRequestItem $item) => [
                    'document_type' => $item->document_type,
                    'label' => $item->label(),
                    'quantity' => $item->quantity,
                    'unit_price' => (float) $item->unit_price,
                    'subtotal' => (float) $item->subtotal,
                ])
                ->values()
                ->all(),
            'amount_paid' => (float) $payment->amount,
            'date_time' => $receiptDate?->toDateTimeString(),
            'payment_method' => $payment->payment_method,
            'cashier_name' => $payment->cashier?->name ?? $payment->verifier?->name ?? 'Payment Gateway',
            'payment_status' => 'PAID',
        ];
    }

    private function pdf(Payment $payment): string
    {
        $documentRequest = $payment->documentRequest;
        $cashierName = $payment->cashier?->name ?? $payment->verifier?->name ?? 'Payment Gateway';
        $receiptDate = $payment->paid_at ?? $payment->verified_at ?? $payment->created_at;
        $items = $documentRequest?->itemSummary() ?? collect();

        $commands = [];
        $green = [10, 92, 54];
        $greenDark = [7, 63, 38];
        $zinc950 = [9, 9, 11];
        $zinc600 = [82, 82, 91];
        $zinc500 = [113, 113, 122];
        $zinc200 = [228, 228, 231];
        $zinc50 = [250, 250, 250];
        $logoImage = $this->pngImageData(public_path('images/isu-logo.png'));

        $itemBlocks = [];
        $itemsHeight = 0;

        foreach ($items as $item) {
            /** @var DocumentRequestItem $item */
            $titleLines = $this->wrapText($item->document_type.' - '.$item->label(), 260, 11, true);
            $height = max(46, 21 + (count($titleLines) * 14));

            $itemBlocks[] = [
                'item' => $item,
                'title_lines' => $titleLines,
                'height' => $height,
            ];

            $itemsHeight += $height + 8;
        }

        $pageWidth = 612;
        $cardX = 70;
        $cardTop = 730;
        $cardWidth = 472;
        $docBoxTop = $cardTop - 382;
        $docBoxHeight = 54 + $itemsHeight + 52;
        $cardBottom = max(60, $docBoxTop - $docBoxHeight - 26);
        $cardHeight = $cardTop - $cardBottom;

        $this->rect($commands, $cardX + 2, $cardBottom - 2, $cardWidth, $cardHeight, [0, 0, 0], null, 0, 0.08);
        $this->rect($commands, $cardX, $cardBottom, $cardWidth, $cardHeight, [255, 255, 255], $zinc200);

        $logoX = 104;
        $logoY = $cardTop - 62;

        if ($logoImage) {
            $this->image($commands, 'Logo', $logoX - 18, $logoY - 18, 36, 36);
        } else {
            $this->circle($commands, $logoX, $logoY, 18, $green);
            $this->circle($commands, $logoX, $logoY, 13, null, [255, 255, 255], 2);
            $this->text($commands, 'ISU', $logoX - 9, $logoY - 4, 9, true, [255, 255, 255]);
        }

        $this->text($commands, 'Isabela State University - Cauayan City Campus', 135, $cardTop - 45, 12, true, $green);
        $this->text($commands, 'Official Receipt', 135, $cardTop - 71, 24, true, $greenDark);
        $this->text($commands, $payment->receipt_number ?: $payment->reference, 94, $cardTop - 101, 12, false, $zinc600);
        $this->line($commands, 94, $cardTop - 122, 518, $cardTop - 122, $zinc200);

        $detailY = $cardTop - 154;
        $this->receiptDetail($commands, 'Student Name', $documentRequest?->student_name, 94, $detailY, $zinc500, $zinc950);
        $this->receiptDetail($commands, 'Student ID', $documentRequest?->student_id, 335, $detailY, $zinc500, $zinc950);

        $detailY -= 55;
        $this->receiptDetail($commands, 'Date and Time', $receiptDate?->format('F j, Y g:i A'), 94, $detailY, $zinc500, $zinc950);
        $this->receiptDetail($commands, 'Payment Method', $payment->payment_method, 335, $detailY, $zinc500, $zinc950);

        $detailY -= 55;
        $this->receiptDetail($commands, 'Cashier Name', $cashierName, 94, $detailY, $zinc500, $zinc950);
        $this->receiptDetail($commands, 'Payment Status', 'PAID', 335, $detailY, $zinc500, $zinc950);

        $detailY -= 55;
        $this->receiptDetail($commands, 'Registrar Release Status', $documentRequest?->request_status, 94, $detailY, $zinc500, $zinc950);
        $this->receiptDetail($commands, 'Request Reference', $documentRequest?->request_reference, 335, $detailY, $zinc500, $zinc950, 174);

        $docBoxX = 94;
        $docBoxWidth = 424;
        $docBoxBottom = $docBoxTop - $docBoxHeight;
        $this->rect($commands, $docBoxX, $docBoxBottom, $docBoxWidth, $docBoxHeight, $zinc50, $zinc200);
        $this->text($commands, 'Requested Documents', $docBoxX + 20, $docBoxTop - 30, 12, true, $zinc950);

        $itemY = $docBoxTop - 64;

        foreach ($itemBlocks as $block) {
            /** @var DocumentRequestItem $item */
            $item = $block['item'];
            $rowBottom = $itemY - $block['height'] + 8;

            $this->rect($commands, $docBoxX + 20, $rowBottom, $docBoxWidth - 40, $block['height'], [255, 255, 255]);

            $titleY = $itemY - 7;
            foreach ($block['title_lines'] as $index => $line) {
                $this->text($commands, $line, $docBoxX + 40, $titleY - ($index * 14), 11, true, $zinc950);
            }

            $qtyY = $titleY - (count($block['title_lines']) * 14) - 2;
            $this->text(
                $commands,
                'Qty: '.$item->quantity.' x PHP '.number_format((float) $item->unit_price, 2),
                $docBoxX + 40,
                $qtyY,
                10,
                false,
                $zinc600
            );
            $this->textRight(
                $commands,
                'PHP '.number_format((float) $item->subtotal, 2),
                $docBoxX + $docBoxWidth - 20,
                $titleY - 1,
                11,
                true,
                $zinc950
            );

            $itemY -= $block['height'] + 8;
        }

        $this->line($commands, $docBoxX + 20, $docBoxBottom + 50, $docBoxX + $docBoxWidth - 20, $docBoxBottom + 50, $zinc200);
        $this->textRight(
            $commands,
            'Total Amount Paid: PHP '.number_format((float) $payment->amount, 2),
            $docBoxX + $docBoxWidth - 20,
            $docBoxBottom + 24,
            12,
            true,
            $zinc950
        );

        $content = implode("\n", $commands)."\n";

        $pageResources = '<< /Font << /F1 4 0 R /F2 5 0 R >>'
            .($logoImage ? ' /XObject << /Logo 6 0 R >>' : '')
            .' >>';
        $contentObjectNumber = $logoImage
            ? ($logoImage['alpha'] ? 8 : 7)
            : 6;

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources '.$pageResources.' /Contents '.$contentObjectNumber.' 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>',
        ];

        if ($logoImage) {
            $objects[] = $this->imageObject($logoImage, $logoImage['alpha'] ? 7 : null);

            if ($logoImage['alpha']) {
                $objects[] = $this->alphaImageObject($logoImage);
            }
        }

        $objects[] = "<< /Length ".strlen($content)." >>\nstream\n{$content}endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF";

        return $pdf;
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int>|null $fill
     * @param array<int, int>|null $stroke
     */
    private function rect(array &$commands, float $x, float $y, float $width, float $height, ?array $fill = null, ?array $stroke = null, float $lineWidth = 1, float $fillAlpha = 1): void
    {
        $commands[] = 'q';
        $commands[] = $this->rgb($fill ?? $stroke ?? [0, 0, 0], 'rg');
        $commands[] = $this->rgb($stroke ?? $fill ?? [0, 0, 0], 'RG');
        $commands[] = $this->number($lineWidth).' w';

        if ($fillAlpha < 1) {
            $commands[] = '0.88 g';
        }

        $commands[] = $this->number($x).' '.$this->number($y).' '.$this->number($width).' '.$this->number($height).' re';
        $commands[] = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $commands[] = 'Q';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int> $color
     */
    private function line(array &$commands, float $x1, float $y1, float $x2, float $y2, array $color, float $lineWidth = 1): void
    {
        $commands[] = 'q';
        $commands[] = $this->rgb($color, 'RG');
        $commands[] = $this->number($lineWidth).' w';
        $commands[] = $this->number($x1).' '.$this->number($y1).' m';
        $commands[] = $this->number($x2).' '.$this->number($y2).' l';
        $commands[] = 'S';
        $commands[] = 'Q';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int>|null $fill
     * @param array<int, int>|null $stroke
     */
    private function circle(array &$commands, float $cx, float $cy, float $radius, ?array $fill = null, ?array $stroke = null, float $lineWidth = 1): void
    {
        $k = 0.5522847498;
        $c = $radius * $k;

        $commands[] = 'q';
        $commands[] = $this->rgb($fill ?? $stroke ?? [0, 0, 0], 'rg');
        $commands[] = $this->rgb($stroke ?? $fill ?? [0, 0, 0], 'RG');
        $commands[] = $this->number($lineWidth).' w';
        $commands[] = $this->number($cx + $radius).' '.$this->number($cy).' m';
        $commands[] = $this->number($cx + $radius).' '.$this->number($cy + $c).' '.$this->number($cx + $c).' '.$this->number($cy + $radius).' '.$this->number($cx).' '.$this->number($cy + $radius).' c';
        $commands[] = $this->number($cx - $c).' '.$this->number($cy + $radius).' '.$this->number($cx - $radius).' '.$this->number($cy + $c).' '.$this->number($cx - $radius).' '.$this->number($cy).' c';
        $commands[] = $this->number($cx - $radius).' '.$this->number($cy - $c).' '.$this->number($cx - $c).' '.$this->number($cy - $radius).' '.$this->number($cx).' '.$this->number($cy - $radius).' c';
        $commands[] = $this->number($cx + $c).' '.$this->number($cy - $radius).' '.$this->number($cx + $radius).' '.$this->number($cy - $c).' '.$this->number($cx + $radius).' '.$this->number($cy).' c';
        $commands[] = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        $commands[] = 'Q';
    }

    /**
     * @param array<int, string> $commands
     */
    private function image(array &$commands, string $name, float $x, float $y, float $width, float $height): void
    {
        $commands[] = 'q';
        $commands[] = $this->number($width).' 0 0 '.$this->number($height).' '.$this->number($x).' '.$this->number($y).' cm';
        $commands[] = '/'.$name.' Do';
        $commands[] = 'Q';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int> $color
     */
    private function text(array &$commands, ?string $text, float $x, float $y, int $size = 10, bool $bold = false, array $color = [0, 0, 0]): void
    {
        $font = $bold ? 'F2' : 'F1';
        $commands[] = 'BT';
        $commands[] = $this->rgb($color, 'rg');
        $commands[] = "/{$font} {$size} Tf";
        $commands[] = '1 0 0 1 '.$this->number($x).' '.$this->number($y).' Tm';
        $commands[] = '('.$this->escape($text).') Tj';
        $commands[] = 'ET';
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int> $color
     */
    private function textRight(array &$commands, ?string $text, float $rightX, float $y, int $size = 10, bool $bold = false, array $color = [0, 0, 0]): void
    {
        $value = (string) $text;
        $this->text($commands, $value, $rightX - $this->textWidth($value, $size, $bold), $y, $size, $bold, $color);
    }

    /**
     * @param array<int, string> $commands
     * @param array<int, int> $labelColor
     * @param array<int, int> $valueColor
     */
    private function receiptDetail(array &$commands, string $label, ?string $value, float $x, float $y, array $labelColor, array $valueColor, float $valueWidth = 175): void
    {
        $this->text($commands, $label, $x, $y, 11, true, $labelColor);

        $lines = $this->wrapText((string) $value, $valueWidth, 11);
        foreach ($lines as $index => $line) {
            $this->text($commands, $line, $x, $y - 20 - ($index * 13), 11, false, $valueColor);
        }
    }

    /**
     * @param array{width: int, height: int, rgb: string, alpha: string|null} $image
     */
    private function imageObject(array $image, ?int $alphaObjectNumber): string
    {
        $smask = $alphaObjectNumber ? ' /SMask '.$alphaObjectNumber.' 0 R' : '';

        return '<< /Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '.$image['height'].' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode'.$smask.' /Length '.strlen($image['rgb'])." >>\nstream\n".$image['rgb']."\nendstream";
    }

    /**
     * @param array{width: int, height: int, rgb: string, alpha: string|null} $image
     */
    private function alphaImageObject(array $image): string
    {
        return '<< /Type /XObject /Subtype /Image /Width '.$image['width'].' /Height '.$image['height'].' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length '.strlen((string) $image['alpha'])." >>\nstream\n".$image['alpha']."\nendstream";
    }

    /**
     * @return array{width: int, height: int, rgb: string, alpha: string|null}|null
     */
    private function pngImageData(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $data = file_get_contents($path);

        if (! is_string($data) || substr($data, 0, 8) !== "\x89PNG\r\n\x1a\n") {
            return null;
        }

        $offset = 8;
        $width = 0;
        $height = 0;
        $bitDepth = 0;
        $colorType = 0;
        $compression = 0;
        $filter = 0;
        $interlace = 0;
        $idat = '';

        while ($offset + 8 <= strlen($data)) {
            $length = unpack('N', substr($data, $offset, 4))[1];
            $type = substr($data, $offset + 4, 4);
            $chunk = substr($data, $offset + 8, $length);
            $offset += 12 + $length;

            if ($type === 'IHDR') {
                $header = unpack('Nwidth/Nheight/CbitDepth/CcolorType/Ccompression/Cfilter/Cinterlace', $chunk);
                $width = (int) $header['width'];
                $height = (int) $header['height'];
                $bitDepth = (int) $header['bitDepth'];
                $colorType = (int) $header['colorType'];
                $compression = (int) $header['compression'];
                $filter = (int) $header['filter'];
                $interlace = (int) $header['interlace'];
                continue;
            }

            if ($type === 'IDAT') {
                $idat .= $chunk;
                continue;
            }

            if ($type === 'IEND') {
                break;
            }
        }

        if ($width <= 0 || $height <= 0 || $bitDepth !== 8 || $compression !== 0 || $filter !== 0 || $interlace !== 0) {
            return null;
        }

        $bytesPerPixel = match ($colorType) {
            2 => 3,
            6 => 4,
            default => null,
        };

        if (! $bytesPerPixel) {
            return null;
        }

        $raw = @gzuncompress($idat);

        if (! is_string($raw)) {
            return null;
        }

        $rows = $this->pngRows($raw, $width, $height, $bytesPerPixel);

        if ($rows === null) {
            return null;
        }

        $rgb = '';
        $alpha = $colorType === 6 ? '' : null;
        $hasTransparency = false;

        foreach ($rows as $row) {
            if ($colorType === 2) {
                $rgb .= $row;
                continue;
            }

            for ($i = 0; $i < strlen($row); $i += 4) {
                $rgb .= $row[$i].$row[$i + 1].$row[$i + 2];
                $alphaValue = $row[$i + 3];
                $alpha .= $alphaValue;

                if (ord($alphaValue) < 255) {
                    $hasTransparency = true;
                }
            }
        }

        return [
            'width' => $width,
            'height' => $height,
            'rgb' => gzcompress($rgb),
            'alpha' => $hasTransparency ? gzcompress((string) $alpha) : null,
        ];
    }

    /**
     * @return array<int, string>|null
     */
    private function pngRows(string $raw, int $width, int $height, int $bytesPerPixel): ?array
    {
        $stride = $width * $bytesPerPixel;
        $offset = 0;
        $previous = str_repeat("\0", $stride);
        $rows = [];

        for ($row = 0; $row < $height; $row++) {
            if ($offset + 1 + $stride > strlen($raw)) {
                return null;
            }

            $filterType = ord($raw[$offset]);
            $scanline = substr($raw, $offset + 1, $stride);
            $offset += 1 + $stride;
            $reconstructed = '';

            for ($i = 0; $i < $stride; $i++) {
                $value = ord($scanline[$i]);
                $left = $i >= $bytesPerPixel ? ord($reconstructed[$i - $bytesPerPixel]) : 0;
                $up = ord($previous[$i]);
                $upperLeft = $i >= $bytesPerPixel ? ord($previous[$i - $bytesPerPixel]) : 0;

                $decoded = match ($filterType) {
                    0 => $value,
                    1 => $value + $left,
                    2 => $value + $up,
                    3 => $value + intdiv($left + $up, 2),
                    4 => $value + $this->paeth($left, $up, $upperLeft),
                    default => null,
                };

                if ($decoded === null) {
                    return null;
                }

                $reconstructed .= chr($decoded & 0xff);
            }

            $rows[] = $reconstructed;
            $previous = $reconstructed;
        }

        return $rows;
    }

    private function paeth(int $left, int $up, int $upperLeft): int
    {
        $estimate = $left + $up - $upperLeft;
        $leftDistance = abs($estimate - $left);
        $upDistance = abs($estimate - $up);
        $upperLeftDistance = abs($estimate - $upperLeft);

        if ($leftDistance <= $upDistance && $leftDistance <= $upperLeftDistance) {
            return $left;
        }

        return $upDistance <= $upperLeftDistance ? $up : $upperLeft;
    }

    /**
     * @return array<int, string>
     */
    private function wrapText(string $line, float $maxWidth, int $fontSize, bool $bold = false): array
    {
        if ($this->textWidth($line, $fontSize, $bold) <= $maxWidth) {
            return [$line];
        }

        $words = preg_split('/\s+/', $line) ?: [];
        $wrapped = [];
        $current = '';

        foreach ($words as $word) {
            if ($current === '') {
                while ($this->textWidth($word, $fontSize, $bold) > $maxWidth && strlen($word) > 1) {
                    $piece = $this->fittingPrefix($word, $maxWidth, $fontSize, $bold);
                    $wrapped[] = $piece;
                    $word = substr($word, strlen($piece));
                }

                $current = $word;
                continue;
            }

            if ($this->textWidth($current.' '.$word, $fontSize, $bold) <= $maxWidth) {
                $current .= ' '.$word;
                continue;
            }

            $wrapped[] = $current;

            while ($this->textWidth($word, $fontSize, $bold) > $maxWidth && strlen($word) > 1) {
                $piece = $this->fittingPrefix($word, $maxWidth, $fontSize, $bold);
                $wrapped[] = $piece;
                $word = substr($word, strlen($piece));
            }

            $current = $word;
        }

        if ($current !== '') {
            $wrapped[] = $current;
        }

        return $wrapped ?: [''];
    }

    private function fittingPrefix(string $word, float $maxWidth, int $fontSize, bool $bold): string
    {
        $prefix = '';

        for ($i = 0; $i < strlen($word); $i++) {
            $candidate = $prefix.$word[$i];

            if ($prefix !== '' && $this->textWidth($candidate, $fontSize, $bold) > $maxWidth) {
                return $prefix;
            }

            $prefix = $candidate;
        }

        return $prefix;
    }

    private function textWidth(string $text, int $fontSize, bool $bold = false): float
    {
        $width = 0;

        foreach (str_split($this->plain($text)) as $char) {
            $width += match (true) {
                $char === ' ' => 0.28,
                ctype_upper($char) => 0.64,
                ctype_digit($char) => 0.56,
                in_array($char, ['.', ',', ':', ';', '-', '_', '/'], true) => 0.32,
                default => 0.5,
            };
        }

        return $width * $fontSize * ($bold ? 1.04 : 1);
    }

    /**
     * @param array<int, int> $color
     */
    private function rgb(array $color, string $operator): string
    {
        return implode(' ', array_map(fn (int $value) => $this->number($value / 255), $color)).' '.$operator;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    private function escape(?string $value): string
    {
        $value = $this->plain((string) $value);
        $value = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $value);

        return $value;
    }

    private function plain(string $value): string
    {
        return preg_replace('/[^\x20-\x7E]/', '', $value) ?? '';
    }
}
