<?php

namespace App\Services;

use App\Models\TradeSignal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class CoinDcxScreenshotParserService
{
    private const OCR_TIMEOUT_SECONDS = 20;

    /**
     * Parse a CoinDCX Expert Pick screenshot into the shared parser payload contract.
     *
     * @return array{success: bool, data: array<string, mixed>, warnings: array<int, string>, errors: array<int, string>, meta?: array<string, mixed>}
     */
    public function parse(UploadedFile|string $image): array
    {
        $data = $this->emptyData();
        $warnings = [];
        $errors = [];
        $meta = [
            'parser' => 'coindcx_screenshot',
            'expected_profit_percent' => null,
        ];

        try {
            $ocrText = $this->extractOcrText($image instanceof UploadedFile ? $image->getRealPath() : $image);
        } catch (\Throwable $exception) {
            Log::warning('[CFS CoinDCX Parser] OCR failed', [
                'reason' => $exception->getMessage(),
            ]);

            return [
                'success' => false,
                'data' => $data,
                'warnings' => $warnings,
                'errors' => ['Could not read the CoinDCX screenshot. Please upload a clearer image.'],
                'meta' => $meta,
            ];
        }

        $text = $this->normalizeText($ocrText);
        if ($text === '') {
            return [
                'success' => false,
                'data' => $data,
                'warnings' => $warnings,
                'errors' => ['Could not read any text from the CoinDCX screenshot. Please upload a clearer image.'],
                'meta' => $meta,
            ];
        }

        [$data['symbol'], $data['pair']] = $this->parseSymbolAndPair($text);
        $data['direction'] = $this->parseDirection($text);
        $data['leverage'] = $this->parseLeverage($text);

        $fallbackText = null;
        $fallbackRan = false;
        if ($data['direction'] === null || $data['leverage'] === null) {
            $fallbackRan = true;
            $fallbackText = $this->extractDirectionBadgeOcrText($image instanceof UploadedFile ? $image->getRealPath() : $image);
            if ($fallbackText !== null) {
                $normalizedFallbackText = $this->normalizeText($fallbackText);
                $data['direction'] ??= $this->parseDirection($normalizedFallbackText);
                $data['leverage'] ??= $this->parseLeverage($normalizedFallbackText);
            }
        }

        $gridValues = $this->parseCoinDcxGridValues($text);
        $entry = $this->parseEntryRange($text) ?? $gridValues['entry_range'];
        if ($entry !== null) {
            $data['entry_min'] = $entry['midpoint'];
            $data['entry_max'] = $entry['midpoint'];
            $data['entry_type'] = 'single';
            $meta['entry_range_min'] = $entry['min'];
            $meta['entry_range_max'] = $entry['max'];
        }
        $data['stop_loss'] = $this->parseLabeledPrice($text, '(?:stop\s*loss|stoploss|s\s*/\s*l|sl)') ?? $gridValues['stop_loss'];
        $data['tp1'] = $this->parseLabeledPrice($text, '(?:take\s*[- ]?\s*profit|target|tp)') ?? $gridValues['take_profit'];
        $meta['expected_profit_percent'] = $this->parseExpectedProfit($text) ?? $gridValues['expected_profit_percent'];
        $meta['direction_badge_fallback_ran'] = $fallbackRan;
        $meta['direction_badge_fallback_recovered_direction'] = $fallbackRan && $data['direction'] !== null;
        $meta['direction_badge_fallback_recovered_leverage'] = $fallbackRan && $data['leverage'] !== null;
        $data['notes'] = "CoinDCX OCR text:\n".$text;

        $missingFields = $this->missingRequiredFields($data, $entry);
        foreach ($missingFields as $field) {
            $errors[] = 'Could not extract '.str_replace('_', ' ', $field);
        }

        if ($missingFields !== []) {
            Log::info('[CFS CoinDCX Parser] Missing required fields', [
                'missing_fields' => $missingFields,
                'direction_badge_fallback_ran' => $fallbackRan,
                'direction_recovered' => $fallbackRan && $data['direction'] !== null,
                'leverage_recovered' => $fallbackRan && $data['leverage'] !== null,
            ]);
        }

        if ($data['leverage'] !== null && $data['leverage'] <= 0) {
            $errors[] = 'Leverage must be greater than zero';
        }

        if ($entry !== null && $data['direction'] !== null && $data['stop_loss'] !== null && $data['tp1'] !== null) {
            $errors = array_merge($errors, $this->validatePriceRelationship($data['direction'], $entry['min'], $entry['max'], $data['stop_loss'], $data['tp1']));
        }

        return [
            'success' => $errors === [],
            'data' => $data,
            'warnings' => $warnings,
            'errors' => $errors === [] ? [] : [$this->formatErrors($errors, $missingFields)],
            'meta' => $meta,
        ];
    }

    /** @return array<string, mixed> */
    private function emptyData(): array
    {
        return [
            'symbol' => null,
            'pair' => null,
            'trader_name' => null,
            'direction' => null,
            'leverage' => null,
            'margin_mode' => null,
            'entry_min' => null,
            'entry_max' => null,
            'entry_type' => null,
            'stop_loss' => null,
            'tp1' => null,
            'tp2' => null,
            'tp3' => null,
            'tp4' => null,
            'market_type' => TradeSignal::MARKET_TYPE_FUTURES,
            'exchange' => 'coindcx',
            'signal_time' => null,
            'notes' => null,
        ];
    }

    private function extractOcrText(?string $path, string $pageSegmentationMode = '6'): string
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            throw new \RuntimeException('Uploaded image is not readable.');
        }

        $process = new Process(['tesseract', $path, 'stdout', '--psm', $pageSegmentationMode]);
        $process->setTimeout(self::OCR_TIMEOUT_SECONDS);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new \RuntimeException('OCR timed out.');
        }

        if (! $process->isSuccessful()) {
            throw new \RuntimeException('OCR executable failed or is unavailable.');
        }

        return $process->getOutput();
    }

    private function extractDirectionBadgeOcrText(?string $path): ?string
    {
        if ($path === null || $path === '' || ! is_file($path) || ! function_exists('imagecreatefromstring')) {
            return null;
        }

        $temporaryPath = tempnam(sys_get_temp_dir(), 'cfs_coindcx_badge_');
        if ($temporaryPath === false) {
            return null;
        }
        $temporaryPngPath = $temporaryPath.'.png';
        @unlink($temporaryPath);

        try {
            $image = @imagecreatefromstring((string) file_get_contents($path));
            if (! $image) {
                return null;
            }

            $width = imagesx($image);
            $height = imagesy($image);
            $cropX = (int) round($width * 0.12);
            $cropY = (int) round($height * 0.08);
            $cropWidth = (int) round($width * 0.76);
            $cropHeight = (int) round($height * 0.30);
            $crop = imagecrop($image, [
                'x' => $cropX,
                'y' => $cropY,
                'width' => $cropWidth,
                'height' => $cropHeight,
            ]);
            imagedestroy($image);

            if (! $crop) {
                return null;
            }

            $processed = imagecreatetruecolor(imagesx($crop), imagesy($crop));
            $black = imagecolorallocate($processed, 0, 0, 0);
            $white = imagecolorallocate($processed, 255, 255, 255);
            for ($y = 0; $y < imagesy($crop); $y++) {
                for ($x = 0; $x < imagesx($crop); $x++) {
                    $rgb = imagecolorat($crop, $x, $y);
                    $green = ($rgb >> 8) & 0xFF;
                    $blue = $rgb & 0xFF;
                    $channel = max($green, $blue);
                    imagesetpixel($processed, $x, $y, $channel >= 145 ? $white : $black);
                }
            }
            imagedestroy($crop);

            imagepng($processed, $temporaryPngPath);
            imagedestroy($processed);

            return $this->extractOcrText($temporaryPngPath, '6');
        } catch (\Throwable $exception) {
            Log::info('[CFS CoinDCX Parser] Direction badge OCR fallback failed', [
                'reason' => $exception->getMessage(),
            ]);

            return null;
        } finally {
            if (is_file($temporaryPngPath)) {
                @unlink($temporaryPngPath);
            }
        }
    }

    /** @return array{entry_range: array{min: int|float, max: int|float, midpoint: int|float}|null, stop_loss: int|float|null, take_profit: int|float|null, expected_profit_percent: int|float|null} */
    private function parseCoinDcxGridValues(string $text): array
    {
        $values = [
            'entry_range' => null,
            'stop_loss' => null,
            'take_profit' => null,
            'expected_profit_percent' => null,
        ];
        $lines = preg_split('/\n/u', $text) ?: [];

        foreach ($lines as $index => $line) {
            $nextLine = $lines[$index + 1] ?? '';
            if (preg_match('/\bstop\s*loss\b.*\btake\s*profit\b/i', $line) === 1) {
                $numbers = $this->extractNumbers($nextLine);
                if (count($numbers) >= 2) {
                    $values['stop_loss'] = $numbers[0];
                    $values['take_profit'] = $numbers[1];
                }
            }

            if (preg_match('/\bentry\s*range\b.*\bexpected\s*profit\b/i', $line) === 1) {
                $numbers = $this->extractNumbers($nextLine);
                if (count($numbers) >= 2) {
                    $min = min($numbers[0], $numbers[1]);
                    $max = max($numbers[0], $numbers[1]);
                    $values['entry_range'] = [
                        'min' => $min,
                        'max' => $max,
                        'midpoint' => $this->normalizeDecimal(((float) $min + (float) $max) / 2),
                    ];
                }

                if (preg_match('/([+-]?\s*'.$this->numberPattern().')\s*%/u', $nextLine, $matches) === 1) {
                    $values['expected_profit_percent'] = $this->cleanNumericValue(str_replace(['+', ' '], '', $matches[1]));
                }
            }
        }

        return $values;
    }

    /** @return array<int, string> */
    private function missingRequiredFields(array $data, ?array $entry): array
    {
        $fields = [];
        foreach ([
            'symbol' => $data['symbol'],
            'direction' => $data['direction'],
            'leverage' => $data['leverage'],
            'entry_range' => $entry,
            'stop_loss' => $data['stop_loss'],
            'take_profit' => $data['tp1'],
        ] as $field => $value) {
            if ($value === null) {
                $fields[] = $field;
            }
        }

        return $fields;
    }

    /** @param array<int, string> $errors @param array<int, string> $missingFields */
    private function formatErrors(array $errors, array $missingFields): string
    {
        if ($missingFields !== []) {
            return 'Could not extract these required fields from the CoinDCX screenshot: '.implode(', ', array_map(fn (string $field): string => str_replace('_', ' ', $field), $missingFields)).'. Please upload a clearer or uncropped image.';
        }

        return implode('; ', array_values(array_unique($errors)));
    }

    /** @return array<int, int|float> */
    private function extractNumbers(string $text): array
    {
        preg_match_all('/'.$this->numberPattern().'/u', $text, $matches);

        return array_map(fn (string $number): int|float => $this->cleanNumericValue($number), $matches[0]);
    }

    private function normalizeText(string $text): string
    {
        $text = str_replace(["\r\n", "\r", '–', '—'], ["\n", "\n", '-', '-'], $text);
        $lines = array_map(
            fn (string $line): string => trim(preg_replace('/\s+/u', ' ', $line) ?? $line),
            preg_split('/\n/u', $text) ?: []
        );

        return trim(implode("\n", array_filter($lines, fn (string $line): bool => $line !== '')));
    }

    /** @return array{0: string|null, 1: string|null} */
    private function parseSymbolAndPair(string $text): array
    {
        if (preg_match('/\b([A-Z0-9]{2,15})\s*(?:\/|:|·|•|\-|_|\s)\s*USDT\b/i', $text, $matches) !== 1) {
            return [null, null];
        }

        $base = strtoupper(preg_replace('/\s+/u', '', $matches[1]) ?? $matches[1]);
        if ($base === 'USDT') {
            return [null, null];
        }

        return [$base.'USDT', $base.'/USDT'];
    }

    private function parseDirection(string $text): ?string
    {
        if (preg_match('/(?:^|\b)(long|short)\s*(?=\d|x|\b)/i', $text, $matches) !== 1) {
            return null;
        }

        return strtoupper($matches[1]);
    }

    private function parseLeverage(string $text): int|float|null
    {
        if (preg_match('/\b(?:long|short)\s*(\d+(?:\.\d+)?)\s*x\b/i', $text, $matches) === 1) {
            return $this->cleanNumericValue($matches[1]);
        }

        if (preg_match('/(?:^|\b)(?:leverage|lev)?\s*[:\-]?\s*(\d+(?:\.\d+)?)\s*x\b/i', $text, $matches) !== 1) {
            return null;
        }

        return $this->cleanNumericValue($matches[1]);
    }

    /** @return array{min: int|float, max: int|float, midpoint: int|float}|null */
    private function parseEntryRange(string $text): ?array
    {
        $pattern = '/\b(?:entry(?:\s*(?:price|range))?|entry\s*zone)\b\s*[:\-]?\s*\$?\s*('.$this->numberPattern().')\s*(?:-|to|\n)\s*\$?\s*('.$this->numberPattern().')/iu';
        if (preg_match($pattern, $text, $matches) !== 1) {
            return null;
        }

        $first = $this->cleanNumericValue($matches[1]);
        $second = $this->cleanNumericValue($matches[2]);
        $min = min($first, $second);
        $max = max($first, $second);

        return [
            'min' => $min,
            'max' => $max,
            'midpoint' => $this->normalizeDecimal(((float) $min + (float) $max) / 2),
        ];
    }

    private function parseLabeledPrice(string $text, string $labelPattern): int|float|null
    {
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if (preg_match('~\b'.$labelPattern.'\b\s*[:\-]?\s*\$?\s*('.$this->numberPattern().')~iu', $line, $matches) === 1) {
                return $this->cleanNumericValue($matches[1]);
            }
        }

        return null;
    }

    private function parseExpectedProfit(string $text): int|float|null
    {
        foreach (preg_split('/\n/u', $text) ?: [] as $line) {
            if (preg_match('/\bexpected\s*profit\b\s*[:\-]?\s*([+-]?\s*'.$this->numberPattern().')\s*%/iu', $line, $matches) === 1) {
                return $this->cleanNumericValue(str_replace(['+', ' '], '', $matches[1]));
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function validatePriceRelationship(string $direction, int|float $entryMin, int|float $entryMax, int|float $stopLoss, int|float $takeProfit): array
    {
        if ($direction === TradeSignal::DIRECTION_LONG) {
            return array_values(array_filter([
                $stopLoss < $entryMin ? null : 'Stop loss should be below the LONG entry range',
                $takeProfit > $entryMax ? null : 'Take profit should be above the LONG entry range',
            ]));
        }

        return array_values(array_filter([
            $stopLoss > $entryMax ? null : 'Stop loss should be above the SHORT entry range',
            $takeProfit < $entryMin ? null : 'Take profit should be below the SHORT entry range',
        ]));
    }

    private function numberPattern(): string
    {
        return '\\d{1,3}(?:,\\d{3})+(?:\\.\\d+)?|\\d+(?:\\.\\d+)?';
    }

    private function cleanNumericValue(string $number): int|float
    {
        $normalized = str_replace(',', '', $number);
        $value = (float) $normalized;

        return str_contains($normalized, '.') ? $this->normalizeDecimal($value) : (int) $value;
    }

    private function normalizeDecimal(float $value): int|float
    {
        $normalized = rtrim(rtrim(number_format($value, 12, '.', ''), '0'), '.');

        return str_contains($normalized, '.') ? (float) $normalized : (int) $normalized;
    }
}
