@extends('layouts.app')

@php
    $directionBadgeClasses = [
        \App\Models\TradeSignal::DIRECTION_LONG => 'text-bg-success',
        \App\Models\TradeSignal::DIRECTION_SHORT => 'text-bg-danger',
    ];

    $statusBadgeClasses = [
        \App\Models\SimulatedTrade::STATUS_ACTIVE => 'text-bg-primary',
        \App\Models\SimulatedTrade::STATUS_CLOSED_TP => 'text-bg-success',
        \App\Models\SimulatedTrade::STATUS_CLOSED_SL => 'text-bg-danger',
        \App\Models\SimulatedTrade::STATUS_TRACKING_AFTER_SL => 'text-bg-warning',
        \App\Models\SimulatedTrade::STATUS_EXPIRED => 'text-bg-dark',
        \App\Models\SimulatedTrade::STATUS_COMPLETED => 'text-bg-success',
    ];

    $eventColumns = [
        'TP1 Hit' => \App\Models\TradeTrackingEvent::EVENT_TP1_HIT,
        'TP2 Hit' => \App\Models\TradeTrackingEvent::EVENT_TP2_HIT,
        'TP3 Hit' => \App\Models\TradeTrackingEvent::EVENT_TP3_HIT,
        'TP4 Hit' => \App\Models\TradeTrackingEvent::EVENT_TP4_HIT,
        'SL Hit' => \App\Models\TradeTrackingEvent::EVENT_SL_HIT,
        '3% Gain' => \App\Models\TradeTrackingEvent::EVENT_GAIN_3_PERCENT,
        '3.5% Gain' => \App\Models\TradeTrackingEvent::EVENT_GAIN_3_5_PERCENT,
        '5% Gain' => \App\Models\TradeTrackingEvent::EVENT_GAIN_5_PERCENT,
        '7% Gain' => \App\Models\TradeTrackingEvent::EVENT_GAIN_7_PERCENT,
    ];

    $formatPercent = fn ($value) => $value === null ? 'N/A' : rtrim(rtrim(number_format((float) $value, 4), '0'), '.').'%';
@endphp

@section('title', 'Simulated Trades | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Simulated Trades</h1>
            <p class="text-muted mb-0">Tracked simulated trades with TP/SL/gain milestone events.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('cryptofuturesignals.trade-signals.index') }}" class="btn btn-outline-primary">Structured Signals</a>
            <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste Signal</a>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('cryptofuturesignals.trades.index') }}" class="row g-3 align-items-end">
                <div class="col-md-2">
                    <label for="date_from" class="form-label">Date From</label>
                    <input type="date" name="date_from" id="date_from" value="{{ $filters['date_from'] }}" class="form-control">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">Date To</label>
                    <input type="date" name="date_to" id="date_to" value="{{ $filters['date_to'] }}" class="form-control">
                </div>
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
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All</option>
                        @foreach ($availableStatuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="market_condition" class="form-label">Market condition</label>
                    <select name="market_condition" id="market_condition" class="form-select">
                        <option value="">All</option>
                        @foreach ($availableMarketConditions as $marketCondition)
                            <option value="{{ $marketCondition }}" @selected($filters['market_condition'] === $marketCondition)>{{ $marketCondition }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="{{ route('cryptofuturesignals.trades.index') }}" class="btn btn-outline-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($trades->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Signal Time</th>
                                <th scope="col">Symbol</th>
                                <th scope="col">Direction</th>
                                <th scope="col">Trader</th>
                                <th scope="col">Entry Price</th>
                                <th scope="col">Entry Triggered At</th>
                                @foreach ($eventColumns as $label => $eventType)
                                    <th scope="col">{{ $label }}</th>
                                @endforeach
                                <th scope="col">Max Gain %</th>
                                <th scope="col">Max Loss %</th>
                                <th scope="col">Final Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($trades as $trade)
                                @php
                                    $events = $trade->trackingEvents->keyBy('event_type');
                                    $entryEvent = $events->get(\App\Models\TradeTrackingEvent::EVENT_ENTRY_TRIGGERED);
                                    $signalTime = $trade->tradeSignal?->signal_time ?? $trade->entry_triggered_at ?? $trade->created_at;
                                    $latestSnapshot = $trade->marketSnapshots
                                        ->sortByDesc(fn ($snapshot) => $snapshot->captured_at ?? $snapshot->snapshot_at ?? $snapshot->created_at)
                                        ->first();
                                    $maxGain = $trade->max_gain_percent ?? $trade->max_leveraged_pnl_percent;
                                    $maxLoss = $trade->max_loss_percent ?? $trade->min_leveraged_pnl_percent;
                                @endphp
                                <tr>
                                    <td class="text-nowrap">{{ $signalTime?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                                    <td>
                                        @if ($trade->tradeSignal)
                                            <a href="{{ route('cryptofuturesignals.trade-signals.show', $trade->tradeSignal) }}" class="fw-semibold text-decoration-none">
                                                {{ $trade->symbol }}
                                            </a>
                                        @else
                                            <span class="fw-semibold">{{ $trade->symbol }}</span>
                                        @endif

                                        @if ($trade->tradeSignal?->pair)
                                            <div class="small text-muted">{{ $trade->tradeSignal->pair }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $directionBadgeClasses[$trade->direction] ?? 'text-bg-secondary' }}">
                                            {{ $trade->direction }}
                                        </span>
                                    </td>
                                    <td>{{ $trade->tradeSignal?->trader_name ?: 'N/A' }}</td>
                                    <td class="text-nowrap">
                                        <div>{{ $trade->entry_price ?? 'N/A' }}</div>
                                        @if ($trade->current_price !== null)
                                            <div class="small text-muted">Current: {{ $trade->current_price }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <div>{{ $trade->entry_triggered_at?->format('Y-m-d H:i') ?? 'N/A' }}</div>
                                        @if ($entryEvent)
                                            <div class="mt-1">
                                                @include('trades.partials.event-cell', ['event' => $entryEvent])
                                            </div>
                                        @endif
                                    </td>
                                    @foreach ($eventColumns as $label => $eventType)
                                        <td>
                                            @include('trades.partials.event-cell', ['event' => $events->get($eventType)])
                                        </td>
                                    @endforeach
                                    <td class="text-nowrap">
                                        <div>{{ $formatPercent($maxGain) }}</div>
                                        @if ($trade->max_actual_gain_percent !== null)
                                            <div class="small text-muted">Actual: {{ $formatPercent($trade->max_actual_gain_percent) }}</div>
                                        @endif
                                    </td>
                                    <td class="text-nowrap">
                                        <div>{{ $formatPercent($maxLoss) }}</div>
                                        @if ($trade->max_actual_loss_percent !== null)
                                            <div class="small text-muted">Actual: {{ $formatPercent($trade->max_actual_loss_percent) }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadgeClasses[$trade->status] ?? 'text-bg-secondary' }}">
                                            {{ $trade->status }}
                                        </span>
                                        <div class="small text-muted mt-1">
                                            Market: {{ $latestSnapshot?->market_condition ?: 'N/A' }}
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-5 text-center text-muted">
                    <p class="mb-1">No simulated trades found.</p>
                    <p class="mb-0">Once Python monitor triggers entries, simulated trades will appear here.</p>
                </div>
            @endif
        </div>
    </div>

    @if ($trades->hasPages())
        <div class="mt-4">
            {{ $trades->links() }}
        </div>
    @endif
@endsection
