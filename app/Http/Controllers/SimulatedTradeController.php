<?php

namespace App\Http\Controllers;

use App\Models\MarketSnapshot;
use App\Models\SimulatedTrade;
use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use App\Models\TradeSignal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SimulatedTradeController extends Controller
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
    private const AVAILABLE_STATUSES = [
        SimulatedTrade::STATUS_ACTIVE,
        SimulatedTrade::STATUS_CLOSED_SL,
        SimulatedTrade::STATUS_CLOSED_TP,
        SimulatedTrade::STATUS_TRACKING_AFTER_SL,
        SimulatedTrade::STATUS_EXPIRED,
        SimulatedTrade::STATUS_COMPLETED,
    ];

    /**
     * @var list<string>
     */
    private const AVAILABLE_MARKET_CONDITIONS = [
        MarketSnapshot::MARKET_CONDITION_BULLISH,
        MarketSnapshot::MARKET_CONDITION_BEARISH,
        MarketSnapshot::MARKET_CONDITION_SIDEWAYS,
    ];

    /**
     * @var array<string, string>
     */
    private const LOCKED_STRATEGY_LABELS = [
        'QUICK_SCALP_3_5' => 'Quick Scalp 3.5%',
        'TP2_RUNNER' => 'TP2 Runner',
        'RECOVERY_HOLD_TP1' => 'Recovery Hold TP1',
    ];

    public function index(Request $request): View
    {
        $filters = [
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'trader_name' => trim((string) $request->query('trader_name', '')),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'direction' => strtoupper(trim((string) $request->query('direction', ''))),
            'status' => trim((string) $request->query('status', '')),
            'market_condition' => strtolower(trim((string) $request->query('market_condition', ''))),
        ];

        foreach (['date_from', 'date_to'] as $dateFilter) {
            if ($filters[$dateFilter] !== '' && ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $filters[$dateFilter])) {
                $filters[$dateFilter] = '';
            }
        }

        if (! in_array($filters['direction'], self::AVAILABLE_DIRECTIONS, true)) {
            $filters['direction'] = '';
        }

        if (! in_array($filters['status'], self::AVAILABLE_STATUSES, true)) {
            $filters['status'] = '';
        }

        if (! in_array($filters['market_condition'], self::AVAILABLE_MARKET_CONDITIONS, true)) {
            $filters['market_condition'] = '';
        }

        $query = SimulatedTrade::query()
            ->with([
                'tradeSignal.pastedSignal',
                'trackingEvents',
                'marketSnapshots',
            ])
            ->where('user_id', auth()->id())
            ->when($filters['date_from'] !== '', function ($query) use ($filters): void {
                $dateFrom = Carbon::parse($filters['date_from'])->startOfDay();

                $query->where(function ($query) use ($dateFrom): void {
                    $query->where(function ($query) use ($dateFrom): void {
                        $query->whereNotNull('entry_triggered_at')
                            ->where('entry_triggered_at', '>=', $dateFrom);
                    })->orWhere(function ($query) use ($dateFrom): void {
                        $query->whereNull('entry_triggered_at')
                            ->where('created_at', '>=', $dateFrom);
                    });
                });
            })
            ->when($filters['date_to'] !== '', function ($query) use ($filters): void {
                $dateTo = Carbon::parse($filters['date_to'])->endOfDay();

                $query->where(function ($query) use ($dateTo): void {
                    $query->where(function ($query) use ($dateTo): void {
                        $query->whereNotNull('entry_triggered_at')
                            ->where('entry_triggered_at', '<=', $dateTo);
                    })->orWhere(function ($query) use ($dateTo): void {
                        $query->whereNull('entry_triggered_at')
                            ->where('created_at', '<=', $dateTo);
                    });
                });
            })
            ->when($filters['trader_name'] !== '', function ($query) use ($filters): void {
                $query->whereHas('tradeSignal', fn ($query) => $query->where('trader_name', 'like', "%{$filters['trader_name']}%"));
            })
            ->when($filters['symbol'] !== '', fn ($query) => $query->where('symbol', 'like', "%{$filters['symbol']}%"))
            ->when($filters['direction'] !== '', fn ($query) => $query->where('direction', $filters['direction']))
            ->when($filters['status'] !== '', fn ($query) => $query->where('status', $filters['status']))
            ->when($filters['market_condition'] !== '', function ($query) use ($filters): void {
                $query->whereHas('marketSnapshots', fn ($query) => $query->where('market_condition', $filters['market_condition']));
            })
            ->orderByRaw('entry_triggered_at IS NULL')
            ->orderByDesc('entry_triggered_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $trades = $query->paginate(15)->withQueryString();
        $visibleTradeIds = $trades->getCollection()->pluck('id')->filter()->values();

        $strategyDefinitions = StrategyDefinition::query()
            ->whereIn('code', array_keys(self::LOCKED_STRATEGY_LABELS))
            ->get()
            ->keyBy('code');

        $strategyResultsByTrade = collect();

        if ($visibleTradeIds->isNotEmpty() && $strategyDefinitions->isNotEmpty()) {
            $latestResultIds = StrategyTradeResult::query()
                ->selectRaw('MAX(id) as id')
                ->whereIn('simulated_trade_id', $visibleTradeIds)
                ->whereIn('strategy_definition_id', $strategyDefinitions->pluck('id'))
                ->groupBy('strategy_definition_id', 'simulated_trade_id');

            $strategyResultsByTrade = StrategyTradeResult::query()
                ->joinSub($latestResultIds, 'latest_strategy_results', function ($join): void {
                    $join->on('strategy_trade_results.id', '=', 'latest_strategy_results.id');
                })
                ->join('strategy_definitions', 'strategy_trade_results.strategy_definition_id', '=', 'strategy_definitions.id')
                ->select('strategy_trade_results.*', 'strategy_definitions.code as strategy_code')
                ->get()
                ->groupBy('simulated_trade_id')
                ->map(fn ($results) => $results->keyBy('strategy_code'));
        }

        return view('trades.index', [
            'trades' => $trades,
            'filters' => $filters,
            'availableDirections' => self::AVAILABLE_DIRECTIONS,
            'availableStatuses' => self::AVAILABLE_STATUSES,
            'availableMarketConditions' => self::AVAILABLE_MARKET_CONDITIONS,
            'lockedStrategies' => self::LOCKED_STRATEGY_LABELS,
            'strategyDefinitions' => $strategyDefinitions,
            'strategyResultsByTrade' => $strategyResultsByTrade,
        ]);
    }
}
