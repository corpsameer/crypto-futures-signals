@extends('layouts.app')

@php
    $directionBadgeClasses = [
        \App\Models\TradeSignal::DIRECTION_LONG => 'text-bg-success',
        \App\Models\TradeSignal::DIRECTION_SHORT => 'text-bg-danger',
    ];

    $statusBadgeClasses = [
        \App\Models\TradeSignal::STATUS_PENDING_ENTRY => 'text-bg-secondary',
        \App\Models\TradeSignal::STATUS_ENTRY_TRIGGERED => 'text-bg-primary',
        \App\Models\TradeSignal::STATUS_ACTIVE => 'text-bg-primary',
        \App\Models\TradeSignal::STATUS_CLOSED_TP => 'text-bg-success',
        \App\Models\TradeSignal::STATUS_COMPLETED => 'text-bg-success',
        \App\Models\TradeSignal::STATUS_CLOSED_SL => 'text-bg-danger',
        \App\Models\TradeSignal::STATUS_EXPIRED => 'text-bg-dark',
        \App\Models\TradeSignal::STATUS_ENTRY_MISSED => 'text-bg-warning',
        \App\Models\TradeSignal::STATUS_INVALID => 'text-bg-dark',
        \App\Models\TradeSignal::STATUS_TRACKING_AFTER_SL => 'text-bg-warning',
    ];
@endphp

@section('title', 'Trade Signal Detail | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Trade Signal Detail</h1>
            <p class="text-muted mb-0">Structured signal #{{ $tradeSignal->id }} for {{ $tradeSignal->symbol ?: 'N/A' }}.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="{{ route('cryptofuturesignals.trade-signals.index') }}" class="btn btn-outline-secondary">Back to Structured Signals</a>
            @if ($tradeSignal->pastedSignal)
                <a href="{{ route('cryptofuturesignals.signals.preview', $tradeSignal->pastedSignal) }}" class="btn btn-primary">Edit Parsed Signal</a>
            @endif
        </div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-lg-6">
            <div class="card metric-card h-100">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">Main Signal Summary</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Symbol</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->symbol ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Pair</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->pair ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Direction</dt>
                        <dd class="col-sm-8">
                            <span class="badge {{ $directionBadgeClasses[$tradeSignal->direction] ?? 'text-bg-secondary' }}">
                                {{ $tradeSignal->direction ?: 'N/A' }}
                            </span>
                        </dd>

                        <dt class="col-sm-4">Leverage</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->leverage !== null ? rtrim(rtrim((string) $tradeSignal->leverage, '0'), '.') . 'x' : 'N/A' }}</dd>

                        <dt class="col-sm-4">Trader</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->trader_name ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Exchange</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->exchange ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Market Type</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->market_type ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Status</dt>
                        <dd class="col-sm-8">
                            <span class="badge {{ $statusBadgeClasses[$tradeSignal->status] ?? 'text-bg-secondary' }}">
                                {{ $tradeSignal->status ?: 'N/A' }}
                            </span>
                        </dd>

                        <dt class="col-sm-4">Signal Time</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->signal_time?->format('Y-m-d H:i') ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Expires At</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->expires_at?->format('Y-m-d H:i') ?? 'N/A' }}</dd>
                    </dl>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card metric-card h-100">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">Price Levels</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Entry Min</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->entry_min ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Entry Max</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->entry_max ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">Entry Type</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->entry_type ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Stop Loss</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->stop_loss ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">TP1</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->tp1 ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">TP2</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->tp2 ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">TP3</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->tp3 ?? 'N/A' }}</dd>

                        <dt class="col-sm-4">TP4</dt>
                        <dd class="col-sm-8">{{ $tradeSignal->tp4 ?? 'N/A' }}</dd>
                    </dl>
                </div>
            </div>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Notes</h2>
        </div>
        <div class="card-body">
            <p class="mb-0" style="white-space: pre-line;">{{ $tradeSignal->notes ?: 'N/A' }}</p>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white d-flex justify-content-between align-items-center gap-3">
            <h2 class="h5 mb-0">Original Pasted Signal</h2>
            @if ($tradeSignal->pastedSignal)
                <a href="{{ route('cryptofuturesignals.signals.preview', $tradeSignal->pastedSignal) }}" class="btn btn-sm btn-outline-primary">Review/Edit Parsed Signal</a>
            @endif
        </div>
        <div class="card-body">
            @if ($tradeSignal->pastedSignal)
                <dl class="row">
                    <dt class="col-sm-2">Trader</dt>
                    <dd class="col-sm-10">{{ $tradeSignal->pastedSignal->trader_name ?: 'N/A' }}</dd>

                    <dt class="col-sm-2">Pasted At</dt>
                    <dd class="col-sm-10">{{ $tradeSignal->pastedSignal->pasted_at?->format('Y-m-d H:i') ?? 'N/A' }}</dd>
                </dl>
                <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;">{{ $tradeSignal->pastedSignal->raw_text }}</pre>
            @else
                <p class="text-muted mb-0">No original pasted signal is linked to this structured signal.</p>
            @endif
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Simulated Trades</h2>
        </div>
        <div class="card-body p-0">
            @if ($tradeSignal->simulatedTrades->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Status</th>
                                <th scope="col">Entry Price</th>
                                <th scope="col">Current Price</th>
                                <th scope="col">Max Leveraged P&amp;L</th>
                                <th scope="col">Min Leveraged P&amp;L</th>
                                <th scope="col">Closed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tradeSignal->simulatedTrades as $simulatedTrade)
                                <tr>
                                    <td>{{ $simulatedTrade->id }}</td>
                                    <td>{{ $simulatedTrade->status ?: 'N/A' }}</td>
                                    <td>{{ $simulatedTrade->entry_price ?? 'N/A' }}</td>
                                    <td>{{ $simulatedTrade->current_price ?? 'N/A' }}</td>
                                    <td>{{ $simulatedTrade->max_leveraged_pnl_percent !== null ? $simulatedTrade->max_leveraged_pnl_percent . '%' : 'N/A' }}</td>
                                    <td>{{ $simulatedTrade->min_leveraged_pnl_percent !== null ? $simulatedTrade->min_leveraged_pnl_percent . '%' : 'N/A' }}</td>
                                    <td>{{ $simulatedTrade->closed_at?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-muted">
                    No simulated trade created yet. Monitoring will be added in a later task.
                </div>
            @endif
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Tracking Events</h2>
        </div>
        <div class="card-body p-0">
            @if ($tradeSignal->trackingEvents->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">Event Type</th>
                                <th scope="col">Event Price</th>
                                <th scope="col">Actual Move %</th>
                                <th scope="col">Leveraged P&amp;L %</th>
                                <th scope="col">Event Timestamp</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tradeSignal->trackingEvents as $trackingEvent)
                                <tr>
                                    <td>{{ $trackingEvent->event_type }}</td>
                                    <td>{{ $trackingEvent->event_price ?? 'N/A' }}</td>
                                    <td>{{ $trackingEvent->actual_price_move_percent !== null ? $trackingEvent->actual_price_move_percent . '%' : 'N/A' }}</td>
                                    <td>{{ $trackingEvent->leveraged_pnl_percent !== null ? $trackingEvent->leveraged_pnl_percent . '%' : 'N/A' }}</td>
                                    <td>{{ $trackingEvent->event_timestamp?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-muted">
                    No tracking events yet.
                </div>
            @endif
        </div>
    </div>
@endsection
