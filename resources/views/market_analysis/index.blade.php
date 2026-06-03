@extends('layouts.app')

@section('title', 'Market Condition Analysis | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatPercent = function ($value): string {
            return $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
        };

        $percentClass = function ($value, bool $negativeIsBad = false): string {
            if ($value === null) {
                return 'text-muted';
            }

            if ((float) $value > 0) {
                return $negativeIsBad ? 'text-success' : 'text-success';
            }

            if ((float) $value < 0) {
                return 'text-danger';
            }

            return 'text-muted';
        };

        $conditionBadgeClasses = [
            'bullish' => 'text-bg-success',
            'bearish' => 'text-bg-danger',
            'sideways' => 'text-bg-info',
            'unknown' => 'text-bg-secondary',
        ];

        $renderRate = function (array $row, string $rateKey, string $countKey) use ($formatPercent): string {
            return $formatPercent($row[$rateKey] ?? null) . '|' . number_format($row[$countKey] ?? 0) . ' / ' . number_format($row['total_trades'] ?? 0);
        };
    @endphp

    <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Market Condition Analysis</h1>
            <p class="text-muted mb-0">Performance summary grouped by BTC/ETH market condition captured during signal tracking.</p>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-start">
            <a href="{{ route('cryptofuturesignals.trades.index') }}" class="btn btn-outline-primary">Simulated Trades</a>
            <a href="{{ route('cryptofuturesignals.traders.index') }}" class="btn btn-outline-primary">Trader Performance</a>
            <a href="{{ route('cryptofuturesignals.dashboard') }}" class="btn btn-outline-secondary">Dashboard</a>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('cryptofuturesignals.market-analysis.index') }}" class="row g-3 align-items-end">
                <div class="col-md-3 col-xl-2">
                    <label for="market_condition" class="form-label">Market Condition</label>
                    <select id="market_condition" name="market_condition" class="form-select">
                        <option value="">All</option>
                        @foreach ($availableMarketConditions as $marketCondition)
                            <option value="{{ $marketCondition }}" @selected($filters['market_condition'] === $marketCondition)>{{ ucfirst($marketCondition) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-xl-2">
                    <label for="direction" class="form-label">Direction</label>
                    <select id="direction" name="direction" class="form-select">
                        <option value="">All</option>
                        @foreach ($availableDirections as $direction)
                            <option value="{{ $direction }}" @selected($filters['direction'] === $direction)>{{ $direction }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 col-xl-2">
                    <label for="symbol" class="form-label">Symbol</label>
                    <input id="symbol" type="text" name="symbol" value="{{ $filters['symbol'] }}" class="form-control" placeholder="BTCUSDT">
                </div>
                <div class="col-md-3 col-xl-2">
                    <label for="trader_name" class="form-label">Trader</label>
                    <input id="trader_name" type="text" name="trader_name" value="{{ $filters['trader_name'] }}" class="form-control" placeholder="Trader name">
                </div>
                <div class="col-md-3 col-xl-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input id="date_from" type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-control">
                </div>
                <div class="col-md-3 col-xl-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input id="date_to" type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-control">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="{{ route('cryptofuturesignals.market-analysis.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($marketAnalysis->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Market Condition</th>
                                <th scope="col">Total Trades</th>
                                <th scope="col">TP1 Hit %</th>
                                <th scope="col">TP2 Hit %</th>
                                <th scope="col">TP3 Hit %</th>
                                <th scope="col">TP4 Hit %</th>
                                <th scope="col">SL Hit %</th>
                                <th scope="col">3.5% Gain Hit %</th>
                                <th scope="col">Average Max Gain</th>
                                <th scope="col">Average Max Loss</th>
                                <th scope="col">Best Direction</th>
                                <th scope="col">Worst Direction</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($marketAnalysis as $row)
                                @php
                                    [$tp1Rate, $tp1Count] = explode('|', $renderRate($row, 'tp1_hit_rate', 'tp1_hit_count'));
                                    [$tp2Rate, $tp2Count] = explode('|', $renderRate($row, 'tp2_hit_rate', 'tp2_hit_count'));
                                    [$tp3Rate, $tp3Count] = explode('|', $renderRate($row, 'tp3_hit_rate', 'tp3_hit_count'));
                                    [$tp4Rate, $tp4Count] = explode('|', $renderRate($row, 'tp4_hit_rate', 'tp4_hit_count'));
                                    [$slRate, $slCount] = explode('|', $renderRate($row, 'sl_hit_rate', 'sl_hit_count'));
                                    [$gain35Rate, $gain35Count] = explode('|', $renderRate($row, 'gain_3_5_hit_rate', 'gain_3_5_hit_count'));
                                @endphp
                                <tr>
                                    <td>
                                        <span class="badge {{ $conditionBadgeClasses[$row['market_condition']] ?? 'text-bg-secondary' }}">
                                            {{ ucfirst($row['market_condition']) }}
                                        </span>
                                    </td>
                                    <td class="fw-semibold">{{ number_format($row['total_trades']) }}</td>
                                    <td class="text-nowrap"><div>{{ $tp1Rate }}</div><div class="small text-muted">{{ $tp1Count }}</div></td>
                                    <td class="text-nowrap"><div>{{ $tp2Rate }}</div><div class="small text-muted">{{ $tp2Count }}</div></td>
                                    <td class="text-nowrap"><div>{{ $tp3Rate }}</div><div class="small text-muted">{{ $tp3Count }}</div></td>
                                    <td class="text-nowrap"><div>{{ $tp4Rate }}</div><div class="small text-muted">{{ $tp4Count }}</div></td>
                                    <td class="text-nowrap"><div>{{ $slRate }}</div><div class="small text-muted">{{ $slCount }}</div></td>
                                    <td class="text-nowrap"><div>{{ $gain35Rate }}</div><div class="small text-muted">{{ $gain35Count }}</div></td>
                                    <td class="text-nowrap fw-semibold {{ $percentClass($row['average_max_gain']) }}">{{ $formatPercent($row['average_max_gain']) }}</td>
                                    <td class="text-nowrap fw-semibold {{ $percentClass($row['average_max_loss'], true) }}">{{ $formatPercent($row['average_max_loss']) }}</td>
                                    <td class="text-nowrap">
                                        @if ($row['best_direction'])
                                            <div class="fw-semibold">{{ $row['best_direction']['direction'] }}</div>
                                            <div class="small text-muted">
                                                Avg Max Gain: {{ $formatPercent($row['best_direction']['average_max_gain']) }} | Trades: {{ number_format($row['best_direction']['trade_count']) }}
                                            </div>
                                        @else
                                            <span class="text-muted">N/A</span>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        @if ($row['worst_direction'])
                                            <div class="fw-semibold">{{ $row['worst_direction']['direction'] }}</div>
                                            <div class="small text-muted">
                                                Avg Max Loss: {{ $formatPercent($row['worst_direction']['average_max_loss']) }} | Trades: {{ number_format($row['worst_direction']['trade_count']) }}
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
                    <h2 class="h5 mb-2">No market condition data available yet.</h2>
                    <p class="text-muted mb-0">Market snapshots are created when entries trigger and trades close. Run the Python monitor after saving signals.</p>
                </div>
            @endif
        </div>
    </div>
@endsection
