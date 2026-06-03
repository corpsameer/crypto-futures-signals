<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use App\Models\SimulatedTrade;
use App\Models\TradeSignal;
use App\Models\TradeTrackingEvent;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class TraderPerformanceController extends Controller
{
    /**
     * @var list<string>
     */
    private const AVAILABLE_DIRECTIONS = [
        TradeSignal::DIRECTION_LONG,
        TradeSignal::DIRECTION_SHORT,
    ];

    /**
     * @var list<string>
     */
    private const TRIGGERED_SIGNAL_STATUSES = [
        TradeSignal::STATUS_ACTIVE,
        TradeSignal::STATUS_ENTRY_TRIGGERED,
        TradeSignal::STATUS_CLOSED_SL,
        TradeSignal::STATUS_CLOSED_TP,
        TradeSignal::STATUS_TRACKING_AFTER_SL,
        TradeSignal::STATUS_EXPIRED,
        TradeSignal::STATUS_COMPLETED,
    ];

    /**
     * @var list<string>
     */
    private const POST_SL_TP_EVENTS = [
        TradeTrackingEvent::EVENT_POST_SL_TP1_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP2_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP3_HIT,
        TradeTrackingEvent::EVENT_POST_SL_TP4_HIT,
    ];

    public function index(Request $request): View
    {
        $filters = [
            'trader_name' => trim((string) $request->query('trader_name', '')),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'direction' => strtoupper(trim((string) $request->query('direction', ''))),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];

        foreach (['date_from', 'date_to'] as $dateFilter) {
            if ($filters[$dateFilter] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateFilter])) {
                $filters[$dateFilter] = '';
            }
        }

        if (! in_array($filters['direction'], self::AVAILABLE_DIRECTIONS, true)) {
            $filters['direction'] = '';
        }

        $tradeSignals = TradeSignal::query()
            ->with([
                'simulatedTrades' => function ($query): void {
                    $query->where('user_id', auth()->id())
                        ->with([
                            'trackingEvents',
                            'marketSnapshots',
                        ]);
                },
            ])
            ->where('user_id', auth()->id())
            ->when($filters['trader_name'] !== '', fn ($query) => $query->where('trader_name', 'like', "%{$filters['trader_name']}%"))
            ->when($filters['symbol'] !== '', fn ($query) => $query->where('symbol', 'like', "%{$filters['symbol']}%"))
            ->when($filters['direction'] !== '', fn ($query) => $query->where('direction', $filters['direction']))
            ->when($filters['date_from'] !== '', function ($query) use ($filters): void {
                $query->where('created_at', '>=', Carbon::parse($filters['date_from'])->startOfDay());
            })
            ->when($filters['date_to'] !== '', function ($query) use ($filters): void {
                $query->where('created_at', '<=', Carbon::parse($filters['date_to'])->endOfDay());
            })
            ->orderByDesc('created_at')
            ->get();

        $traderPerformance = $tradeSignals
            ->groupBy(fn (TradeSignal $tradeSignal): string => $this->formatTraderName($tradeSignal->trader_name))
            ->map(fn (Collection $signals, string $traderName): array => $this->buildTraderMetrics($signals, $traderName))
            ->sort(function (array $first, array $second): int {
                return [
                    $second['entry_trigger_rate'] ?? -1,
                    $second['tp1_hit_rate'] ?? -1,
                    $second['average_max_gain'] ?? -999999,
                ] <=> [
                    $first['entry_trigger_rate'] ?? -1,
                    $first['tp1_hit_rate'] ?? -1,
                    $first['average_max_gain'] ?? -999999,
                ];
            })
            ->values();

        return view('traders.index', [
            'traderPerformance' => $traderPerformance,
            'filters' => $filters,
            'availableDirections' => self::AVAILABLE_DIRECTIONS,
        ]);
    }

    private function buildTraderMetrics(Collection $signals, string $traderName): array
    {
        $totalSignals = $signals->count();
        $entryTriggeredCount = $signals->filter(function (TradeSignal $signal): bool {
            return in_array($signal->status, self::TRIGGERED_SIGNAL_STATUSES, true)
                || $signal->simulatedTrades->isNotEmpty();
        })->count();

        $trades = $signals->flatMap(fn (TradeSignal $signal) => $signal->simulatedTrades)->unique('id')->values();
        $triggeredTradeCount = $trades->count();

        $tp1HitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_TP1_HIT);
        $tp2HitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_TP2_HIT);
        $tp3HitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_TP3_HIT);
        $tp4HitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_TP4_HIT);
        $slHitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_SL_HIT);
        $gain35HitCount = $this->countTradesWithEvent($trades, TradeTrackingEvent::EVENT_GAIN_3_5_PERCENT);

        $maxGains = $trades->map(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxGain($trade))->filter(fn ($value): bool => $value !== null)->values();
        $maxLosses = $trades->map(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxLoss($trade))->filter(fn ($value): bool => $value !== null)->values();

        $slTrades = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_SL_HIT))->values();
        $recoveredPostSlCount = $slTrades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasAnyEvent($trade, self::POST_SL_TP_EVENTS))->count();

        return [
            'trader' => $traderName,
            'is_unknown' => $traderName === 'Unknown',
            'total_signals' => $totalSignals,
            'entry_triggered_count' => $entryTriggeredCount,
            'entry_trigger_rate' => $this->percentage($entryTriggeredCount, $totalSignals),
            'triggered_trade_count' => $triggeredTradeCount,
            'tp1_hit_count' => $tp1HitCount,
            'tp1_hit_rate' => $this->percentage($tp1HitCount, $triggeredTradeCount),
            'tp2_hit_count' => $tp2HitCount,
            'tp2_hit_rate' => $this->percentage($tp2HitCount, $triggeredTradeCount),
            'tp3_hit_count' => $tp3HitCount,
            'tp3_hit_rate' => $this->percentage($tp3HitCount, $triggeredTradeCount),
            'tp4_hit_count' => $tp4HitCount,
            'tp4_hit_rate' => $this->percentage($tp4HitCount, $triggeredTradeCount),
            'sl_hit_count' => $slHitCount,
            'sl_hit_rate' => $this->percentage($slHitCount, $triggeredTradeCount),
            'gain_3_5_hit_count' => $gain35HitCount,
            'gain_3_5_hit_rate' => $this->percentage($gain35HitCount, $triggeredTradeCount),
            'average_max_gain' => $maxGains->isEmpty() ? null : $maxGains->avg(),
            'average_max_loss' => $maxLosses->isEmpty() ? null : $maxLosses->avg(),
            'sl_trade_count' => $slTrades->count(),
            'recovered_post_sl_count' => $recoveredPostSlCount,
            'post_sl_recovery_rate' => $this->percentage($recoveredPostSlCount, $slTrades->count()),
            'best_direction' => $this->bestDirection($trades),
            'best_market_condition' => $this->bestMarketCondition($trades),
        ];
    }

    private function countTradesWithEvent(Collection $trades, string $eventType): int
    {
        return $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, $eventType))->count();
    }

    private function bestDirection(Collection $trades): ?array
    {
        return $trades
            ->filter(fn (SimulatedTrade $trade): bool => in_array($trade->direction, self::AVAILABLE_DIRECTIONS, true) && $this->getTradeMaxGain($trade) !== null)
            ->groupBy('direction')
            ->map(function (Collection $directionTrades, string $direction): array {
                return [
                    'direction' => $direction,
                    'average_max_gain' => $directionTrades->avg(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxGain($trade)),
                    'trade_count' => $directionTrades->count(),
                ];
            })
            ->sortByDesc('average_max_gain')
            ->first();
    }

    private function bestMarketCondition(Collection $trades): ?array
    {
        return $trades
            ->map(function (SimulatedTrade $trade): ?array {
                $marketCondition = $this->getTradeMarketCondition($trade);
                $maxGain = $this->getTradeMaxGain($trade);

                if ($marketCondition === null || $maxGain === null) {
                    return null;
                }

                return [
                    'market_condition' => $marketCondition,
                    'max_gain' => $maxGain,
                ];
            })
            ->filter()
            ->groupBy('market_condition')
            ->map(function (Collection $conditionTrades, string $marketCondition): array {
                return [
                    'market_condition' => $marketCondition,
                    'average_max_gain' => $conditionTrades->avg('max_gain'),
                    'trade_count' => $conditionTrades->count(),
                ];
            })
            ->sortByDesc('average_max_gain')
            ->first();
    }

    private function percentage($numerator, $denominator): ?float
    {
        if ((float) $denominator <= 0.0) {
            return null;
        }

        return ((float) $numerator / (float) $denominator) * 100;
    }

    private function formatTraderName($name): string
    {
        $formattedName = trim((string) $name);

        return $formattedName === '' ? 'Unknown' : $formattedName;
    }

    private function tradeHasEvent(SimulatedTrade $trade, string $eventType): bool
    {
        return $trade->trackingEvents->contains('event_type', $eventType);
    }

    private function tradeHasAnyEvent(SimulatedTrade $trade, array $eventTypes): bool
    {
        return $trade->trackingEvents->contains(fn (TradeTrackingEvent $event): bool => in_array($event->event_type, $eventTypes, true));
    }

    private function getTradeMaxGain(SimulatedTrade $trade): ?float
    {
        $value = $trade->max_gain_percent ?? $trade->max_leveraged_pnl_percent;

        return $value === null ? null : (float) $value;
    }

    private function getTradeMaxLoss(SimulatedTrade $trade): ?float
    {
        $value = $trade->max_loss_percent ?? $trade->min_leveraged_pnl_percent;

        return $value === null ? null : (float) $value;
    }

    private function getTradeMarketCondition(SimulatedTrade $trade): ?string
    {
        $validConditions = [
            MarketSnapshot::MARKET_CONDITION_BULLISH,
            MarketSnapshot::MARKET_CONDITION_BEARISH,
            MarketSnapshot::MARKET_CONDITION_SIDEWAYS,
        ];

        $entrySnapshot = $trade->marketSnapshots
            ->where('snapshot_type', MarketSnapshot::SNAPSHOT_ENTRY_TRIGGERED)
            ->whereIn('market_condition', $validConditions)
            ->sortByDesc(fn (MarketSnapshot $snapshot) => $snapshot->snapshot_at ?? $snapshot->captured_at ?? $snapshot->created_at)
            ->first();

        if ($entrySnapshot?->market_condition) {
            return $entrySnapshot->market_condition;
        }

        $latestSnapshot = $trade->marketSnapshots
            ->whereIn('market_condition', $validConditions)
            ->sortByDesc(fn (MarketSnapshot $snapshot) => $snapshot->snapshot_at ?? $snapshot->captured_at ?? $snapshot->created_at)
            ->first();

        return $latestSnapshot?->market_condition;
    }
}
