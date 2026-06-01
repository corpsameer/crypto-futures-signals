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
        $text = trim($rawText);
        $lines = $this->lines($text);

        $data = [
            'symbol' => null,
            'pair' => null,
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

        [$data['symbol'], $data['pair']] = $this->parseSymbol($text);
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
    private function parseSymbol(string $text): array
    {
        if (! preg_match('/#?\b([A-Z0-9]{2,15})\s*(?:\/|\-|\s)?\s*USDT\b/i', $text, $matches)) {
            return [null, null];
        }

        $base = strtoupper($matches[1]);
        if ($base === 'USDT') {
            return [null, null];
        }

        return [$base.'USDT', $base.'/USDT'];
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
        if (! preg_match('/\b(?:leverage|lev|cross|isolated)?\s*:?\s*(\d+(?:\.\d+)?)\s*x\b/i', $text, $matches)) {
            return null;
        }

        return $this->normalizeNumber($matches[1]);
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
            if (! preg_match('/\b(entry(?:\s+zone)?|buy)\b\s*:?/i', $line)) {
                continue;
            }

            $numbers = $this->extractNumbers($line);
            if ($numbers === []) {
                continue;
            }

            $entryNumbers = array_slice($numbers, 0, 2);
            if (count($entryNumbers) >= 2) {
                return [
                    'min' => min($entryNumbers[0], $entryNumbers[1]),
                    'max' => max($entryNumbers[0], $entryNumbers[1]),
                    'type' => 'range',
                ];
            }

            return [
                'min' => $entryNumbers[0],
                'max' => $entryNumbers[0],
                'type' => 'single',
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
            if (! preg_match('/\b(s\/?l|stop\s*[- ]?\s*loss|stoploss|sl)\b\s*:?/i', $line)) {
                continue;
            }

            $numbers = $this->extractNumbers($line);
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
            $isExplicitTp = preg_match('/\btp\s*([1-4])\b\s*:?/i', $line, $tpMatch) === 1;
            $isTargetHeader = preg_match('/\b(targets?|take\s*profits?|tps?)\b\s*:?/i', $line) === 1;

            if ($isExplicitTp || $isTargetHeader) {
                $numbers = $this->extractNumbers($line);

                if ($isExplicitTp && $numbers !== []) {
                    $targets[(int) $tpMatch[1] - 1] = end($numbers);
                } else {
                    foreach ($numbers as $number) {
                        $targets[] = $number;
                    }
                }

                $collectingTargetBlock = $isTargetHeader && $numbers === [];
                continue;
            }

            if (! $collectingTargetBlock) {
                continue;
            }

            if ($this->isTargetBlockTerminator($line)) {
                $collectingTargetBlock = false;
                continue;
            }

            foreach ($this->extractNumbers($line) as $number) {
                $targets[] = $number;
            }
        }

        ksort($targets);

        return array_slice(array_values($targets), 0, 4);
    }

    private function isTargetBlockTerminator(string $line): bool
    {
        return preg_match('/\b(entry(?:\s+zone)?|buy|s\/?l|stop\s*[- ]?\s*loss|stoploss|sl|leverage|lev|cross|isolated|direction|coin)\b/i', $line) === 1;
    }

    /**
     * @return array<int, int|float>
     */
    private function extractNumbers(string $text): array
    {
        preg_match_all('/\d{1,3}(?:,\d{3})+(?:\.\d+)?|\d+(?:\.\d+)?/', $text, $matches);

        return array_map(fn (string $number): int|float => $this->normalizeNumber($number), $matches[0]);
    }

    private function normalizeNumber(string $number): int|float
    {
        $normalized = str_replace(',', '', $number);
        $value = (float) $normalized;

        return str_contains($normalized, '.') ? $value : (int) $value;
    }
}
