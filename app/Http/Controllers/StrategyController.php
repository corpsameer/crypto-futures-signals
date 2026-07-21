<?php

namespace App\Http\Controllers;

use App\Models\StrategyDefinition;
use App\Models\StrategyTradeResult;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StrategyController extends Controller
{
    private const UNKNOWN_VALUE = '__UNKNOWN__';
    private const NULL_VALUE = '__NULL__';

    /**
     * @var list<string>
     */
    private const RESULT_STATUSES = ['win', 'loss', 'open', 'skipped'];

    public function index(Request $request): View
    {
        $strategies = StrategyDefinition::query()
            ->orderBy('id')
            ->get();

        $baseCanonicalQuery = $this->canonicalResultsQuery();
        $filterOptions = $this->buildFilterOptions($baseCanonicalQuery);
        $filters = $this->validatedFilters($request, $filterOptions);
        $filtersActive = $this->filtersAreActive($filters);

        $canonicalResults = $this->applyFilters($this->canonicalResultsQuery(), $filters)
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
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'filtersActive' => $filtersActive,
        ]);
    }

    public function edit(StrategyDefinition $strategy): View
    {
        return view('strategies.edit', [
            'strategy' => $strategy,
        ]);
    }

    public function update(Request $request, StrategyDefinition $strategy): RedirectResponse
    {
        $request->merge([
            'name' => trim((string) $request->input('name', '')),
            'description' => filled($request->input('description')) ? trim((string) $request->input('description')) : null,
            'is_active' => $request->boolean('is_active'),
        ]);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:191'],
            'description' => ['nullable', 'string'],
            'allocation_percent' => ['required', 'numeric', 'gt:0', 'lte:100'],
            'is_active' => ['boolean'],
        ]);

        $strategy->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'allocation_percent' => $validated['allocation_percent'],
            'is_active' => (bool) $validated['is_active'],
        ]);

        return redirect()
            ->route('cryptofuturesignals.strategies.show', $strategy)
            ->with('success', 'Strategy settings updated successfully.');
    }

    public function show(Request $request, StrategyDefinition $strategy): View
    {
        $baseCanonicalQuery = $this->canonicalResultsQuery($strategy);
        $filterOptions = $this->buildFilterOptions($baseCanonicalQuery);
        $filters = $this->validatedFilters($request, $filterOptions);
        $filtersActive = $this->filtersAreActive($filters);

        $canonicalResults = $this->applyFilters($this->canonicalResultsQuery($strategy), $filters)
            ->orderBy('strategy_trade_results.entry_time')
            ->orderBy('strategy_trade_results.simulated_trade_id')
            ->orderBy('strategy_trade_results.id')
            ->get();

        $ledgerResults = $this->applyFilters($this->canonicalResultsQuery($strategy), $filters)
            ->with('tradeSignal:id,signal_source')
            ->orderByDesc('strategy_trade_results.entry_time')
            ->orderByDesc('strategy_trade_results.simulated_trade_id')
            ->orderByDesc('strategy_trade_results.id')
            ->paginate(25)
            ->withQueryString();

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
            'filters' => $filters,
            'filterOptions' => $filterOptions,
            'filtersActive' => $filtersActive,
        ]);
    }


    private function validatedFilters(Request $request, array $filterOptions): array
    {
        $filters = [
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'trader' => trim((string) $request->query('trader', '')),
            'direction' => strtoupper(trim((string) $request->query('direction', ''))),
            'symbol' => strtoupper(trim((string) $request->query('symbol', ''))),
            'result_status' => trim((string) $request->query('result_status', '')),
            'exit_event_type' => trim((string) $request->query('exit_event_type', '')),
            'post_sl_recovered' => trim((string) $request->query('post_sl_recovered', '')),
        ];

        $request->merge($filters);
        $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'trader' => ['nullable', 'string', 'max:255', Rule::in(array_keys($filterOptions['traders']))],
            'direction' => ['nullable', 'string', 'max:50', Rule::in(array_keys($filterOptions['directions']))],
            'symbol' => ['nullable', 'string', 'max:50', Rule::in(array_keys($filterOptions['symbols']))],
            'result_status' => ['nullable', Rule::in(self::RESULT_STATUSES)],
            'exit_event_type' => ['nullable', 'string', 'max:100', Rule::in(array_keys($filterOptions['exitEvents']))],
            'post_sl_recovered' => ['nullable', Rule::in(['1', '0'])],
        ], [
            'date_from.before_or_equal' => 'The From Date must be on or before the To Date.',
        ]);

        return $filters;
    }

    private function filtersAreActive(array $filters): bool
    {
        return collect($filters)->contains(fn (string $value): bool => $value !== '');
    }

    private function applyFilters(Builder $query, array $filters): Builder
    {
        $timezone = config('app.timezone', 'UTC');

        if ($filters['date_from'] !== '') {
            $query->where('strategy_trade_results.entry_time', '>=', CarbonImmutable::createFromFormat('Y-m-d', $filters['date_from'], $timezone)->startOfDay()->utc());
        }

        if ($filters['date_to'] !== '') {
            $query->where('strategy_trade_results.entry_time', '<', CarbonImmutable::createFromFormat('Y-m-d', $filters['date_to'], $timezone)->startOfDay()->addDay()->utc());
        }

        foreach (['trader' => 'trader_name', 'direction' => 'direction', 'symbol' => 'symbol'] as $filterKey => $column) {
            if ($filters[$filterKey] === self::UNKNOWN_VALUE) {
                $query->where(fn (Builder $query) => $query->whereNull("strategy_trade_results.$column")->orWhereRaw("TRIM(strategy_trade_results.$column) = ''"));
            } elseif ($filters[$filterKey] !== '') {
                if (in_array($filterKey, ['direction', 'symbol'], true)) {
                    $query->whereRaw("UPPER(strategy_trade_results.$column) = ?", [$filters[$filterKey]]);
                } else {
                    $query->where("strategy_trade_results.$column", $filters[$filterKey]);
                }
            }
        }

        if ($filters['result_status'] !== '') {
            $query->where('strategy_trade_results.result_status', $filters['result_status']);
        }

        if ($filters['exit_event_type'] === self::NULL_VALUE) {
            $query->where(fn (Builder $query) => $query->whereNull('strategy_trade_results.exit_event_type')->orWhereRaw("TRIM(strategy_trade_results.exit_event_type) = ''"));
        } elseif ($filters['exit_event_type'] !== '') {
            $query->where('strategy_trade_results.exit_event_type', $filters['exit_event_type']);
        }

        if ($filters['post_sl_recovered'] !== '') {
            $query->where('strategy_trade_results.post_sl_recovered', $filters['post_sl_recovered'] === '1');
        }

        return $query;
    }

    private function buildFilterOptions(Builder $canonicalQuery): array
    {
        $results = (clone $canonicalQuery)->get(['strategy_trade_results.trader_name', 'strategy_trade_results.direction', 'strategy_trade_results.symbol', 'strategy_trade_results.exit_event_type']);

        return [
            'traders' => $this->optionMap($results, 'trader_name', includeUnknown: true),
            'directions' => $this->directionOptions($results),
            'symbols' => $this->optionMap($results, 'symbol', includeUnknown: true, uppercaseLabels: true),
            'resultStatuses' => collect(self::RESULT_STATUSES)->mapWithKeys(fn (string $status): array => [$status => ucfirst($status)])->all(),
            'exitEvents' => $this->optionMap($results, 'exit_event_type') + [self::NULL_VALUE => 'No Exit'],
            'postSlRecovered' => ['1' => 'Yes', '0' => 'No'],
        ];
    }

    private function directionOptions(Collection $results): array
    {
        $options = ['LONG' => 'LONG', 'SHORT' => 'SHORT'];

        return $options + $this->optionMap($results, 'direction', includeUnknown: true, uppercaseLabels: true);
    }

    private function optionMap(Collection $results, string $field, string $unknownValue = self::UNKNOWN_VALUE, string $unknownLabel = 'Unknown', bool $includeUnknown = false, bool $uppercaseLabels = false): array
    {
        $values = $results->pluck($field)->map(fn ($value): string => trim((string) $value));
        $options = $values->filter(fn (string $value): bool => $value !== '')->unique()->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->mapWithKeys(fn (string $value): array => [$uppercaseLabels ? strtoupper($value) : $value => $uppercaseLabels ? strtoupper($value) : $value])->all();

        if ($includeUnknown && $values->contains('')) {
            $options[$unknownValue] = $unknownLabel;
        }

        return $options;
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
            'filtered_net_pnl' => $canonicalNetPnl,
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
