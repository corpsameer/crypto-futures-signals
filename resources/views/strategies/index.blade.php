@extends('layouts.app')

@section('title', 'Strategy Simulations | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatUsdt = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . ' USDT';
        $formatSignedUsdt = function ($value): string {
            if ($value === null) {
                return 'N/A';
            }

            $prefix = (float) $value > 0 ? '+' : '';

            return $prefix . number_format((float) $value, 2) . ' USDT';
        };
        $formatPercent = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
        $valueClass = function ($value): string {
            if ($value === null || (float) $value === 0.0) {
                return 'text-muted';
            }

            return (float) $value > 0 ? 'text-success' : 'text-danger';
        };
        $formatTrade = function (?array $trade) use ($formatSignedUsdt): string {
            if ($trade === null) {
                return 'N/A';
            }

            $symbol = $trade['symbol'] ? ' (' . $trade['symbol'] . ')' : '';

            return $formatSignedUsdt($trade['net_pnl']) . $symbol;
        };
    @endphp

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Strategy Simulations</h1>
            <p class="text-muted mb-0">Independent virtual-capital performance for the three configured strategies.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="{{ route('cryptofuturesignals.trades.index') }}" class="btn btn-outline-primary">Simulated Trades</a>
            <a href="{{ route('cryptofuturesignals.dashboard') }}" class="btn btn-outline-secondary">Dashboard</a>
        </div>
    </div>

    @include('strategies.partials.filters', [
        'action' => route('cryptofuturesignals.strategies.index'),
        'clearUrl' => route('cryptofuturesignals.strategies.index'),
        'filters' => $filters,
        'filterOptions' => $filterOptions,
        'filtersActive' => $filtersActive,
    ])

    @if ($summaries->isEmpty())
        <div class="card metric-card">
            <div class="card-body p-5 text-center">
                <h2 class="h5 mb-2">No strategies configured yet.</h2>
                <p class="text-muted mb-0">Strategy definitions will appear here after they are seeded.</p>
            </div>
        </div>
    @endif

    <div class="row g-4">
        @foreach ($summaries as $summary)
            @php($strategy = $summary['strategy'])
            <div class="col-12">
                <div class="card metric-card h-100">
                    <div class="card-body">
                        <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
                            <div>
                                <h2 class="h4 mb-2">{{ $strategy->name }}</h2>
                                <div class="d-flex flex-wrap gap-2 align-items-center">
                                    <span class="badge text-bg-dark">{{ $strategy->code }}</span>
                                    <span class="badge {{ $strategy->is_active ? 'text-bg-success' : 'text-bg-secondary' }}">
                                        {{ $strategy->is_active ? 'Active' : 'Inactive' }}
                                    </span>
                                    @if ($summary['ledger_mismatch'])
                                        <span class="badge text-bg-warning">Ledger history differs</span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-lg-end">
                                <div class="text-muted small text-uppercase fw-semibold">Allocation</div>
                                <div class="h5 mb-2">{{ $formatPercent($summary['allocation_percent']) }}</div>
                                <a href="{{ route('cryptofuturesignals.strategies.show', $strategy) }}" class="btn btn-sm btn-outline-primary">View details</a>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <div class="col-sm-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Starting Capital</div><div class="fs-5 fw-semibold">{{ $formatUsdt($summary['starting_capital']) }}</div></div></div>
                            <div class="col-sm-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Current Capital</div><div class="fs-5 fw-semibold">{{ $formatUsdt($summary['current_capital']) }}</div></div></div>
                            <div class="col-sm-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">{{ $filtersActive ? 'Account Net P&L' : 'Net P&L' }}</div><div class="fs-5 fw-semibold {{ $valueClass($summary['net_pnl']) }}">{{ $formatSignedUsdt($summary['net_pnl']) }}</div></div></div>
                            <div class="col-sm-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">{{ $filtersActive ? 'Account Return' : 'Return' }}</div><div class="fs-5 fw-semibold {{ $valueClass($summary['return_percent']) }}">{{ $formatPercent($summary['return_percent']) }}</div></div></div>
                            @if ($filtersActive)
                                <div class="col-sm-6 col-xl-3"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Filtered Net P&amp;L</div><div class="fs-5 fw-semibold {{ $valueClass($summary['filtered_net_pnl']) }}">{{ $formatSignedUsdt($summary['filtered_net_pnl']) }}</div></div></div>
                            @endif
                        </div>

                        <div class="table-responsive mb-4">
                            <table class="table table-sm table-striped align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Total</th><th>Wins</th><th>Losses</th><th>Open</th><th>Skipped</th><th>Win Rate</th><th>Avg Win</th><th>Avg Loss</th><th>Best Trade</th><th>Worst Trade</th><th>Max Drawdown</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td class="fw-semibold">{{ number_format($summary['total_trades']) }}</td>
                                        <td><span class="badge text-bg-success">{{ number_format($summary['wins']) }}</span></td>
                                        <td><span class="badge text-bg-danger">{{ number_format($summary['losses']) }}</span></td>
                                        <td><span class="badge text-bg-primary">{{ number_format($summary['open_trades']) }}</span></td>
                                        <td><span class="badge text-bg-secondary">{{ number_format($summary['skipped_trades']) }}</span></td>
                                        <td class="fw-semibold">{{ $formatPercent($summary['win_rate']) }}</td>
                                        <td class="fw-semibold text-success">{{ $formatSignedUsdt($summary['average_win']) }}</td>
                                        <td class="fw-semibold text-danger">{{ $formatSignedUsdt($summary['average_loss']) }}</td>
                                        <td class="fw-semibold {{ $summary['best_trade'] ? $valueClass($summary['best_trade']['net_pnl']) : 'text-muted' }}">{{ $formatTrade($summary['best_trade']) }}</td>
                                        <td class="fw-semibold {{ $summary['worst_trade'] ? $valueClass($summary['worst_trade']['net_pnl']) : 'text-muted' }}">{{ $formatTrade($summary['worst_trade']) }}</td>
                                        <td>{{ $formatUsdt($summary['max_drawdown']['amount']) }} ({{ $formatPercent($summary['max_drawdown']['percent']) }})</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="row g-3">
                            <div class="col-sm-4"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">SL Hit First</div><div class="fs-5 fw-semibold text-danger">{{ number_format($summary['sl_hit_first_count']) }}</div></div></div>
                            <div class="col-sm-4"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Post-SL Recoveries</div><div class="fs-5 fw-semibold text-success">{{ number_format($summary['post_sl_recovery_count']) }}</div></div></div>
                            <div class="col-sm-4"><div class="border rounded p-3 h-100"><div class="small text-muted text-uppercase fw-semibold">Recovery Rate</div><div class="fs-5 fw-semibold">{{ $formatPercent($summary['post_sl_recovery_rate']) }}</div></div></div>
                        </div>
                    </div>
                </div>
            </div>
        @endforeach
    </div>
@endsection
