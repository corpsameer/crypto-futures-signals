@extends('layouts.app')

@section('title', $strategy->name . ' | Strategy Details | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatUsdt = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . ' USDT';
        $formatSignedUsdt = function ($value): string { if ($value === null) return 'N/A'; $prefix = (float) $value > 0 ? '+' : ''; return $prefix . number_format((float) $value, 2) . ' USDT'; };
        $formatPercent = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
        $formatPrice = fn ($value) => $value === null ? 'N/A' : rtrim(rtrim(number_format((float) $value, 12, '.', ''), '0'), '.');
        $formatDate = fn ($value) => $value?->format('Y-m-d H:i') ?? 'N/A';
        $na = fn ($value) => filled($value) ? $value : 'N/A';
        $valueClass = fn ($value) => $value === null || (float) $value === 0.0 ? 'text-muted' : ((float) $value > 0 ? 'text-success' : 'text-danger');
        $resultBadgeClasses = ['win' => 'text-bg-success', 'loss' => 'text-bg-danger', 'open' => 'text-bg-primary', 'skipped' => 'text-bg-secondary'];
        $directionBadgeClasses = ['LONG' => 'text-bg-success', 'SHORT' => 'text-bg-danger', 'long' => 'text-bg-success', 'short' => 'text-bg-danger'];
        $formatTrade = fn (?array $trade) => $trade === null ? 'N/A' : $formatSignedUsdt($trade['net_pnl']) . ($trade['symbol'] ? ' (' . strtoupper($trade['symbol']) . ')' : '');
    @endphp

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <div class="mb-2"><a href="{{ route('cryptofuturesignals.strategies.index') }}" class="text-decoration-none">&larr; Back to strategy summary</a></div>
            <h1 class="h3 mb-1">{{ $strategy->name }}</h1>
            <p class="text-muted mb-0">Configuration, canonical ledger analytics, and grouped performance breakdowns.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <span class="badge fs-6 {{ $strategy->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $strategy->is_active ? 'Active' : 'Inactive' }}</span>
            <a href="{{ route('cryptofuturesignals.strategies.edit', $strategy) }}" class="btn btn-primary">Edit</a>
            <a href="{{ route('cryptofuturesignals.trades.index') }}" class="btn btn-outline-primary">Simulated Trades</a>
        </div>
    </div>

    @include('strategies.partials.filters', [
        'action' => route('cryptofuturesignals.strategies.show', $strategy),
        'clearUrl' => route('cryptofuturesignals.strategies.show', $strategy),
        'filters' => $filters,
        'filterOptions' => $filterOptions,
        'filtersActive' => $filtersActive,
    ])

    <div class="card metric-card mb-4"><div class="card-body">
        <h2 class="h5 mb-3">Strategy Details</h2>
        <div class="row g-3">
            @foreach ([
                'Name' => $strategy->name, 'Code' => $strategy->code, 'Description' => $strategy->description,
                'Strategy Type' => $strategy->strategy_type, 'Target Event Type' => $strategy->target_event_type, 'Stop Event Type' => $strategy->stop_event_type,
                'Allocation Percentage' => $formatPercent($strategy->allocation_percent), 'Starting Capital' => $formatUsdt($strategy->starting_capital),
                'Current Capital' => $formatUsdt($strategy->current_capital), 'Loss Cap Percentage' => $formatPercent($strategy->loss_cap_percent),
                'Uses Post-SL Recovery' => $strategy->uses_post_sl_recovery ? 'Yes' : 'No',
            ] as $label => $value)
                <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">{{ $label }}</div><div class="fw-semibold">{{ $na($value) }}</div></div></div>
            @endforeach
        </div>
        @if (strtoupper((string) $strategy->code) === 'RECOVERY_HOLD_TP1')
            <div class="alert alert-info mt-3 mb-0">This strategy’s stored configuration describes a post-SL recovery approach: normal SL can be ignored and <strong>POST_SL_TP1_HIT</strong> may be a valid exit.</div>
        @endif
    </div></div>

    @if ($capitalSummary['ledger_mismatch'])
        <div class="alert alert-warning">Ledger history differs: stored current capital reflects the latest executed ledger, while this page deduplicates historical trade results by strategy and simulated trade.</div>
    @endif

    <h2 class="h5 mb-3">Capital Summary</h2>
    <div class="row g-4 mb-4">
        @foreach ([
            'Starting Capital' => [$capitalSummary['starting_capital'], false], 'Current Capital' => [$capitalSummary['current_capital'], false], ($filtersActive ? 'Account Net P&L' : 'Net P&L') => [$capitalSummary['net_pnl'], true], ($filtersActive ? 'Account Return' : 'Return') => [$capitalSummary['return_percent'], 'percent'],
            ($filtersActive ? 'Filtered Total Allocated' : 'Total Allocated') => [$capitalSummary['total_allocated_capital'], false], ($filtersActive ? 'Filtered Total Gross P&L' : 'Total Gross P&L') => [$capitalSummary['total_gross_pnl'], true], ($filtersActive ? 'Filtered Total Fees' : 'Total Fees') => [$capitalSummary['total_fees'], false], ($filtersActive ? 'Filtered Canonical Net P&L' : 'Canonical Net P&L') => [$capitalSummary['total_canonical_net_pnl'], true],
            ($filtersActive ? 'Filtered Max Drawdown' : 'Max Drawdown') => [$capitalSummary['max_drawdown']['amount'], false], ($filtersActive ? 'Filtered Max Drawdown %' : 'Max Drawdown %') => [$capitalSummary['max_drawdown']['percent'], 'percent'],
        ] as $label => [$value, $signed])
            <div class="col-sm-6 col-xl-3"><div class="card metric-card h-100"><div class="card-body"><div class="small text-muted text-uppercase fw-semibold">{{ $label }}</div><div class="fs-5 fw-semibold {{ $signed === true || $signed === 'percent' ? $valueClass($value) : '' }}">{{ $signed === 'percent' ? $formatPercent($value) : ($signed ? $formatSignedUsdt($value) : $formatUsdt($value)) }}</div></div></div></div>
        @endforeach
    </div>

    <div class="card metric-card mb-4"><div class="card-body p-0">
        <div class="p-3"><h2 class="h5 mb-0">{{ $filtersActive ? 'Filtered Win/Loss Summary' : 'Win/Loss Summary' }}</h2>@if ($filtersActive)<p class="small text-muted mb-0">{{ number_format($summary['total_trades']) }} matching canonical results.</p>@endif</div>
        <div class="table-responsive"><table class="table table-striped align-middle mb-0"><thead class="table-light"><tr><th>Total</th><th>Wins</th><th>Losses</th><th>Open</th><th>Skipped</th><th>Win Rate</th><th>Average Win</th><th>Average Loss</th><th>Best Trade</th><th>Worst Trade</th></tr></thead><tbody><tr><td class="fw-semibold">{{ number_format($summary['total_trades']) }}</td><td><span class="badge text-bg-success">{{ number_format($summary['wins']) }}</span></td><td><span class="badge text-bg-danger">{{ number_format($summary['losses']) }}</span></td><td><span class="badge text-bg-primary">{{ number_format($summary['open_trades']) }}</span></td><td><span class="badge text-bg-secondary">{{ number_format($summary['skipped_trades']) }}</span></td><td>{{ $formatPercent($summary['win_rate']) }}</td><td class="text-success fw-semibold">{{ $formatSignedUsdt($summary['average_win']) }}</td><td class="text-danger fw-semibold">{{ $formatSignedUsdt($summary['average_loss']) }}</td><td class="{{ $summary['best_trade'] ? $valueClass($summary['best_trade']['net_pnl']) : 'text-muted' }} fw-semibold">{{ $formatTrade($summary['best_trade']) }}</td><td class="{{ $summary['worst_trade'] ? $valueClass($summary['worst_trade']['net_pnl']) : 'text-muted' }} fw-semibold">{{ $formatTrade($summary['worst_trade']) }}</td></tr></tbody></table></div>
    </div></div>

    <div class="card metric-card mb-4"><div class="card-body p-0"><div class="p-3"><h2 class="h5 mb-1">{{ $filtersActive ? 'Filtered Capital Progression' : 'Capital Progression' }}</h2><p class="small text-muted mb-0">Analytical capital applies stored net P&amp;L for wins, subtracts invested capital when a loss has no stored negative P&amp;L, and leaves open/skipped rows at zero change.</p></div>
        @if ($capitalProgression->isEmpty()) <div class="p-5 text-center text-muted">{{ $filtersActive ? 'No canonical results match the active filters for capital progression.' : 'No canonical results are available for capital progression.' }}</div> @else
        <div class="table-responsive"><table class="table table-striped table-hover align-middle mb-0"><thead class="table-light"><tr><th>Sequence</th><th>Entry Time</th><th>Symbol</th><th>Result</th><th>Capital Invested</th><th>P&amp;L %</th><th>Stored Net P&amp;L</th><th>Capital Change</th><th>Analytical Capital</th></tr></thead><tbody>@foreach ($capitalProgression as $row)<tr><td>{{ $row['sequence'] }}</td><td class="text-nowrap">{{ $formatDate($row['entry_time']) }}</td><td class="fw-semibold">{{ strtoupper($na($row['symbol'])) }}</td><td><span class="badge {{ $resultBadgeClasses[$row['result_status']] ?? 'text-bg-secondary' }}">{{ $row['result_status'] ?? 'N/A' }}</span></td><td>{{ $formatUsdt($row['allocated_capital']) }}</td><td class="fw-semibold {{ $valueClass($row['pnl_percent']) }}">{{ $formatPercent($row['pnl_percent']) }}</td><td class="fw-semibold {{ $valueClass($row['stored_net_pnl']) }}">{{ $formatSignedUsdt($row['stored_net_pnl']) }}</td><td class="fw-semibold {{ $valueClass($row['capital_change']) }}">{{ $formatSignedUsdt($row['capital_change']) }}</td><td class="fw-semibold">{{ $formatUsdt($row['analytical_capital']) }}</td></tr>@endforeach</tbody></table></div>@endif
    </div></div>

    <div class="card metric-card mb-4"><div class="card-body p-0"><div class="p-3"><h2 class="h5 mb-0">Trade-by-Trade Ledger</h2></div>
        @if ($ledgerResults->count() === 0) <div class="p-5 text-center text-muted">{{ $filtersActive ? 'No canonical ledger rows match the active filters for this strategy.' : 'No canonical ledger rows are available for this strategy.' }}</div> @else
        <div class="table-responsive"><table class="table table-striped table-hover align-middle mb-0"><thead class="table-light"><tr><th>Entry Time</th><th>Symbol</th><th>Source</th><th>Direction</th><th>Trader</th><th>Capital Before</th><th>Allocation %</th><th>Allocated Capital</th><th>Entry Price</th><th>Exit Price</th><th>Exit Event</th><th>Exit P&amp;L %</th><th>Net P&amp;L</th><th>Capital After</th><th>Result</th><th>SL Hit First</th><th>Post-SL Recovery</th></tr></thead><tbody>
            @foreach ($ledgerResults as $result)<tr><td class="text-nowrap">{{ $formatDate($result->entry_time) }}</td><td class="fw-semibold">{{ strtoupper($na($result->symbol)) }}</td><td><x-signal-source-badge :source="$result->tradeSignal?->signal_source ?? 'unknown'" /></td><td><span class="badge {{ $directionBadgeClasses[$result->direction] ?? 'text-bg-secondary' }}">{{ strtoupper($na($result->direction)) }}</span></td><td>{{ $result->trader_name ?: 'Unknown' }}</td><td>{{ $formatUsdt($result->capital_before) }}</td><td>{{ $formatPercent($result->allocation_percent) }}</td><td>{{ $formatUsdt($result->allocated_capital) }}</td><td>{{ $formatPrice($result->entry_price) }}</td><td>{{ $formatPrice($result->exit_price) }}</td><td>{{ $na($result->exit_event_type) }}</td><td>{{ $formatPercent($result->exit_leveraged_pnl_percent) }}</td><td class="fw-semibold {{ $valueClass($result->net_pnl) }}">{{ $formatSignedUsdt($result->net_pnl) }}</td><td>{{ $formatUsdt($result->capital_after) }}</td><td><span class="badge {{ $resultBadgeClasses[$result->result_status] ?? 'text-bg-secondary' }}">{{ $result->result_status ?? 'N/A' }}</span></td><td>{{ $result->sl_hit_first ? 'Yes' : 'No' }}</td><td>@if ($result->post_sl_recovered) <span class="text-success fw-semibold">Yes</span><div class="small text-muted">{{ $na($result->post_sl_first_recovery_event) }}</div> @else No @endif</td></tr>@endforeach
        </tbody></table></div>@endif
    </div></div>@if ($ledgerResults->hasPages())<div class="mt-4">{{ $ledgerResults->withQueryString()->links() }}</div>@endif

    <div class="card metric-card mb-4"><div class="card-body">
        <h2 class="h5 mb-3">Post-SL Recovery Analytics</h2><div class="row g-3 mb-3">
            @foreach (['SL Hit First' => $postSlAnalytics['sl_hit_first_count'], 'Recovered' => $postSlAnalytics['post_sl_recovered_count'], 'Recovery Rate' => $formatPercent($postSlAnalytics['post_sl_recovery_rate']), 'Avg Max Gain' => $formatPercent($postSlAnalytics['average_post_sl_max_gain_percent']), 'Best Max Gain' => $formatPercent($postSlAnalytics['best_post_sl_max_gain_percent'])] as $label => $value)<div class="col-sm-6 col-xl"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">{{ $label }}</div><div class="fs-5 fw-semibold">{{ is_numeric($value) ? number_format($value) : $value }}</div></div></div>@endforeach
        </div>
        <h3 class="h6">First Recovery Event Counts</h3>@if ($postSlAnalytics['first_recovery_event_counts']->isEmpty())<p class="text-muted">No recovered SL-first trades.</p>@else <div class="d-flex flex-wrap gap-2 mb-3">@foreach ($postSlAnalytics['first_recovery_event_counts'] as $event => $count)<span class="badge text-bg-info">{{ $event }}: {{ $count }}</span>@endforeach</div>@endif
        <h3 class="h6">Recovery Detail</h3>@if ($postSlAnalytics['recovery_rows']->isEmpty())<p class="text-muted mb-0">No SL-first rows are available.</p>@else <div class="table-responsive"><table class="table table-sm table-striped align-middle mb-0"><thead class="table-light"><tr><th>Entry Time</th><th>Symbol</th><th>Original Result</th><th>SL Time</th><th>First Recovery Event</th><th>First Recovery Price</th><th>First Recovery Time</th><th>Post-SL Max Gain %</th><th>Post-SL Max Gain Price</th></tr></thead><tbody>@foreach ($postSlAnalytics['recovery_rows'] as $row)<tr><td>{{ $formatDate($row->entry_time) }}</td><td class="fw-semibold">{{ strtoupper($na($row->symbol)) }}</td><td><span class="badge {{ $resultBadgeClasses[$row->result_status] ?? 'text-bg-secondary' }}">{{ $row->result_status ?? 'N/A' }}</span></td><td>{{ $formatDate($row->sl_hit_time) }}</td><td>{{ $na($row->post_sl_first_recovery_event) }}</td><td>{{ $formatPrice($row->post_sl_first_recovery_price) }}</td><td>{{ $formatDate($row->post_sl_first_recovery_time) }}</td><td>{{ $formatPercent($row->post_sl_max_gain_percent) }}</td><td>{{ $formatPrice($row->post_sl_max_gain_price) }}</td></tr>@endforeach</tbody></table></div>@endif
    </div></div>

    @foreach ([['Trader Breakdown', $traderBreakdown, false], ['Direction Breakdown', $directionBreakdown, false], ['Symbol Breakdown', $symbolBreakdown, true]] as [$title, $rows, $isSymbol])
        <div class="card metric-card mb-4"><div class="card-body p-0"><div class="p-3"><h2 class="h5 mb-0">{{ $title }}</h2></div>
            @if ($rows->isEmpty()) <div class="p-5 text-center text-muted">No canonical results are available for this breakdown.</div> @else
            <div class="table-responsive"><table class="table table-striped table-hover align-middle mb-0"><thead class="table-light"><tr><th>{{ str_replace(' Breakdown', '', $title) }}</th><th>Total</th><th>Wins</th><th>Losses</th><th>Open</th><th>Skipped</th><th>Win Rate</th><th>Net P&amp;L</th>@if($isSymbol)<th>Average Net P&amp;L</th><th>Best Trade</th><th>Worst Trade</th>@endif<th>SL Hit First</th><th>Recovered</th><th>Recovery Rate</th></tr></thead><tbody>@foreach ($rows as $row)<tr><td class="fw-semibold">{{ $row['label'] }}</td><td>{{ number_format($row['total']) }}</td><td>{{ number_format($row['wins']) }}</td><td>{{ number_format($row['losses']) }}</td><td>{{ number_format($row['open']) }}</td><td>{{ number_format($row['skipped']) }}</td><td>{{ $formatPercent($row['win_rate']) }}</td><td class="fw-semibold {{ $valueClass($row['net_pnl']) }}">{{ $formatSignedUsdt($row['net_pnl']) }}</td>@if($isSymbol)<td class="fw-semibold {{ $valueClass($row['average_net_pnl']) }}">{{ $formatSignedUsdt($row['average_net_pnl']) }}</td><td>{{ $formatTrade($row['best_trade']) }}</td><td>{{ $formatTrade($row['worst_trade']) }}</td>@endif<td>{{ number_format($row['sl_hit_first']) }}</td><td>{{ number_format($row['recovered']) }}</td><td>{{ $formatPercent($row['recovery_rate']) }}</td></tr>@endforeach</tbody></table></div>@endif
        </div></div>
    @endforeach
@endsection
