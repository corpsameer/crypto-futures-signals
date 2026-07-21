<?php

namespace Tests\Feature;

use App\Models\StrategyBacktestRun;
use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use App\Models\TradeSignal;
use App\Models\SimulatedTrade;
use App\Models\TradeTrackingEvent;
use Database\Seeders\StrategyDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RecoveryHoldTp1StrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(StrategyDefinitionSeeder::class);
    }

    public function test_recovery_hold_final_exit_directional_pnl_and_loss_cap_cases(): void
    {
        $cases = [
            ['LONG', '90', 'loss', '-50.00000000', '-25.00000000'],
            ['LONG', '110', 'win', '50.00000000', '25.00000000'],
            ['SHORT', '110', 'loss', '-50.00000000', '-25.00000000'],
            ['SHORT', '90', 'win', '50.00000000', '25.00000000'],
            ['LONG', '100', 'loss', '0.00000000', '0.00000000'],
            ['LONG', '50', 'loss', '-100.00000000', '-50.00000000'],
        ];

        foreach ($cases as [$direction, $finalPrice, $status, $return, $net]) {
            StrategyTradeResult::query()->delete();
            StrategyBacktestRun::query()->delete();
            StrategyDefinition::query()->update(['current_capital' => '500']);
            $trade = $this->trade($direction, '100', '5', SimulatedTrade::STATUS_EXPIRED, SimulatedTrade::EXIT_REASON_EXPIRED, null, '2026-06-03 02:00:00');
            $this->event($trade, TradeTrackingEvent::EVENT_ENTRY_TRIGGERED, '100', '0', '2026-06-03 00:00:00');
            $this->event($trade, TradeTrackingEvent::EVENT_SL_HIT, $direction === 'LONG' ? '95' : '105', '-25', '2026-06-03 00:30:00');
            $this->event($trade, TradeTrackingEvent::EVENT_TRADE_EXPIRED, $finalPrice, $return, '2026-06-03 02:00:00');

            $this->artisan('strategies:backtest', ['--strategy' => 'RECOVERY_HOLD_TP1', '--capital' => '500'])
                ->assertExitCode(0);

            $result = StrategyTradeResult::query()->firstOrFail();
            $this->assertSame('FINAL_EXIT', $result->exit_event_type);
            $this->assertSame($finalPrice.'.000000000000', $result->exit_price);
            $this->assertSame($status, $result->result_status);
            $this->assertSame($return, $result->return_percent);
            $this->assertSame($net, $result->gross_pnl);
            $this->assertSame('0.00000000', $result->fees);
            $this->assertSame($net, $result->net_pnl);
            $this->assertSame(bcadd($result->capital_before, $result->net_pnl, 8), $result->capital_after);
            $this->assertSame('1', (string) $result->sl_hit_first);
        }
    }

    public function test_recovery_hold_tp1_and_post_sl_tp1_keep_existing_winning_exit_events(): void
    {
        $normal = $this->trade('LONG', '100', '5');
        $this->event($normal, TradeTrackingEvent::EVENT_ENTRY_TRIGGERED, '100', '0', '2026-06-03 00:00:00');
        $this->event($normal, TradeTrackingEvent::EVENT_TP1_HIT, '102', '10', '2026-06-03 00:10:00');

        $postSl = $this->trade('LONG', '100', '5');
        $this->event($postSl, TradeTrackingEvent::EVENT_ENTRY_TRIGGERED, '100', '0', '2026-06-03 01:00:00');
        $this->event($postSl, TradeTrackingEvent::EVENT_SL_HIT, '95', '-25', '2026-06-03 01:10:00');
        $this->event($postSl, TradeTrackingEvent::EVENT_POST_SL_TP1_HIT, '102', '10', '2026-06-03 01:20:00');

        $this->artisan('strategies:backtest', ['--strategy' => 'RECOVERY_HOLD_TP1', '--capital' => '500'])->assertExitCode(0);

        $results = StrategyTradeResult::query()->orderBy('entry_time')->get();
        $this->assertSame(TradeTrackingEvent::EVENT_TP1_HIT, $results[0]->exit_event_type);
        $this->assertSame('5.00000000', $results[0]->net_pnl);
        $this->assertSame(TradeTrackingEvent::EVENT_POST_SL_TP1_HIT, $results[1]->exit_event_type);
        $this->assertSame('5.05000000', $results[1]->net_pnl);
        $this->assertSame('1', (string) $results[1]->post_sl_recovered);
    }

    public function test_sl_only_recovery_hold_does_not_fabricate_final_exit(): void
    {
        $trade = $this->trade('LONG', '100', '5', SimulatedTrade::STATUS_CLOSED_SL, SimulatedTrade::EXIT_REASON_SL, '95', '2026-06-03 00:30:00');
        $this->event($trade, TradeTrackingEvent::EVENT_ENTRY_TRIGGERED, '100', '0', '2026-06-03 00:00:00');
        $this->event($trade, TradeTrackingEvent::EVENT_SL_HIT, '95', '-25', '2026-06-03 00:30:00');

        $this->artisan('strategies:backtest', ['--strategy' => 'RECOVERY_HOLD_TP1', '--capital' => '500'])->assertExitCode(0);

        $result = StrategyTradeResult::query()->firstOrFail();
        $this->assertSame('open', $result->result_status);
        $this->assertNull($result->exit_event_type);
        $this->assertNull($result->exit_price);
        $this->assertSame('0.00000000', $result->net_pnl);
    }

    public function test_other_strategies_and_incremental_duplicate_behaviour_are_unchanged(): void
    {
        $trade = $this->trade('LONG', '100', '5', SimulatedTrade::STATUS_EXPIRED, SimulatedTrade::EXIT_REASON_EXPIRED, null, '2026-06-03 01:00:00');
        $this->event($trade, TradeTrackingEvent::EVENT_ENTRY_TRIGGERED, '100', '0', '2026-06-03 00:00:00');
        $this->event($trade, TradeTrackingEvent::EVENT_SL_HIT, '95', '-25', '2026-06-03 00:10:00');
        $this->event($trade, TradeTrackingEvent::EVENT_TRADE_EXPIRED, '90', '-50', '2026-06-03 01:00:00');

        $this->artisan('strategies:backtest', ['--capital' => '500'])->assertExitCode(0);
        $quick = StrategyTradeResult::query()->whereRelation('strategyDefinition', 'code', 'QUICK_SCALP_3_5')->firstOrFail();
        $tp2 = StrategyTradeResult::query()->whereRelation('strategyDefinition', 'code', 'TP2_RUNNER')->firstOrFail();
        $recovery = StrategyTradeResult::query()->whereRelation('strategyDefinition', 'code', 'RECOVERY_HOLD_TP1')->firstOrFail();

        $this->assertSame(TradeTrackingEvent::EVENT_SL_HIT, $quick->exit_event_type);
        $this->assertSame('-37.50000000', $quick->net_pnl);
        $this->assertSame(TradeTrackingEvent::EVENT_SL_HIT, $tp2->exit_event_type);
        $this->assertSame('-25.00000000', $tp2->net_pnl);
        $this->assertSame('FINAL_EXIT', $recovery->exit_event_type);
        $this->assertSame('-25.00000000', $recovery->net_pnl);

        $this->artisan('strategies:backtest', ['--incremental' => true])->assertExitCode(0);
        $this->assertSame(3, StrategyTradeResult::query()->count());
    }

    private function trade(string $direction, string $entryPrice, string $leverage, string $status = SimulatedTrade::STATUS_ACTIVE, ?string $exitReason = null, ?string $exitPrice = null, ?string $closedAt = null): SimulatedTrade
    {
        $signal = TradeSignal::query()->create(['symbol' => 'BTCUSDT', 'direction' => $direction, 'leverage' => $leverage, 'status' => 'active']);

        return SimulatedTrade::query()->create([
            'trade_signal_id' => $signal->id,
            'symbol' => 'BTCUSDT',
            'direction' => $direction,
            'leverage' => $leverage,
            'entry_price' => $entryPrice,
            'entry_triggered_at' => Carbon::parse('2026-06-03 00:00:00'),
            'stop_loss' => $direction === 'LONG' ? '95' : '105',
            'exit_price' => $exitPrice,
            'exit_reason' => $exitReason,
            'closed_at' => $closedAt ? Carbon::parse($closedAt) : null,
            'status' => $status,
            'tracking_until' => $closedAt ? Carbon::parse($closedAt) : null,
        ]);
    }

    private function event(SimulatedTrade $trade, string $type, string $price, string $pnl, string $time): void
    {
        TradeTrackingEvent::query()->create([
            'simulated_trade_id' => $trade->id,
            'trade_signal_id' => $trade->trade_signal_id,
            'event_type' => $type,
            'event_price' => $price,
            'actual_price_move_percent' => bcdiv($pnl, '5', 4),
            'leveraged_pnl_percent' => $pnl,
            'event_timestamp' => Carbon::parse($time),
        ]);
    }
}
