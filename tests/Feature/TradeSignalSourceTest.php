<?php

namespace Tests\Feature;

use App\Models\TradeSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TradeSignalSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_telegram_compatible_trade_signal_defaults_to_telegram_source(): void
    {
        $tradeSignal = TradeSignal::query()->create([
            'symbol' => 'BTCUSDT',
            'direction' => TradeSignal::DIRECTION_LONG,
            'entry_min' => '100.00',
            'entry_max' => '100.00',
            'stop_loss' => '95.00',
            'tp1' => '105.00',
        ])->refresh();

        $this->assertSame(TradeSignal::SOURCE_TELEGRAM, $tradeSignal->signal_source);
        $this->assertNull($tradeSignal->source_image_path);
        $this->assertSame('BTCUSDT', $tradeSignal->symbol);
        $this->assertSame(TradeSignal::DIRECTION_LONG, $tradeSignal->direction);
    }

    public function test_coindcx_trade_signal_source_and_image_path_can_be_stored(): void
    {
        $tradeSignal = TradeSignal::query()->create([
            'signal_source' => TradeSignal::SOURCE_COINDCX,
            'source_image_path' => 'coindcx/source-images/example.png',
            'symbol' => 'ETHUSDT',
            'direction' => TradeSignal::DIRECTION_SHORT,
            'entry_min' => '200.00',
            'entry_max' => '210.00',
            'stop_loss' => '220.00',
            'tp1' => '190.00',
        ])->refresh();

        $this->assertSame(TradeSignal::SOURCE_COINDCX, $tradeSignal->signal_source);
        $this->assertSame('coindcx/source-images/example.png', $tradeSignal->source_image_path);
    }
}
