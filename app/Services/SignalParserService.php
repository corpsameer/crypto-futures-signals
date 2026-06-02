<?php

namespace App\Services;

class SignalParserService
{
    /**
     * Parse raw Telegram crypto futures signal text into a structured payload.
     *
     * @return array{success: bool, data: array<string, mixed>, warnings: array<int, string>, errors: array<int, string>}
     */
    public function parse(string $rawText): array
    {
        $text = $this->normalizeText($rawText);
        $lines = $this->lines($text);

        $data = [
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
            'market_type' => 'futures',
            'exchange' => 'coindcx',
            'signal_time' => null,
            'notes' => null,
        ];

        [$data['symbol'], $data['pair']] = $this->parseSymbolAndPair($text);
        $data['trader_name'] = $this->parseTraderName($text);
        $data['direction'] = $this->parseDirection($text);
        $data['leverage'] = $this->parseLeverage($text);
        $data['margin_mode'] = $this->parseMarginMode($text);

        $entry = $this->parseEntry($lines);
        if ($entry !== null) {
            $data['entry_min'] = $entry['min'];
            $data['entry_max'] = $entry['max'];
            $data['entry_type'] = $entry['type'];
        }

        $data['stop_loss'] = $this->parseStopLoss($lines);

        foreach ($this->parseTargets($lines) as $index => $target) {
            $data['tp'.($index + 1)] = $target;
        }

        $warnings = [];
        if ($data['leverage'] === null) {
            $warnings[] = 'Leverage not found';
        }
        if ($data['margin_mode'] === null) {
            $warnings[] = 'Margin mode not found';
        }

        $errors = [];
        if ($data['symbol'] === null) {
            $errors[] = 'Symbol not found';
        }
        if ($data['direction'] === null) {
            $errors[] = 'Direction not found';
        }
        if ($data['entry_min'] === null && $data['entry_max'] === null) {
            $errors[] = 'Entry not found';
        }
        if ($data['stop_loss'] === null) {
            $errors[] = 'Stop loss not found';
        }
        if ($data['tp1'] === null) {
            $errors[] = 'At least one target not found';
        }

        return [
            'success' => $errors === [],
            'data' => $data,
            'warnings' => $warnings,
            'errors' => $errors,
        ];
    }

    private function normalizeText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @return array<int, string>
     */
    private function lines(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: []), fn (string $line): bool => $line !== ''));
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseSymbolAndPair(string $text): array
    {
        if (preg_match('/\bcoin\s*name\s*[:\-]?\s*([A-Z0-9]{2,15})\s*\/\s*USDT\b/i', $text, $matches) === 1) {
            $base = strtoupper($matches[1]);

            return [$base.'USDT', $base.'/USDT'];
        }

        if (! preg_match('/#?\b([A-Z0-9]{2,15})\s*(?:\/|\-|\s)?\s*USDT\b/i', $text, $matches)) {
            return [null, null];
        }

        $base = strtoupper($matches[1]);
        if ($base === 'USDT') {
            return [null, null];
        }

        return [$base.'USDT', $base.'/USDT'];
    }

    private function parseTraderName(string $text): ?string
    {
        $patterns = [
            '/\bsignal\s+by\s+trader\s*:\s*([^\n]+)/i',
            '/\btrader\s*:\s*([^\n]+)/i',
            '/\bsignal\s+by\s*:\s*([^\n]+)/i',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $matches) === 1) {
                $name = trim($matches[1]);
                $name = preg_replace('/\s+/u', ' ', $name) ?: $name;
                $name = trim($name, " \t\n\r\0\x0B.,;:-");

                return $name !== '' ? $name : null;
            }
        }

        return null;
    }

    private function parseDirection(string $text): ?string
    {
        if (! preg_match('/\b(LONG|SHORT|BUY|SELL)\b/i', $text, $matches)) {
            return null;
        }

        return in_array(strtoupper($matches[1]), ['LONG', 'BUY'], true) ? 'LONG' : 'SHORT';
    }

    private function parseLeverage(string $text): int|float|null
    {
        if (! preg_match('/\b(?:leverage|lev|cross|isolated)?\s*[:\-]?\s*(\d+(?:\.\d+)?)\s*x\b/i', $text, $matches)) {
            return null;
        }

        return $this->cleanNumericValue($matches[1]);
    }

    private function parseMarginMode(string $text): ?string
    {
        if (! preg_match('/\b(cross|isolated)\b/i', $text, $matches)) {
            return null;
        }

        return strtolower($matches[1]);
    }

    /**
     * @param array<int, string> $lines
     * @return array{min: int|float, max: int|float, type: string}|null
     */
    private function parseEntry(array $lines): ?array
    {
        foreach ($lines as $line) {
            if (preg_match('/\b(entry(?:\s+(?:price|zone))?|buy)\b\s*[:\-]?/i', $line) !== 1) {
                continue;
            }

            $lineAfterLabel = preg_replace('/^.*?\b(?:entry(?:\s+(?:price|zone))?|buy)\b\s*[:\-]?\s*/i', '', $line) ?? $line;
            $numbers = $this->extractNumbersOutsideParentheses($lineAfterLabel);
            if ($numbers === []) {
                continue;
            }

            $entryNumbers = array_slice($numbers, 0, 2);
            $isRange = count($entryNumbers) >= 2;
            $type = $isRange ? 'range' : 'single';
            if (! $isRange && preg_match('/\blimit\b/i', $line) === 1) {
                $type = 'limit';
            }

            return [
                'min' => $isRange ? min($entryNumbers[0], $entryNumbers[1]) : $entryNumbers[0],
                'max' => $isRange ? max($entryNumbers[0], $entryNumbers[1]) : $entryNumbers[0],
                'type' => $type,
            ];
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     */
    private function parseStopLoss(array $lines): int|float|null
    {
        foreach ($lines as $line) {
            if (preg_match('/\b(s\/l|s\s*\/\s*l|stop\s*[- ]?\s*loss|stoploss|sl)\b\s*[:\-]?/i', $line) !== 1) {
                continue;
            }

            $priceAfterDollar = $this->extractFirstPriceFromLine($line);
            if ($priceAfterDollar !== null) {
                return $priceAfterDollar;
            }

            $lineAfterLabel = preg_replace('/^.*?\b(?:s\/l|s\s*\/\s*l|stop\s*[- ]?\s*loss|stoploss|sl)\b\s*[:\-]?\s*/i', '', $line) ?? $line;
            $numbers = $this->extractNumbersOutsideParentheses($lineAfterLabel);
            if ($numbers !== []) {
                return $numbers[0];
            }
        }

        return null;
    }

    /**
     * @param array<int, string> $lines
     * @return array<int, int|float>
     */
    private function parseTargets(array $lines): array
    {
        $targets = [];
        $collectingTargetBlock = false;

        foreach ($lines as $line) {
            if ($collectingTargetBlock && $this->isTargetBlockTerminator($line)) {
                $collectingTargetBlock = false;
                continue;
            }

            $isTargetHeader = $this->isTargetSectionHeader($line);
            if ($isTargetHeader) {
                $collectingTargetBlock = true;
                $labelRemainder = preg_replace('/^.*?\b(?:targets?|take\s*profits?|tp)\b\s*[:\-]?\s*/i', '', $line) ?? '';

                foreach ($this->extractTargetPricesFromLine($labelRemainder, true) as $target) {
                    $targets[] = $target;
                }

                if (count($targets) >= 4) {
                    break;
                }

                continue;
            }

            if (! $collectingTargetBlock && preg_match('/^\s*(?:tp\s*[1-4]|target\s*[1-4])\s*[:=\-]\s*/i', $line) !== 1) {
                continue;
            }

            foreach ($this->extractTargetPricesFromLine($line) as $target) {
                $targets[] = $target;
            }

            if (count($targets) >= 4) {
                break;
            }
        }

        return array_slice($targets, 0, 4);
    }

    private function isTargetSectionHeader(string $line): bool
    {
        return preg_match('/\b(targets?|take\s*profits?|tp)\b/i', $line) === 1
            && preg_match('/^\s*(?:tp\s*[1-4]|target\s*[1-4])\s*[:=\-]/i', $line) !== 1;
    }

    private function isTargetBlockTerminator(string $line): bool
    {
        return preg_match('/\b(entry(?:\s+(?:price|zone))?|buy|s\/l|s\s*\/\s*l|stop\s*[- ]?\s*loss|stoploss|sl|action|signal\s+by\s+trader|signal\s+by|trader\s*:|risk|leverage|lev|cross|isolated|direction|coin)\b/i', $line) === 1;
    }

    /**
     * @return array<int, int|float>
     */
    private function extractTargetPricesFromLine(string $line, bool $lineAlreadyAfterLabel = false): array
    {
        $line = trim($line);
        if ($line === '' || preg_match('/^[-–—]+$/u', $line) === 1) {
            return [];
        }

        $lineWithoutParentheses = preg_replace('/\([^)]*\)/', '', $line) ?? $line;

        if (preg_match('/^\s*\d+\s*[).\-]\s*\$\s*('.$this->numberPattern().')\b/u', $lineWithoutParentheses, $matches) === 1) {
            return [$this->cleanNumericValue($matches[1])];
        }

        if (preg_match('/^\s*\d+\s*[).\-]\s*('.$this->numberPattern().')\b/u', $lineWithoutParentheses, $matches) === 1) {
            return [$this->cleanNumericValue($matches[1])];
        }

        if (! $lineAlreadyAfterLabel && preg_match('/^\s*(?:tp\s*[1-4]|target\s*[1-4])\s*[:=\-]\s*\$?\s*('.$this->numberPattern().')\b/iu', $lineWithoutParentheses, $matches) === 1) {
            return [$this->cleanNumericValue($matches[1])];
        }

        return $this->extractNumbersOutsideParentheses($lineWithoutParentheses);
    }

    private function extractFirstPriceFromLine(string $line): int|float|null
    {
        if (preg_match('/\$\s*('.$this->numberPattern().')\b/u', $line, $matches) !== 1) {
            return null;
        }

        return $this->cleanNumericValue($matches[1]);
    }

    /**
     * @return array<int, int|float>
     */
    private function extractNumbersOutsideParentheses(string $text): array
    {
        $text = preg_replace('/\([^)]*\)/', '', $text) ?? $text;
        $text = preg_replace('/\bRR\s*[:\-]?\s*[-\d:.]+/i', '', $text) ?? $text;
        $text = preg_replace('/\b\d+(?:\.\d+)?\s*%/', '', $text) ?? $text;
        $text = preg_replace('/\b\d+(?:\.\d+)?\s*x\b/i', '', $text) ?? $text;

        preg_match_all('/'.$this->numberPattern().'/u', $text, $matches);

        return array_map(fn (string $number): int|float => $this->cleanNumericValue($number), $matches[0]);
    }

    private function numberPattern(): string
    {
        return '\\d{1,3}(?:,\\d{3})+(?:\\.\\d+)?|\\d+(?:\\.\\d+)?';
    }

    private function cleanNumericValue(string $number): int|float
    {
        $normalized = str_replace(',', '', $number);
        $value = (float) $normalized;

        return str_contains($normalized, '.') ? $value : (int) $value;
    }
}
