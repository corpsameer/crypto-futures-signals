@extends('layouts.app')

@php
    $formatPercent = fn ($value) => $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
    $percentClass = function ($value): string {
        if ($value === null || (float) $value === 0.0) {
            return 'text-muted';
        }

        return (float) $value > 0 ? 'text-success' : 'text-danger';
    };
    $lossClass = fn ($value) => $value === null ? 'text-muted' : ((float) $value < 0 ? 'text-danger' : 'text-success');
@endphp

@section('title', 'Trader Performance | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Trader Performance</h1>
            <p class="text-muted mb-0">Performance summary grouped by trader/channel based on simulated trades and tracking events.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('cryptofuturesignals.trades.index') }}" class="btn btn-outline-primary">Simulated Trades</a>
            <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste Signal</a>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('cryptofuturesignals.traders.index') }}" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="trader_name" class="form-label">Trader</label>
                    <input type="text" name="trader_name" id="trader_name" value="{{ $filters['trader_name'] }}" class="form-control" placeholder="Trader name">
                </div>
                <div class="col-md-2">
                    <label for="symbol" class="form-label">Symbol</label>
                    <input type="text" name="symbol" id="symbol" value="{{ $filters['symbol'] }}" class="form-control" placeholder="BTCUSDT">
                </div>
                <div class="col-md-2">
                    <label for="direction" class="form-label">Direction</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="">All</option>
                        @foreach ($availableDirections as $direction)
                            <option value="{{ $direction }}" @selected($filters['direction'] === $direction)>{{ $direction }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] }}" class="form-control">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="{{ route('cryptofuturesignals.traders.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($traderPerformance->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Trader</th>
                                <th scope="col">Total Signals</th>
                                <th scope="col">Entry Trigger Rate</th>
                                <th scope="col">TP1 Hit Rate</th>
                                <th scope="col">TP2 Hit Rate</th>
                                <th scope="col">TP3 Hit Rate</th>
                                <th scope="col">TP4 Hit Rate</th>
                                <th scope="col">SL Hit Rate</th>
                                <th scope="col">3.5% Gain Hit Rate</th>
                                <th scope="col">Average Max Gain</th>
                                <th scope="col">Average Max Loss</th>
                                <th scope="col">Post-SL Recovery Rate</th>
                                <th scope="col">Best Direction</th>
                                <th scope="col">Best Market Condition</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($traderPerformance as $row)
                                <tr>
                                    <td class="fw-semibold text-nowrap">
                                        @if ($row['is_unknown'])
                                            <span class="badge text-bg-secondary">Unknown</span>
                                        @else
                                            {{ $row['trader'] }}
                                        @endif
                                    </td>
                                    <td>{{ $row['total_signals'] }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['entry_trigger_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['entry_triggered_count'] }} / {{ $row['total_signals'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['tp1_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['tp1_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['tp2_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['tp2_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['tp3_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['tp3_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['tp4_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['tp4_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['sl_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['sl_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td>
                                        <div class="fw-semibold">{{ $formatPercent($row['gain_3_5_hit_rate']) }}</div>
                                        <div class="small text-muted">{{ $row['gain_3_5_hit_count'] }} / {{ $row['triggered_trade_count'] }}</div>
                                    </td>
                                    <td class="fw-semibold {{ $percentClass($row['average_max_gain']) }}">{{ $formatPercent($row['average_max_gain']) }}</td>
                                    <td class="fw-semibold {{ $lossClass($row['average_max_loss']) }}">{{ $formatPercent($row['average_max_loss']) }}</td>
                                    <td>
                                        @if ($row['sl_trade_count'] === 0)
                                            <span class="text-muted">N/A</span>
                                        @else
                                            <div class="fw-semibold">{{ $formatPercent($row['post_sl_recovery_rate']) }}</div>
                                            <div class="small text-muted">{{ $row['recovered_post_sl_count'] }} / {{ $row['sl_trade_count'] }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($row['best_direction'])
                                            <div class="fw-semibold">{{ $row['best_direction']['direction'] }}</div>
                                            <div class="small text-muted">
                                                Avg Max Gain: {{ $formatPercent($row['best_direction']['average_max_gain']) }} | Trades: {{ $row['best_direction']['trade_count'] }}
                                            </div>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($row['best_market_condition'])
                                            <div class="fw-semibold">{{ ucfirst($row['best_market_condition']['market_condition']) }}</div>
                                            <div class="small text-muted">
                                                Avg Max Gain: {{ $formatPercent($row['best_market_condition']['average_max_gain']) }} | Trades: {{ $row['best_market_condition']['trade_count'] }}
                                            </div>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-5 text-center">
                    <h2 class="h5 mb-2">No trader performance data available yet.</h2>
                    <p class="text-muted mb-3">Paste and confirm signals, then run the Python monitor to generate simulated trade data.</p>
                    <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste Signal</a>
                </div>
            @endif
        </div>
    </div>
@endsection
