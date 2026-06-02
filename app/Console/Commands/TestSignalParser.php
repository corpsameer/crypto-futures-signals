<?php

namespace App\Console\Commands;

use App\Services\SignalParserService;
use Illuminate\Console\Command;

class TestSignalParser extends Command
{
    protected $signature = 'signal:test-parser';

    protected $description = 'Test the crypto futures signal parser against hardcoded examples.';

    public function handle(SignalParserService $parser): int
    {
        $failed = false;

        foreach ($this->examples() as $title => $example) {
            $result = $parser->parse($example['raw_text']);
            $data = $result['data'];

            $this->line(str_repeat('-', 60));
            $this->info($title);
            $this->line('Success: '.($result['success'] ? 'true' : 'false'));
            $this->line('symbol: '.($data['symbol'] ?? 'N/A'));
            $this->line('pair: '.($data['pair'] ?? 'N/A'));
            $this->line('trader_name: '.($data['trader_name'] ?? 'N/A'));
            $this->line('direction: '.($data['direction'] ?? 'N/A'));
            $this->line('leverage: '.$this->formatNullable($data['leverage']));
            $this->line('entry_min: '.$this->formatNullable($data['entry_min']));
            $this->line('entry_max: '.$this->formatNullable($data['entry_max']));
            $this->line('stop_loss: '.$this->formatNullable($data['stop_loss']));
            $this->line('tp1: '.$this->formatNullable($data['tp1']));
            $this->line('tp2: '.$this->formatNullable($data['tp2']));
            $this->line('tp3: '.$this->formatNullable($data['tp3']));
            $this->line('tp4: '.$this->formatNullable($data['tp4']));
            $this->line('warnings: '.($result['warnings'] === [] ? 'None' : implode('; ', $result['warnings'])));
            $this->line('errors: '.($result['errors'] === [] ? 'None' : implode('; ', $result['errors'])));

            foreach ($example['expected'] as $field => $expected) {
                $actual = $data[$field] ?? null;
                $passed = $this->matchesExpected($actual, $expected);
                $failed = $failed || ! $passed;

                $this->line(sprintf(
                    '%s %s expected %s, got %s',
                    $passed ? 'PASS' : 'FAIL',
                    $field,
                    $this->formatNullable($expected),
                    $this->formatNullable($actual),
                ));
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return array<string, array{raw_text: string, expected: array<string, mixed>}>
     */
    private function examples(): array
    {
        return [
            'Example ICP - numbered dollar targets with RR text' => [
                'raw_text' => <<<'SIGNAL'
Coin name ICP/USDT

Futures Trading 🥇
Signal Type LONG Position 🟩
Lev - 5x
Risk 0.5%

Entry price - $ 2.6926 (Limit Order)

Targets 🎯-

1) $ 2.93 (RR 2)
2) $ 3.345 (RR 5.5)

🚫Stop loss- $ 2.574 (SL % 4.4)

⚠️ Action
– After TP1 hit, secure 50% &
Move SL to breakeven / CTC.

Signal by Trader : Sumit
SIGNAL,
                'expected' => [
                    'tp1' => 2.93,
                    'tp2' => 3.345,
                    'trader_name' => 'Sumit',
                    'stop_loss' => 2.574,
                ],
            ],
            'Example CHZ - four dollar targets with RR ratio' => [
                'raw_text' => <<<'SIGNAL'
Coin name CHZ/USDT

Futures Trading 🥇
Signal Type LONG Position 🟩
Lev - 5x
Risk 0.5%

Entry price - $ 0.3520

Targets 🎯-

1) $ 0.3630
2) $ 0.377
3) $ 0.387
4) $ 0.4095 (RR:-1:4.2)

🚫Stop loss- $0.3321  (SL4.3 % )

⚠️ Action
– After TP2 hit, secure 50% &
Move SL to breakeven / CTC.

Signal by Trader : Mohan
SIGNAL,
                'expected' => [
                    'tp1' => 0.3630,
                    'tp2' => 0.377,
                    'tp3' => 0.387,
                    'tp4' => 0.4095,
                    'trader_name' => 'Mohan',
                    'stop_loss' => 0.3321,
                ],
            ],
            'Example OP - spaced coin name and four targets' => [
                'raw_text' => <<<'SIGNAL'
Coin name  OP/USDT

Futures Trading 🥇
Signal Type LONG Position 🟩
Lev - 5x
Risk 0.5%

Entry price - $  0.1230

Targets 🎯-

1) $ 0.126
2) $ 0.1287
3) $ 0.1313
4) $ 0.1353

🚫Stop loss- $ 0.1156 (SL 4.90% )

⚠️ Action
– After TP2 hit, secure 50% &
Move SL to breakeven / CTC.

Signal by Trader : Mohan
SIGNAL,
                'expected' => [
                    'tp1' => 0.126,
                    'tp2' => 0.1287,
                    'tp3' => 0.1313,
                    'tp4' => 0.1353,
                    'trader_name' => 'Mohan',
                    'stop_loss' => 0.1156,
                ],
            ],
        ];
    }

    private function matchesExpected(mixed $actual, mixed $expected): bool
    {
        if (is_numeric($actual) && is_numeric($expected)) {
            return abs((float) $actual - (float) $expected) < 0.00000001;
        }

        return $actual === $expected;
    }

    private function formatNullable(mixed $value): string
    {
        return $value === null ? 'N/A' : (string) $value;
    }
}
