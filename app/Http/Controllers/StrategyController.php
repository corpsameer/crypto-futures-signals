<?php

namespace App\Http\Controllers;

use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StrategyController extends Controller
{
    public function index(): View
    {
        $strategies = StrategyDefinition::query()
            ->orderBy('id')
            ->get();

        $latestResultIds = StrategyTradeResult::query()
            ->selectRaw('MAX(id) as id')
            ->groupBy('strategy_definition_id', 'simulated_trade_id');

        $canonicalResults = StrategyTradeResult::query()
            ->joinSub($latestResultIds, 'latest_strategy_results', function ($join): void {
                $join->on('strategy_trade_results.id', '=', 'latest_strategy_results.id');
            })
            ->select('strategy_trade_results.*')
            ->orderBy('strategy_definition_id')
            ->orderBy('entry_time')
            ->orderBy('simulated_trade_id')
            ->orderBy('id')
            ->get()
            ->groupBy('strategy_definition_id');

        $summaries = $strategies->map(function (StrategyDefinition $strategy) use ($canonicalResults): array {
            $results = $canonicalResults->get($strategy->id, collect())->values();

            return $this->buildSummary($strategy, $results);
        });

        return view('strategies.index', [
            'summaries' => $summaries,
        ]);
    }

    private function buildSummary(StrategyDefinition $strategy, Collection $results): array
    {
        $wins = $results->where('result_status', StrategyTradeResult::RESULT_STATUS_WIN);
        $losses = $results->where('result_status', StrategyTradeResult::RESULT_STATUS_LOSS);
        $open = $results->where('result_status', StrategyTradeResult::RESULT_STATUS_OPEN);
        $skipped = $results->where('result_status', StrategyTradeResult::RESULT_STATUS_SKIPPED);

        $startingCapital = $strategy->starting_capital === null ? null : (float) $strategy->starting_capital;
        $currentCapital = $strategy->current_capital === null ? null : (float) $strategy->current_capital;
        $netPnl = $startingCapital === null || $currentCapital === null ? null : $currentCapital - $startingCapital;
        $returnPercent = $startingCapital !== null && $startingCapital > 0 && $netPnl !== null
            ? ($netPnl / $startingCapital) * 100
            : null;

        $closedTradeCount = $wins->count() + $losses->count();
        $slHitFirstCount = $results->where('sl_hit_first', true)->count();
        $postSlRecoveryCount = $results->where('post_sl_recovered', true)->count();
        $canonicalNetPnl = $results->sum(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0));
        $expectedCapital = $startingCapital === null ? null : $startingCapital + $canonicalNetPnl;

        return [
            'strategy' => $strategy,
            'starting_capital' => $startingCapital,
            'current_capital' => $currentCapital,
            'net_pnl' => $netPnl,
            'return_percent' => $returnPercent,
            'allocation_percent' => $strategy->allocation_percent === null ? null : (float) $strategy->allocation_percent,
            'total_trades' => $results->count(),
            'wins' => $wins->count(),
            'losses' => $losses->count(),
            'open_trades' => $open->count(),
            'skipped_trades' => $skipped->count(),
            'win_rate' => $closedTradeCount > 0 ? ($wins->count() / $closedTradeCount) * 100 : null,
            'average_win' => $wins->isEmpty() ? null : $wins->avg(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0)),
            'average_loss' => $losses->isEmpty() ? null : $losses->avg(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0)),
            'best_trade' => $this->tradeExtreme($results, true),
            'worst_trade' => $this->tradeExtreme($results, false),
            'max_drawdown' => $this->calculateMaxDrawdown($startingCapital, $results),
            'sl_hit_first_count' => $slHitFirstCount,
            'post_sl_recovery_count' => $postSlRecoveryCount,
            'post_sl_recovery_rate' => $slHitFirstCount > 0 ? ($postSlRecoveryCount / $slHitFirstCount) * 100 : null,
            'ledger_mismatch' => $currentCapital !== null && $expectedCapital !== null && abs($currentCapital - $expectedCapital) > 0.01,
        ];
    }

    private function tradeExtreme(Collection $results, bool $best): ?array
    {
        if ($results->isEmpty()) {
            return null;
        }

        $result = $best
            ? $results->sortByDesc(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0))->first()
            : $results->sortBy(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0))->first();

        if (! $result instanceof StrategyTradeResult) {
            return null;
        }

        return [
            'net_pnl' => (float) ($result->net_pnl ?? 0),
            'symbol' => $result->symbol,
        ];
    }

    private function calculateMaxDrawdown(?float $startingCapital, Collection $results): array
    {
        $equity = $startingCapital ?? 0.0;
        $peak = $equity;
        $maxAmount = 0.0;
        $maxPercent = $peak > 0 ? 0.0 : null;

        $results
            ->sortBy([
                fn (StrategyTradeResult $first, StrategyTradeResult $second): int => ($first->entry_time?->getTimestamp() ?? 0) <=> ($second->entry_time?->getTimestamp() ?? 0),
                fn (StrategyTradeResult $first, StrategyTradeResult $second): int => ($first->simulated_trade_id ?? 0) <=> ($second->simulated_trade_id ?? 0),
                fn (StrategyTradeResult $first, StrategyTradeResult $second): int => $first->id <=> $second->id,
            ])
            ->each(function (StrategyTradeResult $result) use (&$equity, &$peak, &$maxAmount, &$maxPercent): void {
                if (in_array($result->result_status, [StrategyTradeResult::RESULT_STATUS_WIN, StrategyTradeResult::RESULT_STATUS_LOSS], true)) {
                    $equity += (float) ($result->net_pnl ?? 0);
                }

                $peak = max($peak, $equity);
                $drawdownAmount = max(0.0, $peak - $equity);

                if ($drawdownAmount > $maxAmount) {
                    $maxAmount = $drawdownAmount;
                    $maxPercent = $peak > 0 ? ($drawdownAmount / $peak) * 100 : null;
                }
            });

        return [
            'amount' => $maxAmount,
            'percent' => $maxPercent,
        ];
    }
}
