<?php

namespace App\Http\Controllers;

use App\Models\StrategyBacktestRun;
use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use Illuminate\View\View;

class StrategyBacktestController extends Controller
{
    public function index(): View
    {
        $backtestRuns = StrategyBacktestRun::query()
            ->select('strategy_backtest_runs.*')
            ->withCount('tradeResults as result_rows_count')
            ->selectSub(function ($query): void {
                $query->from('strategy_trade_results')
                    ->selectRaw('COUNT(DISTINCT strategy_definition_id)')
                    ->whereColumn('strategy_trade_results.strategy_backtest_run_id', 'strategy_backtest_runs.id');
            }, 'strategies_represented_count')
            ->selectSub(function ($query): void {
                $query->from('strategy_trade_results')
                    ->selectRaw('COUNT(DISTINCT simulated_trade_id)')
                    ->whereColumn('strategy_trade_results.strategy_backtest_run_id', 'strategy_backtest_runs.id');
            }, 'processed_trades_count')
            ->selectSub(function ($query): void {
                $query->from('strategy_trade_results')
                    ->selectRaw('COALESCE(SUM(net_pnl), 0)')
                    ->whereColumn('strategy_trade_results.strategy_backtest_run_id', 'strategy_backtest_runs.id');
            }, 'total_net_pnl')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('strategy-backtests.index', [
            'backtestRuns' => $backtestRuns,
        ]);
    }

    public function show(StrategyBacktestRun $backtestRun): View
    {
        $runId = $backtestRun->getKey();
        $startingCapital = $backtestRun->starting_capital === null ? null : (float) $backtestRun->starting_capital;

        $overallRow = StrategyTradeResult::query()
            ->where('strategy_backtest_run_id', $runId)
            ->selectRaw('COUNT(*) as result_rows')
            ->selectRaw('COUNT(DISTINCT strategy_definition_id) as strategies_represented')
            ->selectRaw('COUNT(DISTINCT simulated_trade_id) as processed_trades')
            ->selectRaw("SUM(CASE WHEN result_status = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN result_status = 'loss' THEN 1 ELSE 0 END) as losses")
            ->selectRaw("SUM(CASE WHEN result_status = 'open' THEN 1 ELSE 0 END) as open_results")
            ->selectRaw("SUM(CASE WHEN result_status = 'skipped' THEN 1 ELSE 0 END) as skipped_results")
            ->selectRaw('COALESCE(SUM(allocated_capital), 0) as total_allocated_capital')
            ->selectRaw('COALESCE(SUM(gross_pnl), 0) as total_gross_pnl')
            ->selectRaw('COALESCE(SUM(fees), 0) as total_fees')
            ->selectRaw('COALESCE(SUM(net_pnl), 0) as total_net_pnl')
            ->first();

        $overallSummary = [
            'strategies_represented' => (int) ($overallRow->strategies_represented ?? 0),
            'processed_trades' => (int) ($overallRow->processed_trades ?? 0),
            'result_rows' => (int) ($overallRow->result_rows ?? 0),
            'wins' => (int) ($overallRow->wins ?? 0),
            'losses' => (int) ($overallRow->losses ?? 0),
            'open' => (int) ($overallRow->open_results ?? 0),
            'skipped' => (int) ($overallRow->skipped_results ?? 0),
            'total_allocated_capital' => (float) ($overallRow->total_allocated_capital ?? 0),
            'total_gross_pnl' => (float) ($overallRow->total_gross_pnl ?? 0),
            'total_fees' => (float) ($overallRow->total_fees ?? 0),
            'total_net_pnl' => (float) ($overallRow->total_net_pnl ?? 0),
        ];

        $strategyRows = StrategyTradeResult::query()
            ->where('strategy_backtest_run_id', $runId)
            ->select('strategy_definition_id')
            ->selectRaw('COUNT(*) as result_rows')
            ->selectRaw('COUNT(DISTINCT simulated_trade_id) as processed_trades')
            ->selectRaw("SUM(CASE WHEN result_status = 'win' THEN 1 ELSE 0 END) as wins")
            ->selectRaw("SUM(CASE WHEN result_status = 'loss' THEN 1 ELSE 0 END) as losses")
            ->selectRaw("SUM(CASE WHEN result_status = 'open' THEN 1 ELSE 0 END) as open_results")
            ->selectRaw("SUM(CASE WHEN result_status = 'skipped' THEN 1 ELSE 0 END) as skipped_results")
            ->selectRaw('COALESCE(SUM(allocated_capital), 0) as total_allocated_capital')
            ->selectRaw('COALESCE(SUM(net_pnl), 0) as net_pnl')
            ->selectRaw('SUM(CASE WHEN sl_hit_first = 1 THEN 1 ELSE 0 END) as sl_hit_first_count')
            ->selectRaw('SUM(CASE WHEN post_sl_recovered = 1 THEN 1 ELSE 0 END) as recovered_count')
            ->groupBy('strategy_definition_id')
            ->orderBy('strategy_definition_id')
            ->get();

        $strategies = StrategyDefinition::query()
            ->whereIn('id', $strategyRows->pluck('strategy_definition_id')->filter()->all())
            ->get()
            ->keyBy('id');

        $strategySummaries = $strategyRows->map(function ($row) use ($strategies, $startingCapital): array {
            $wins = (int) ($row->wins ?? 0);
            $losses = (int) ($row->losses ?? 0);
            $decided = $wins + $losses;
            $netPnl = (float) ($row->net_pnl ?? 0);
            $slHitFirst = (int) ($row->sl_hit_first_count ?? 0);
            $recovered = (int) ($row->recovered_count ?? 0);

            return [
                'strategy_definition_id' => (int) $row->strategy_definition_id,
                'strategy' => $strategies->get($row->strategy_definition_id),
                'result_rows' => (int) ($row->result_rows ?? 0),
                'processed_trades' => (int) ($row->processed_trades ?? 0),
                'wins' => $wins,
                'losses' => $losses,
                'open' => (int) ($row->open_results ?? 0),
                'skipped' => (int) ($row->skipped_results ?? 0),
                'win_rate' => $decided > 0 ? ($wins / $decided) * 100 : null,
                'starting_capital' => $startingCapital,
                'analytical_ending_capital' => $startingCapital === null ? null : $startingCapital + $netPnl,
                'net_pnl' => $netPnl,
                'return_percent' => $startingCapital !== null && $startingCapital > 0 ? ($netPnl / $startingCapital) * 100 : null,
                'total_allocated_capital' => (float) ($row->total_allocated_capital ?? 0),
                'sl_hit_first_count' => $slHitFirst,
                'recovered_count' => $recovered,
                'recovery_rate' => $slHitFirst > 0 ? ($recovered / $slHitFirst) * 100 : null,
            ];
        });

        $results = StrategyTradeResult::query()
            ->with('strategyDefinition:id,name,code')
            ->where('strategy_backtest_run_id', $runId)
            ->orderByRaw('entry_time IS NULL')
            ->orderBy('entry_time')
            ->orderBy('strategy_definition_id')
            ->orderBy('simulated_trade_id')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString();

        return view('strategy-backtests.show', [
            'backtestRun' => $backtestRun,
            'overallSummary' => $overallSummary,
            'strategySummaries' => $strategySummaries,
            'results' => $results,
        ]);
    }
}
