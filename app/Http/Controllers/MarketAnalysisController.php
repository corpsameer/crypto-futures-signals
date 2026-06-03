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

class MarketAnalysisController extends Controller
{
    /**
     * @var list<string>
     */
    private const AVAILABLE_MARKET_CONDITIONS = [
        MarketSnapshot::MARKET_CONDITION_BULLISH,
        MarketSnapshot::MARKET_CONDITION_BEARISH,
        MarketSnapshot::MARKET_CONDITION_SIDEWAYS,
        'unknown',
    ];

    /**
     * @var list<string>
     */
    private const AVAILABLE_DIRECTIONS = [
        TradeSignal::DIRECTION_LONG,
        TradeSignal::DIRECTION_SHORT,
    ];

    public function index(Request $request): View
    {
        $filters = [
            'market_condition' => strtolower(trim((string) $request->query('market_condition', ''))),
            'direction' => strtoupper(trim((string) $request->query('direction', ''))),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'trader_name' => trim((string) $request->query('trader_name', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];

        if (! in_array($filters['market_condition'], self::AVAILABLE_MARKET_CONDITIONS, true)) {
            $filters['market_condition'] = '';
        }

        if (! in_array($filters['direction'], self::AVAILABLE_DIRECTIONS, true)) {
            $filters['direction'] = '';
        }

        foreach (['date_from', 'date_to'] as $dateFilter) {
            if ($filters[$dateFilter] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateFilter])) {
                $filters[$dateFilter] = '';
            }
        }

        $trades = SimulatedTrade::query()
            ->with([
                'tradeSignal',
                'trackingEvents',
                'marketSnapshots',
            ])
            ->where('user_id', auth()->id())
            ->when($filters['direction'] !== '', fn ($query) => $query->where('direction', $filters['direction']))
            ->when($filters['symbol'] !== '', fn ($query) => $query->where('symbol', 'like', "%{$filters['symbol']}%"))
            ->when($filters['trader_name'] !== '', function ($query) use ($filters): void {
                $query->whereHas('tradeSignal', fn ($tradeSignalQuery) => $tradeSignalQuery->where('trader_name', 'like', "%{$filters['trader_name']}%"));
            })
            ->when($filters['date_from'] !== '', function ($query) use ($filters): void {
                $query->where(function ($dateQuery) use ($filters): void {
                    $dateQuery->whereDate('entry_triggered_at', '>=', Carbon::parse($filters['date_from'])->toDateString())
                        ->orWhere(function ($fallbackQuery) use ($filters): void {
                            $fallbackQuery->whereNull('entry_triggered_at')
                                ->whereDate('created_at', '>=', Carbon::parse($filters['date_from'])->toDateString());
                        });
                });
            })
            ->when($filters['date_to'] !== '', function ($query) use ($filters): void {
                $query->where(function ($dateQuery) use ($filters): void {
                    $dateQuery->whereDate('entry_triggered_at', '<=', Carbon::parse($filters['date_to'])->toDateString())
                        ->orWhere(function ($fallbackQuery) use ($filters): void {
                            $fallbackQuery->whereNull('entry_triggered_at')
                                ->whereDate('created_at', '<=', Carbon::parse($filters['date_to'])->toDateString());
                        });
                });
            })
            ->orderByDesc('entry_triggered_at')
            ->orderByDesc('created_at')
            ->get()
            ->map(function (SimulatedTrade $trade): SimulatedTrade {
                $trade->analysis_market_condition = $this->getTradeMarketCondition($trade);

                return $trade;
            });

        if ($filters['market_condition'] !== '') {
            $trades = $trades
                ->filter(fn (SimulatedTrade $trade): bool => $trade->analysis_market_condition === $filters['market_condition'])
                ->values();
        }

        $marketAnalysis = $trades
            ->groupBy('analysis_market_condition')
            ->map(fn (Collection $conditionTrades, string $marketCondition): array => $this->buildMarketMetrics($conditionTrades, $marketCondition))
            ->sort(function (array $first, array $second): int {
                return [
                    $second['total_trades'],
                    $second['tp1_hit_rate'] ?? -1,
                    $second['average_max_gain'] ?? -999999,
                ] <=> [
                    $first['total_trades'],
                    $first['tp1_hit_rate'] ?? -1,
                    $first['average_max_gain'] ?? -999999,
                ];
            })
            ->values();

        return view('market_analysis.index', [
            'marketAnalysis' => $marketAnalysis,
            'filters' => $filters,
            'availableMarketConditions' => self::AVAILABLE_MARKET_CONDITIONS,
            'availableDirections' => self::AVAILABLE_DIRECTIONS,
        ]);
    }

    private function buildMarketMetrics(Collection $trades, string $marketCondition): array
    {
        $totalTrades = $trades->count();

        $tp1HitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_TP1_HIT))->count();
        $tp2HitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_TP2_HIT))->count();
        $tp3HitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_TP3_HIT))->count();
        $tp4HitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_TP4_HIT))->count();
        $slHitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_SL_HIT))->count();
        $gain35HitCount = $trades->filter(fn (SimulatedTrade $trade): bool => $this->tradeHasEvent($trade, TradeTrackingEvent::EVENT_GAIN_3_5_PERCENT))->count();

        $maxGains = $trades->map(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxGain($trade))->filter(fn ($value): bool => $value !== null)->values();
        $maxLosses = $trades->map(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxLoss($trade))->filter(fn ($value): bool => $value !== null)->values();

        return [
            'market_condition' => $marketCondition,
            'total_trades' => $totalTrades,
            'tp1_hit_count' => $tp1HitCount,
            'tp1_hit_rate' => $this->percentage($tp1HitCount, $totalTrades),
            'tp2_hit_count' => $tp2HitCount,
            'tp2_hit_rate' => $this->percentage($tp2HitCount, $totalTrades),
            'tp3_hit_count' => $tp3HitCount,
            'tp3_hit_rate' => $this->percentage($tp3HitCount, $totalTrades),
            'tp4_hit_count' => $tp4HitCount,
            'tp4_hit_rate' => $this->percentage($tp4HitCount, $totalTrades),
            'sl_hit_count' => $slHitCount,
            'sl_hit_rate' => $this->percentage($slHitCount, $totalTrades),
            'gain_3_5_hit_count' => $gain35HitCount,
            'gain_3_5_hit_rate' => $this->percentage($gain35HitCount, $totalTrades),
            'average_max_gain' => $maxGains->isEmpty() ? null : $maxGains->avg(),
            'average_max_loss' => $maxLosses->isEmpty() ? null : $maxLosses->avg(),
            'best_direction' => $this->getBestDirection($trades),
            'worst_direction' => $this->getWorstDirection($trades),
        ];
    }

    private function percentage($numerator, $denominator): ?float
    {
        if ((float) $denominator <= 0.0) {
            return null;
        }

        return ((float) $numerator / (float) $denominator) * 100;
    }

    private function formatPercent($value): string
    {
        return $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
    }

    private function tradeHasEvent(SimulatedTrade $trade, string $eventType): bool
    {
        return $trade->trackingEvents->contains('event_type', $eventType);
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

    private function getTradeMarketCondition(SimulatedTrade $trade): string
    {
        $entrySnapshot = $trade->marketSnapshots
            ->where('snapshot_type', MarketSnapshot::SNAPSHOT_ENTRY_TRIGGERED)
            ->filter(fn (MarketSnapshot $snapshot): bool => $snapshot->market_condition !== null)
            ->sortByDesc(fn (MarketSnapshot $snapshot) => $snapshot->captured_at ?? $snapshot->snapshot_at ?? $snapshot->created_at)
            ->first();

        if ($entrySnapshot?->market_condition) {
            return in_array($entrySnapshot->market_condition, self::AVAILABLE_MARKET_CONDITIONS, true)
                ? $entrySnapshot->market_condition
                : 'unknown';
        }

        $latestSnapshot = $trade->marketSnapshots
            ->filter(fn (MarketSnapshot $snapshot): bool => $snapshot->market_condition !== null)
            ->sortByDesc(fn (MarketSnapshot $snapshot) => $snapshot->captured_at ?? $snapshot->snapshot_at ?? $snapshot->created_at)
            ->first();

        if ($latestSnapshot?->market_condition) {
            return in_array($latestSnapshot->market_condition, self::AVAILABLE_MARKET_CONDITIONS, true)
                ? $latestSnapshot->market_condition
                : 'unknown';
        }

        return 'unknown';
    }

    private function getBestDirection(Collection $trades): ?array
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

    private function getWorstDirection(Collection $trades): ?array
    {
        return $trades
            ->filter(fn (SimulatedTrade $trade): bool => in_array($trade->direction, self::AVAILABLE_DIRECTIONS, true) && $this->getTradeMaxLoss($trade) !== null)
            ->groupBy('direction')
            ->map(function (Collection $directionTrades, string $direction): array {
                return [
                    'direction' => $direction,
                    'average_max_loss' => $directionTrades->avg(fn (SimulatedTrade $trade): ?float => $this->getTradeMaxLoss($trade)),
                    'trade_count' => $directionTrades->count(),
                ];
            })
            ->sortBy('average_max_loss')
            ->first();
    }
}
