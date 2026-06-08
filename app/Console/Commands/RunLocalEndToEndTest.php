<?php

namespace App\Console\Commands;

use App\Models\PastedSignal;
use App\Models\SimulatedTrade;
use App\Models\TradeSignal;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class RunLocalEndToEndTest extends Command
{
    protected $signature = 'cfs:test-local-e2e {--batch= : Reuse a specific LOCAL_E2E_TEST_BATCH_* marker instead of creating a new one}';

    protected $description = 'Prepare controlled local E2E test signals for the Crypto Futures Signal Analyzer Python scenario runner.';

    public function handle(): int
    {
        $batch = $this->option('batch') ?: 'LOCAL_E2E_TEST_BATCH_'.now()->format('YmdHis');
        $user = $this->testUser();
        $now = now();

        $this->info('Preparing Crypto Futures Signal Analyzer local E2E test data.');
        $this->line('Batch: '.$batch);
        $this->line('User: '.$user->email.' (#'.$user->id.')');
        $this->newLine();

        $rows = [];
        foreach ($this->scenarios($now) as $scenario) {
            $signal = $this->createOrReuseScenario($user, $batch, $scenario, $now);
            $rows[] = [$scenario['name'], $signal->symbol, $signal->id, $signal->status];
        }

        $this->table(['Scenario', 'Symbol', 'Trade Signal ID', 'Status'], $rows);

        $this->newLine();
        $this->info('Next steps:');
        $this->line('1. Start Laravel locally if it is not already running:');
        $this->line('   php artisan serve');
        $this->line('2. Run the Python local E2E scenario runner with fake prices:');
        $this->line('   python python/local_e2e_test.py --batch latest');
        $this->line('   # or target this batch explicitly:');
        $this->line('   python python/local_e2e_test.py --batch '.$batch);
        $this->line('3. Review logs: python/logs/local_e2e_test.log');
        $this->newLine();
        $this->comment('This command only prepares local simulation/tracking data. It does not run Python, call CoinDCX, place live orders, or send Telegram messages.');

        return self::SUCCESS;
    }

    private function testUser(): User
    {
        return User::query()->firstOrCreate(
            ['email' => 'local-e2e@example.test'],
            [
                'name' => 'Local E2E Tester',
                'password' => Hash::make('local-e2e-password'),
            ]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function scenarios(Carbon $now): array
    {
        $baseLong = [
            'direction' => TradeSignal::DIRECTION_LONG,
            'leverage' => 5,
            'entry_min' => 100,
            'entry_max' => 100,
            'stop_loss' => 95,
            'tp1' => 101,
            'tp2' => 102,
            'tp3' => 103,
            'tp4' => 104,
        ];

        $baseShort = [
            'direction' => TradeSignal::DIRECTION_SHORT,
            'leverage' => 5,
            'entry_min' => 100,
            'entry_max' => 100,
            'stop_loss' => 105,
            'tp1' => 99,
            'tp2' => 98,
            'tp3' => 97,
            'tp4' => 96,
        ];

        return [
            ['name' => 'A_LONG_FULL_TP', 'symbol' => 'E2ELONGUSDT'] + $baseLong,
            ['name' => 'B_LONG_SL', 'symbol' => 'E2ELONGSLUSDT'] + $baseLong,
            ['name' => 'C_SHORT_FULL_TP', 'symbol' => 'E2ESHORTUSDT'] + $baseShort,
            ['name' => 'D_SHORT_SL', 'symbol' => 'E2ESHORTSLUSDT'] + $baseShort,
            ['name' => 'E_ENTRY_MISSED', 'symbol' => 'E2EMISSEDUSDT', 'signal_time' => $now->copy()->subHours(25), 'created_at' => $now->copy()->subHours(25), 'updated_at' => $now->copy()->subHours(25)] + $baseLong,
            ['name' => 'F_POST_SL_RECOVERY', 'symbol' => 'E2EPOSTSLUSDT'] + $baseLong,
            ['name' => 'G_POST_SL_COMPLETION', 'symbol' => 'E2ECOMPLETEUSDT', 'precreate_tracking_trade' => true] + $baseLong,
            ['name' => 'H_IDEMPOTENCY', 'symbol' => 'E2EIDEMPUSDT'] + $baseLong,
            ['name' => 'I_MARKET_SNAPSHOT', 'symbol' => 'E2ESNAPSHOTUSDT'] + $baseLong,
            ['name' => 'J_DYNAMIC_FINAL_TP_TP2_ONLY', 'symbol' => 'E2ETP2ONLYUSDT', 'tp3' => null, 'tp4' => null] + $baseLong,
            ['name' => 'K_DYNAMIC_FINAL_TP_TP1_ONLY', 'symbol' => 'E2ETP1ONLYUSDT', 'tp2' => null, 'tp3' => null, 'tp4' => null] + $baseLong,
        ];
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function createOrReuseScenario(User $user, string $batch, array $scenario, Carbon $now): TradeSignal
    {
        $notes = $batch.' | '.$scenario['name'];
        $existing = TradeSignal::query()
            ->where('symbol', $scenario['symbol'])
            ->where('trader_name', 'LocalE2E')
            ->where('notes', 'like', '%'.$batch.'%')
            ->first();

        if ($existing) {
            return $existing;
        }

        $signalTime = $scenario['signal_time'] ?? $now;
        $createdAt = $scenario['created_at'] ?? $now;
        $updatedAt = $scenario['updated_at'] ?? $now;

        $pastedSignal = PastedSignal::create([
            'user_id' => $user->id,
            'trader_name' => 'LocalE2E',
            'raw_text' => $this->rawSignalText($scenario, $batch),
            'parsed_payload' => [
                'local_e2e_batch' => $batch,
                'scenario' => $scenario['name'],
                'symbol' => $scenario['symbol'],
            ],
            'parse_status' => PastedSignal::PARSE_STATUS_PARSED,
            'source' => 'local_e2e_test',
            'pasted_at' => $signalTime,
        ]);
        $pastedSignal->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ])->save();

        $signal = TradeSignal::create([
            'pasted_signal_id' => $pastedSignal->id,
            'user_id' => $user->id,
            'trader_name' => 'LocalE2E',
            'exchange' => 'coindcx',
            'symbol' => $scenario['symbol'],
            'pair' => str_replace('USDT', '/USDT', $scenario['symbol']),
            'market_type' => TradeSignal::MARKET_TYPE_FUTURES,
            'direction' => $scenario['direction'],
            'leverage' => $scenario['leverage'],
            'margin_mode' => 'isolated',
            'entry_min' => $scenario['entry_min'],
            'entry_max' => $scenario['entry_max'],
            'entry_type' => 'limit',
            'stop_loss' => $scenario['stop_loss'],
            'tp1' => $scenario['tp1'],
            'tp2' => $scenario['tp2'],
            'tp3' => $scenario['tp3'],
            'tp4' => $scenario['tp4'],
            'signal_time' => $signalTime,
            'expires_at' => $scenario['name'] === 'E_ENTRY_MISSED' ? $now->copy()->subHour() : $now->copy()->addHours(24),
            'status' => ! empty($scenario['precreate_tracking_trade']) ? TradeSignal::STATUS_TRACKING_AFTER_SL : TradeSignal::STATUS_PENDING_ENTRY,
            'notes' => $notes,
        ]);
        $signal->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
        ])->save();

        if (! empty($scenario['precreate_tracking_trade'])) {
            SimulatedTrade::create([
                'trade_signal_id' => $signal->id,
                'user_id' => $user->id,
                'symbol' => $signal->symbol,
                'direction' => $signal->direction,
                'leverage' => $signal->leverage,
                'entry_price' => 100,
                'entry_triggered_at' => $now->copy()->subDays(8),
                'stop_loss' => $signal->stop_loss,
                'current_price' => 95,
                'max_price_after_entry' => 100,
                'min_price_after_entry' => 95,
                'max_price' => 100,
                'min_price' => 95,
                'max_actual_price_move_percent' => 0,
                'max_leveraged_pnl_percent' => 0,
                'min_actual_price_move_percent' => -5,
                'min_leveraged_pnl_percent' => -25,
                'max_actual_gain_percent' => 0,
                'max_actual_loss_percent' => -5,
                'max_gain_percent' => 0,
                'max_loss_percent' => -25,
                'status' => SimulatedTrade::STATUS_TRACKING_AFTER_SL,
                'tracking_until' => $now->copy()->subMinute(),
            ]);
        }

        return $signal;
    }

    /**
     * @param array<string, mixed> $scenario
     */
    private function rawSignalText(array $scenario, string $batch): string
    {
        return sprintf(
            "%s\nScenario: %s\n%s %s %sx\nEntry: %s\nSL: %s\nTP: %s, %s, %s, %s",
            $batch,
            $scenario['name'],
            $scenario['symbol'],
            $scenario['direction'],
            $scenario['leverage'],
            $scenario['entry_min'],
            $scenario['stop_loss'],
            $scenario['tp1'],
            $scenario['tp2'],
            $scenario['tp3'],
            $scenario['tp4'],
        );
    }
}
