<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use App\Models\SimulatedTrade;
use App\Models\TradeSignal;
use App\Models\TradeTrackingEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return $this->index();
    }

    public function index(): View
    {
        $userId = auth()->id();

        $tradeSignalQuery = TradeSignal::query()->where('user_id', $userId);
        $simulatedTradeQuery = SimulatedTrade::query()->where('user_id', $userId);

        $summary = [
            'total_signals' => (clone $tradeSignalQuery)->count(),
            'pending_entry' => (clone $tradeSignalQuery)->where('status', TradeSignal::STATUS_PENDING_ENTRY)->count(),
            'entry_triggered' => (clone $tradeSignalQuery)
                ->whereIn('status', [
                    TradeSignal::STATUS_ACTIVE,
                    TradeSignal::STATUS_ENTRY_TRIGGERED,
                    TradeSignal::STATUS_CLOSED_SL,
                    TradeSignal::STATUS_CLOSED_TP,
                    TradeSignal::STATUS_TRACKING_AFTER_SL,
                    TradeSignal::STATUS_EXPIRED,
                    TradeSignal::STATUS_COMPLETED,
                ])
                ->count(),
            'entry_missed' => (clone $tradeSignalQuery)->where('status', TradeSignal::STATUS_ENTRY_MISSED)->count(),
            'active_trades' => (clone $simulatedTradeQuery)->where('status', SimulatedTrade::STATUS_ACTIVE)->count(),
            'sl_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_SL_HIT),
            'tp1_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_TP1_HIT),
            'tp2_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_TP2_HIT),
            'tp3_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_TP3_HIT),
            'tp4_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_TP4_HIT),
            'gain_3_5_hit' => $this->countTradesWithEvent(TradeTrackingEvent::EVENT_GAIN_3_5_PERCENT),
            'average_max_gain' => (clone $simulatedTradeQuery)->whereNotNull('max_gain_percent')->avg('max_gain_percent'),
            'average_max_loss' => (clone $simulatedTradeQuery)->whereNotNull('max_loss_percent')->avg('max_loss_percent'),
            'best_trader' => $this->bestTrader($userId),
            'worst_trader' => $this->worstTrader($userId),
            'best_market_condition' => $this->bestMarketCondition($userId),
        ];

        return view('dashboard', [
            'summary' => $summary,
        ]);
    }

    private function countTradesWithEvent(string $eventType): int
    {
        return TradeTrackingEvent::query()
            ->where('event_type', $eventType)
            ->whereHas('simulatedTrade', function ($query): void {
                $query->where('user_id', auth()->id());
            })
            ->distinct('simulated_trade_id')
            ->count('simulated_trade_id');
    }

    private function bestTrader(int|string|null $userId): ?object
    {
        return SimulatedTrade::query()
            ->join('trade_signals', 'simulated_trades.trade_signal_id', '=', 'trade_signals.id')
            ->where('simulated_trades.user_id', $userId)
            ->whereNotNull('simulated_trades.max_gain_percent')
            ->whereNotNull('trade_signals.trader_name')
            ->where('trade_signals.trader_name', '!=', '')
            ->select([
                'trade_signals.trader_name',
                DB::raw('AVG(simulated_trades.max_gain_percent) as avg_max_gain_percent'),
                DB::raw('COUNT(simulated_trades.id) as trade_count'),
            ])
            ->groupBy('trade_signals.trader_name')
            ->orderByDesc('avg_max_gain_percent')
            ->first();
    }

    private function worstTrader(int|string|null $userId): ?object
    {
        return SimulatedTrade::query()
            ->join('trade_signals', 'simulated_trades.trade_signal_id', '=', 'trade_signals.id')
            ->where('simulated_trades.user_id', $userId)
            ->whereNotNull('simulated_trades.max_loss_percent')
            ->whereNotNull('trade_signals.trader_name')
            ->where('trade_signals.trader_name', '!=', '')
            ->select([
                'trade_signals.trader_name',
                DB::raw('AVG(simulated_trades.max_loss_percent) as avg_max_loss_percent'),
                DB::raw('COUNT(simulated_trades.id) as trade_count'),
            ])
            ->groupBy('trade_signals.trader_name')
            ->orderBy('avg_max_loss_percent')
            ->first();
    }

    private function bestMarketCondition(int|string|null $userId): ?object
    {
        return SimulatedTrade::query()
            ->join('market_snapshots', 'simulated_trades.id', '=', 'market_snapshots.simulated_trade_id')
            ->where('simulated_trades.user_id', $userId)
            ->where('market_snapshots.snapshot_type', MarketSnapshot::SNAPSHOT_ENTRY_TRIGGERED)
            ->whereIn('market_snapshots.market_condition', [
                MarketSnapshot::MARKET_CONDITION_BULLISH,
                MarketSnapshot::MARKET_CONDITION_BEARISH,
                MarketSnapshot::MARKET_CONDITION_SIDEWAYS,
            ])
            ->whereNotNull('simulated_trades.max_gain_percent')
            ->select([
                'market_snapshots.market_condition',
                DB::raw('AVG(simulated_trades.max_gain_percent) as avg_max_gain_percent'),
                DB::raw('COUNT(DISTINCT simulated_trades.id) as trade_count'),
            ])
            ->groupBy('market_snapshots.market_condition')
            ->orderByDesc('avg_max_gain_percent')
            ->first();
    }
}
