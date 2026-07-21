@extends('layouts.app')

@section('title', 'Strategy Backtest Runs | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatUsdt = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . ' USDT';
        $formatSignedUsdt = function ($value): string {
            if ($value === null) {
                return 'N/A';
            }

            return ((float) $value > 0 ? '+' : '') . number_format((float) $value, 2) . ' USDT';
        };
        $formatDate = fn ($value, $fallback = 'N/A') => $value ? $value->timezone(config('app.timezone'))->format('Y-m-d H:i:s T') : $fallback;
        $statusBadgeClasses = ['completed' => 'text-bg-success', 'running' => 'text-bg-info', 'failed' => 'text-bg-danger'];
        $valueClass = function ($value): string {
            if ($value === null || (float) $value === 0.0) {
                return 'text-muted';
            }

            return (float) $value > 0 ? 'text-success' : 'text-danger';
        };
    @endphp

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Strategy Backtest Runs</h1>
            <p class="text-muted mb-0">Historical backtest runs. Select a run to view its run-specific strategy results.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="{{ route('cryptofuturesignals.strategies.index') }}" class="btn btn-outline-primary">Strategies</a>
            <a href="{{ route('cryptofuturesignals.dashboard') }}" class="btn btn-outline-secondary">Dashboard</a>
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($backtestRuns->count() === 0)
                <div class="p-5 text-center text-muted">
                    <h2 class="h5 mb-2">No backtest runs found.</h2>
                    <p class="mb-0">Backtest runs will appear here after the backtest command stores them.</p>
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Status</th>
                                <th>Started At</th>
                                <th>Completed At</th>
                                <th>Starting Capital</th>
                                <th>Strategies</th>
                                <th>Processed Trades</th>
                                <th>Result Rows</th>
                                <th>Net P&amp;L</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($backtestRuns as $run)
                                <tr>
                                    <td class="fw-semibold">#{{ $run->id }}</td>
                                    <td>
                                        <a href="{{ route('strategy-backtests.show', $run) }}" class="fw-semibold text-decoration-none">{{ $run->name }}</a>
                                        @if (trim((string) $run->notes) !== '')
                                            <div class="small text-muted text-truncate" style="max-width: 24rem;">{{ $run->notes }}</div>
                                        @endif
                                    </td>
                                    <td><span class="badge {{ $statusBadgeClasses[$run->status] ?? 'text-bg-secondary' }}">{{ strtoupper(trim((string) $run->status) === '' ? 'unknown' : $run->status) }}</span></td>
                                    <td class="text-nowrap">{{ $formatDate($run->started_at) }}</td>
                                    <td class="text-nowrap">{{ $formatDate($run->completed_at, 'Not completed') }}</td>
                                    <td>{{ $formatUsdt($run->starting_capital) }}</td>
                                    <td>{{ number_format((int) $run->strategies_represented_count) }}</td>
                                    <td>{{ number_format((int) $run->processed_trades_count) }}</td>
                                    <td>{{ number_format((int) $run->result_rows_count) }}</td>
                                    <td class="fw-semibold {{ $valueClass($run->total_net_pnl) }}">{{ $formatSignedUsdt($run->total_net_pnl) }}</td>
                                    <td class="text-end"><a href="{{ route('strategy-backtests.show', $run) }}" class="btn btn-sm btn-outline-primary">View Run</a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>

    @if ($backtestRuns->hasPages())
        <div class="mt-4">{{ $backtestRuns->links() }}</div>
    @endif
@endsection
