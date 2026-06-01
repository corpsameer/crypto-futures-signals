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
        foreach ($this->examples() as $title => $rawText) {
            $result = $parser->parse($rawText);
            $data = $result['data'];
            $tps = array_values(array_filter([
                $data['tp1'],
                $data['tp2'],
                $data['tp3'],
                $data['tp4'],
            ], fn ($target): bool => $target !== null));

            $this->line(str_repeat('-', 60));
            $this->info($title);
            $this->line('Success: '.($result['success'] ? 'true' : 'false'));
            $this->line('Symbol: '.($data['symbol'] ?? 'N/A'));
            $this->line('Direction: '.($data['direction'] ?? 'N/A'));
            $this->line('Entry Range: '.$this->formatNullable($data['entry_min']).' - '.$this->formatNullable($data['entry_max']));
            $this->line('Stop Loss: '.$this->formatNullable($data['stop_loss']));
            $this->line('TP Levels: '.($tps === [] ? 'N/A' : implode(', ', $tps)));
            $this->line('Warnings: '.($result['warnings'] === [] ? 'None' : implode('; ', $result['warnings'])));
            $this->line('Errors: '.($result['errors'] === [] ? 'None' : implode('; ', $result['errors'])));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function examples(): array
    {
        return [
            'Example A - BTC long range entry' => "BTC/USDT LONG\nLeverage: 10x\nEntry: 65000 - 65200\nTargets: 66000, 67000, 68000, 69000\nStop Loss: 64000",
            'Example B - ETH short TP lines' => "#ETHUSDT SHORT\nEntry Zone: 3200-3225\nSL: 3260\nTP1: 3150\nTP2: 3100\nTP3: 3050\nLeverage 20x",
            'Example C - SOL buy signal' => "Coin: SOLUSDT\nDirection: Long\nBuy: 145.5\nTargets:\n150\n155\n160\nStoploss: 140",
            'Example D - DOGE space separated pair' => "DOGE USDT LONG\nEntry: 0.165\nTake Profit: 0.170 / 0.175 / 0.180 / 0.190\nSL 0.160\nLev: 10x",
            'Incomplete example' => 'BTC moon soon',
        ];
    }

    private function formatNullable(mixed $value): string
    {
        return $value === null ? 'N/A' : (string) $value;
    }
}
