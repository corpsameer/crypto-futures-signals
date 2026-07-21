@extends('layouts.app')

@section('title', 'Strategy Backtest Run #' . $backtestRun->id . ' | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatUsdt = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . ' USDT';
        $formatSignedUsdt = function ($value): string {
            if ($value === null) {
                return '—';
            }
            return ((float) $value > 0 ? '+' : '') . number_format((float) $value, 2) . ' USDT';
        };
        $formatPercent = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
        $formatDate = fn ($value, $fallback = 'N/A') => $value ? $value->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') : $fallback;
        $na = fn ($value, $fallback = 'N/A') => trim((string) $value) === '' ? $fallback : $value;
        $valueClass = function ($value): string {
            if ($value === null) {
                return 'text-muted';
            }
            if ((float) $value > 0) {
                return 'text-success';
            }
            if ((float) $value < 0) {
                return 'text-danger';
            }
            return 'text-muted';
        };
        $runStatusBadgeClasses = ['completed' => 'text-bg-success', 'running' => 'text-bg-info', 'failed' => 'text-bg-danger'];
        $resultBadgeClasses = ['win' => 'text-bg-success', 'loss' => 'text-bg-danger', 'open' => 'text-bg-info', 'skipped' => 'text-bg-secondary'];
        $directionBadgeClasses = ['LONG' => 'text-bg-success', 'SHORT' => 'text-bg-danger'];
    @endphp

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <div class="mb-2 d-flex flex-wrap gap-3"><a href="{{ route('strategy-backtests.index') }}" class="text-decoration-none">&larr; Back to Backtest Runs</a><a href="{{ route('cryptofuturesignals.strategies.index') }}" class="text-decoration-none">Back to Strategies</a></div>
            <h1 class="h3 mb-1">Strategy Backtest Run #{{ $backtestRun->id }}</h1>
            <p class="text-muted mb-0">Run-specific historical strategy results. No canonical cross-run replacement is applied.</p>
        </div>
        <div><span class="badge fs-6 {{ $runStatusBadgeClasses[$backtestRun->status] ?? 'text-bg-secondary' }}">{{ strtoupper($na($backtestRun->status, 'unknown')) }}</span></div>
    </div>


    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('strategy-backtests.show', $backtestRun) }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="source" class="form-label">Source</label>
                    <x-source-filter-select :selected="$filters['source']" />
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filter</button>
                    <a href="{{ route('strategy-backtests.show', $backtestRun) }}" class="btn btn-outline-secondary">Clear Filter</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card metric-card mb-4"><div class="card-body">
        <h2 class="h5 mb-3">Backtest Run Details</h2>
        <div class="row g-3">
            @foreach ([
                'ID' => $backtestRun->id,
                'Name' => $backtestRun->name,
                'Status' => strtoupper($na($backtestRun->status, 'unknown')),
                'Started At' => $formatDate($backtestRun->started_at),
                'Completed At' => $formatDate($backtestRun->completed_at, 'Not completed'),
                'Starting Capital' => $formatUsdt($backtestRun->starting_capital),
                'Created At' => $formatDate($backtestRun->created_at),
            ] as $label => $value)
                <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">{{ $label }}</div><div class="fw-semibold">{{ $value }}</div></div></div>
            @endforeach
            <div class="col-12"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Notes</div><div class="fw-semibold white-space-pre-line">{{ trim((string) $backtestRun->notes) === '' ? 'No notes' : $backtestRun->notes }}</div></div></div>
        </div>
    </div></div>

    <h2 class="h5 mb-3">Overall Run Summary</h2>
    <div class="row g-4 mb-4">
        @foreach ([
            ['Strategies Represented', number_format($overallSummary['strategies_represented']), null],
            ['Processed Trades', number_format($overallSummary['processed_trades']), null],
            ['Total Result Rows', number_format($overallSummary['result_rows']), null],
            ['Wins', number_format($overallSummary['wins']), null],
            ['Losses', number_format($overallSummary['losses']), null],
            ['Open', number_format($overallSummary['open']), null],
            ['Skipped', number_format($overallSummary['skipped']), null],
            ['Total Allocated Capital', $formatUsdt($overallSummary['total_allocated_capital']), null],
            ['Total Gross P&L', $formatSignedUsdt($overallSummary['total_gross_pnl']), $overallSummary['total_gross_pnl']],
            ['Total Fees', $formatUsdt($overallSummary['total_fees']), null],
            ['Total Net P&L', $formatSignedUsdt($overallSummary['total_net_pnl']), $overallSummary['total_net_pnl']],
        ] as [$label, $value, $classValue])
            <div class="col-sm-6 col-xl-3"><div class="card metric-card h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">{{ $label }}</div><div class="fs-5 fw-semibold {{ $classValue === null ? '' : $valueClass($classValue) }}">{{ $value }}</div></div></div></div>
        @endforeach
    </div>

    <div class="card metric-card mb-4"><div class="card-body p-0"><div class="p-3"><h2 class="h5 mb-0">Per-Strategy Summaries</h2></div>
        @if ($strategySummaries->isEmpty())
            <div class="p-5 text-center text-muted">No strategy results are linked to this backtest run yet.</div>
        @else
            <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0"><thead class="table-light"><tr><th>Strategy</th><th>Code</th><th>Rows</th><th>Trades</th><th>Wins</th><th>Losses</th><th>Open</th><th>Skipped</th><th>Win Rate</th><th>Starting Capital</th><th>Analytical Ending Capital</th><th>Net P&amp;L</th><th>Return</th><th>Total Allocated</th><th>SL First</th><th>Recovered</th><th>Recovery Rate</th></tr></thead><tbody>
                @foreach ($strategySummaries as $summary)
                    @php($strategy = $summary['strategy'])
                    <tr>
                        <td class="fw-semibold">@if ($strategy)<a href="{{ route('cryptofuturesignals.strategies.show', $strategy) }}" class="text-decoration-none">{{ $strategy->name }}</a>@else Unknown strategy #{{ $summary['strategy_definition_id'] }} @endif</td>
                        <td><span class="badge text-bg-dark">{{ $strategy?->code ?? ('ID ' . $summary['strategy_definition_id']) }}</span></td>
                        <td>{{ number_format($summary['result_rows']) }}</td><td>{{ number_format($summary['processed_trades']) }}</td><td>{{ number_format($summary['wins']) }}</td><td>{{ number_format($summary['losses']) }}</td><td>{{ number_format($summary['open']) }}</td><td>{{ number_format($summary['skipped']) }}</td><td>{{ $formatPercent($summary['win_rate']) }}</td><td>{{ $formatUsdt($summary['starting_capital']) }}</td><td>{{ $formatUsdt($summary['analytical_ending_capital']) }}</td><td class="fw-semibold {{ $valueClass($summary['net_pnl']) }}">{{ $formatSignedUsdt($summary['net_pnl']) }}</td><td class="fw-semibold {{ $valueClass($summary['return_percent']) }}">{{ $formatPercent($summary['return_percent']) }}</td><td>{{ $formatUsdt($summary['total_allocated_capital']) }}</td><td>{{ number_format($summary['sl_hit_first_count']) }}</td><td>{{ number_format($summary['recovered_count']) }}</td><td>{{ $formatPercent($summary['recovery_rate']) }}</td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @endif
    </div></div>

    <div class="card metric-card mb-4"><div class="card-body p-0"><div class="p-3"><h2 class="h5 mb-1">Processed Results</h2><p class="small text-muted mb-0">Stored rows scoped to backtest run #{{ $backtestRun->id }}, ordered by entry time, strategy, trade, and result ID.</p></div>
        @if ($results->count() === 0)
            <div class="p-5 text-center text-muted">No processed strategy result rows are linked to this backtest run.</div>
        @else
            <div class="table-responsive"><table class="table table-sm table-striped table-hover align-middle mb-0"><thead class="table-light"><tr><th>Strategy</th><th>Entry Time</th><th>Symbol</th><th>Source</th><th>Direction</th><th>Trader</th><th>Result</th><th>Exit Event</th><th>Net P&amp;L</th><th>Capital Before</th><th>Capital After</th></tr></thead><tbody>
                @foreach ($results as $result)
                    @php($direction = strtoupper(trim((string) $result->direction)))
                    @php($exitEvent = trim((string) $result->exit_event_type) !== '' ? $result->exit_event_type : ($result->result_status === 'open' ? 'No exit yet' : 'N/A'))
                    <tr>
                        <td>@if ($result->strategyDefinition)<div class="fw-semibold">{{ $result->strategyDefinition->name }}</div><div class="small text-muted">{{ $result->strategyDefinition->code }}</div>@else <span class="fw-semibold">Unknown strategy #{{ $result->strategy_definition_id }}</span> @endif</td>
                        <td class="text-nowrap">{{ $formatDate($result->entry_time) }}</td>
                        <td class="fw-semibold">{{ strtoupper($na($result->symbol)) }}</td>
                        <td><x-signal-source-badge :source="$result->tradeSignal?->signal_source ?? 'unknown'" /></td>
                        <td><span class="badge {{ $directionBadgeClasses[$direction] ?? 'text-bg-secondary' }}">{{ $direction === '' ? 'N/A' : $direction }}</span></td>
                        <td>{{ $na($result->trader_name, 'Unknown') }}</td>
                        <td><span class="badge {{ $resultBadgeClasses[$result->result_status] ?? 'text-bg-secondary' }}">{{ strtoupper($na($result->result_status, 'unknown')) }}</span></td>
                        <td>{{ $exitEvent }}</td>
                        <td class="fw-semibold {{ $valueClass($result->net_pnl) }}">{{ $formatSignedUsdt($result->net_pnl) }}</td>
                        <td>{{ $formatUsdt($result->capital_before) }}</td>
                        <td>{{ $formatUsdt($result->capital_after) }}</td>
                    </tr>
                @endforeach
            </tbody></table></div>
        @endif
    </div></div>
    @if ($results->hasPages())<div class="mt-4">{{ $results->withQueryString()->links() }}</div>@endif
@endsection
