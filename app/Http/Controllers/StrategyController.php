<?php

namespace App\Http\Controllers;

use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StrategyController extends Controller
{
    public function index(): View
    {
        $strategies = StrategyDefinition::query()
            ->orderBy('id')
            ->get();

        $canonicalResults = $this->canonicalResultsQuery()
            ->orderBy('strategy_trade_results.strategy_definition_id')
            ->orderBy('strategy_trade_results.entry_time')
            ->orderBy('strategy_trade_results.simulated_trade_id')
            ->orderBy('strategy_trade_results.id')
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

    public function show(StrategyDefinition $strategy): View
    {
        $canonicalResults = $this->canonicalResultsQuery($strategy)
            ->orderBy('strategy_trade_results.entry_time')
            ->orderBy('strategy_trade_results.simulated_trade_id')
            ->orderBy('strategy_trade_results.id')
            ->get();

        $ledgerResults = $this->canonicalResultsQuery($strategy)
            ->orderByDesc('strategy_trade_results.entry_time')
            ->orderByDesc('strategy_trade_results.simulated_trade_id')
            ->orderByDesc('strategy_trade_results.id')
            ->paginate(25);

        return view('strategies.show', [
            'strategy' => $strategy,
            'summary' => $this->buildSummary($strategy, $canonicalResults),
            'capitalSummary' => $this->buildCapitalSummary($strategy, $canonicalResults),
            'capitalProgression' => $this->buildCapitalProgression($strategy, $canonicalResults),
            'ledgerResults' => $ledgerResults,
            'postSlAnalytics' => $this->buildPostSlAnalytics($canonicalResults),
            'traderBreakdown' => $this->buildGroupedBreakdown($canonicalResults, 'trader_name'),
            'directionBreakdown' => $this->buildDirectionBreakdown($canonicalResults),
            'symbolBreakdown' => $this->buildSymbolBreakdown($canonicalResults),
        ]);
    }

    private function canonicalResultsQuery(?StrategyDefinition $strategy = null): Builder
    {
        $latestResultIds = StrategyTradeResult::query()
            ->selectRaw('MAX(id) as id')
            ->when($strategy !== null, fn (Builder $query) => $query->where('strategy_definition_id', $strategy->id))
            ->groupBy('strategy_definition_id', 'simulated_trade_id');

        return StrategyTradeResult::query()
            ->joinSub($latestResultIds, 'latest_strategy_results', function ($join): void {
                $join->on('strategy_trade_results.id', '=', 'latest_strategy_results.id');
            })
            ->select('strategy_trade_results.*')
            ->when($strategy !== null, fn (Builder $query) => $query->where('strategy_trade_results.strategy_definition_id', $strategy->id));
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
            'total_trades' => $wins->count() + $losses->count() + $open->count() + $skipped->count(),
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

    private function buildCapitalSummary(StrategyDefinition $strategy, Collection $results): array
    {
        $summary = $this->buildSummary($strategy, $results);
        $startingCapital = $summary['starting_capital'];
        $canonicalNetPnl = $results->sum(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0));

        return $summary + [
            'total_allocated_capital' => $results->sum(fn (StrategyTradeResult $result): float => (float) ($result->allocated_capital ?? 0)),
            'total_gross_pnl' => $results->sum(fn (StrategyTradeResult $result): float => (float) ($result->gross_pnl ?? 0)),
            'total_fees' => $results->sum(fn (StrategyTradeResult $result): float => (float) ($result->fees ?? 0)),
            'total_canonical_net_pnl' => $canonicalNetPnl,
            'expected_capital' => $startingCapital === null ? null : $startingCapital + $canonicalNetPnl,
        ];
    }

    private function buildCapitalProgression(StrategyDefinition $strategy, Collection $results): Collection
    {
        $capital = $strategy->starting_capital === null ? 0.0 : (float) $strategy->starting_capital;
        $sequence = 0;

        return $results->sortBy([
            fn (StrategyTradeResult $first, StrategyTradeResult $second): int => ($first->entry_time?->getTimestamp() ?? 0) <=> ($second->entry_time?->getTimestamp() ?? 0),
            fn (StrategyTradeResult $first, StrategyTradeResult $second): int => ($first->simulated_trade_id ?? 0) <=> ($second->simulated_trade_id ?? 0),
            fn (StrategyTradeResult $first, StrategyTradeResult $second): int => $first->id <=> $second->id,
        ])->values()->map(function (StrategyTradeResult $result) use (&$capital, &$sequence): array {
            $sequence++;
            $capitalChange = $this->analyticalCapitalChange($result);
            $capital += $capitalChange;

            return [
                'sequence' => $sequence,
                'entry_time' => $result->entry_time,
                'symbol' => $result->symbol,
                'result_status' => $result->result_status,
                'allocated_capital' => $result->allocated_capital === null ? null : (float) $result->allocated_capital,
                'pnl_percent' => $result->exit_leveraged_pnl_percent === null ? null : (float) $result->exit_leveraged_pnl_percent,
                'stored_net_pnl' => (float) ($result->net_pnl ?? 0),
                'capital_change' => $capitalChange,
                'analytical_capital' => $capital,
            ];
        });
    }

    private function analyticalCapitalChange(StrategyTradeResult $result): float
    {
        if ($result->result_status === StrategyTradeResult::RESULT_STATUS_LOSS) {
            $netPnl = (float) ($result->net_pnl ?? 0);

            return $netPnl < 0 ? $netPnl : -1 * (float) ($result->allocated_capital ?? 0);
        }

        if ($result->result_status === StrategyTradeResult::RESULT_STATUS_WIN) {
            return (float) ($result->net_pnl ?? 0);
        }

        return 0.0;
    }

    private function buildPostSlAnalytics(Collection $results): array
    {
        $slFirst = $results->where('sl_hit_first', true)->values();
        $recovered = $slFirst->where('post_sl_recovered', true)->values();
        $gainRows = $slFirst->filter(fn (StrategyTradeResult $result): bool => $result->post_sl_max_gain_percent !== null);

        return [
            'sl_hit_first_count' => $slFirst->count(),
            'post_sl_recovered_count' => $recovered->count(),
            'post_sl_recovery_rate' => $slFirst->isNotEmpty() ? ($recovered->count() / $slFirst->count()) * 100 : null,
            'average_post_sl_max_gain_percent' => $gainRows->isEmpty() ? null : $gainRows->avg(fn (StrategyTradeResult $result): float => (float) $result->post_sl_max_gain_percent),
            'best_post_sl_max_gain_percent' => $gainRows->isEmpty() ? null : $gainRows->max(fn (StrategyTradeResult $result): float => (float) $result->post_sl_max_gain_percent),
            'first_recovery_event_counts' => $recovered->groupBy(fn (StrategyTradeResult $result): string => $this->filledOrUnknown($result->post_sl_first_recovery_event, 'Unknown'))->map->count()->sortKeys(),
            'recovery_rows' => $slFirst->sortByDesc(fn (StrategyTradeResult $result): int => $result->sl_hit_time?->getTimestamp() ?? 0)->values(),
        ];
    }

    private function buildGroupedBreakdown(Collection $results, string $field): Collection
    {
        return $results->groupBy(fn (StrategyTradeResult $result): string => $this->filledOrUnknown($result->{$field}, 'Unknown'))
            ->map(fn (Collection $group, string $label): array => $this->summarizeGroup($group, $label))
            ->sortBy([['total', 'desc'], ['label', 'asc']])
            ->values();
    }

    private function buildDirectionBreakdown(Collection $results): Collection
    {
        return $results->groupBy(fn (StrategyTradeResult $result): string => $this->filledOrUnknown($result->direction, 'Unknown'))
            ->map(fn (Collection $group, string $label): array => $this->summarizeGroup($group, strtoupper($label)))
            ->sortBy(fn (array $row): array => [match ($row['label']) { 'LONG' => 0, 'SHORT' => 1, default => 2 }, $row['label']])
            ->values();
    }

    private function buildSymbolBreakdown(Collection $results): Collection
    {
        return $results->groupBy(fn (StrategyTradeResult $result): string => strtoupper($this->filledOrUnknown($result->symbol, 'Unknown')))
            ->map(function (Collection $group, string $label): array {
                $summary = $this->summarizeGroup($group, $label);
                $summary['average_net_pnl'] = $group->isEmpty() ? null : $group->avg(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0));
                $summary['best_trade'] = $this->tradeExtreme($group, true);
                $summary['worst_trade'] = $this->tradeExtreme($group, false);

                return $summary;
            })
            ->sortBy([['total', 'desc'], ['net_pnl', 'desc'], ['label', 'asc']])
            ->values();
    }

    private function summarizeGroup(Collection $group, string $label): array
    {
        $wins = $group->where('result_status', StrategyTradeResult::RESULT_STATUS_WIN)->count();
        $losses = $group->where('result_status', StrategyTradeResult::RESULT_STATUS_LOSS)->count();
        $open = $group->where('result_status', StrategyTradeResult::RESULT_STATUS_OPEN)->count();
        $skipped = $group->where('result_status', StrategyTradeResult::RESULT_STATUS_SKIPPED)->count();
        $slHitFirst = $group->where('sl_hit_first', true)->count();
        $recovered = $group->where('post_sl_recovered', true)->count();
        $closed = $wins + $losses;

        return [
            'label' => $label,
            'total' => $wins + $losses + $open + $skipped,
            'wins' => $wins,
            'losses' => $losses,
            'open' => $open,
            'skipped' => $skipped,
            'win_rate' => $closed > 0 ? ($wins / $closed) * 100 : null,
            'net_pnl' => $group->sum(fn (StrategyTradeResult $result): float => (float) ($result->net_pnl ?? 0)),
            'sl_hit_first' => $slHitFirst,
            'recovered' => $recovered,
            'recovery_rate' => $slHitFirst > 0 ? ($recovered / $slHitFirst) * 100 : null,
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
                $equity += $this->analyticalCapitalChange($result);

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

    private function filledOrUnknown(?string $value, string $fallback): string
    {
        $value = trim((string) $value);

        return $value === '' ? $fallback : $value;
    }
}
