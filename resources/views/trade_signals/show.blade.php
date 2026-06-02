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
        \App\Models\TradeSignal::STATUS_TRACKING_AFTER_SL => 'text-bg-warning',
        \App\Models\TradeSignal::STATUS_EXPIRED => 'text-bg-dark',
        \App\Models\TradeSignal::STATUS_ENTRY_MISSED => 'text-bg-warning',
        \App\Models\TradeSignal::STATUS_INVALID => 'text-bg-dark',
    ];

    $display = fn ($value) => $value !== null && $value !== '' ? $value : 'N/A';
    $dateDisplay = fn ($date) => $date ? $date->format('Y-m-d H:i') : 'N/A';
    $percentDisplay = fn ($value) => $value !== null ? number_format((float) $value, 2) . '%' : 'N/A';
    $leverageDisplay = fn ($value) => $value !== null ? rtrim(rtrim((string) $value, '0'), '.') . 'x' : 'N/A';

    $entryMin = $tradeSignal->entry_min !== null ? (float) $tradeSignal->entry_min : null;
    $entryMax = $tradeSignal->entry_max !== null ? (float) $tradeSignal->entry_max : null;
    $approxEntry = null;

    if ($entryMin !== null && $entryMax !== null) {
        $approxEntry = ($entryMin + $entryMax) / 2;
    } elseif ($entryMin !== null) {
        $approxEntry = $entryMin;
    } elseif ($entryMax !== null) {
        $approxEntry = $entryMax;
    }

    $stopLoss = $tradeSignal->stop_loss !== null ? (float) $tradeSignal->stop_loss : null;
    $leverage = $tradeSignal->leverage !== null ? (float) $tradeSignal->leverage : null;
    $canCalculateRiskReward = $approxEntry !== null
        && $approxEntry > 0
        && $stopLoss !== null
        && in_array($tradeSignal->direction, [\App\Models\TradeSignal::DIRECTION_LONG, \App\Models\TradeSignal::DIRECTION_SHORT], true);
    $riskMove = null;
    $tpMoves = [];

    if ($canCalculateRiskReward) {
        if ($tradeSignal->direction === \App\Models\TradeSignal::DIRECTION_LONG) {
            $riskMove = (($approxEntry - $stopLoss) / $approxEntry) * 100;
        } else {
            $riskMove = (($stopLoss - $approxEntry) / $approxEntry) * 100;
        }

        foreach (['tp1', 'tp2', 'tp3', 'tp4'] as $tpField) {
            $tp = $tradeSignal->{$tpField} !== null ? (float) $tradeSignal->{$tpField} : null;

            if ($tp === null) {
                $tpMoves[$tpField] = null;
                continue;
            }

            $tpMoves[$tpField] = $tradeSignal->direction === \App\Models\TradeSignal::DIRECTION_LONG
                ? (($tp - $approxEntry) / $approxEntry) * 100
                : (($approxEntry - $tp) / $approxEntry) * 100;
        }
    }

    $parserPayloadJson = $tradeSignal->pastedSignal?->parsed_payload
        ? json_encode($tradeSignal->pastedSignal->parsed_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        : null;
@endphp

@section('title', 'Signal Detail | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Signal Detail</h1>
            <p class="text-muted mb-0">Structured signal #{{ $tradeSignal->id }} for {{ $tradeSignal->symbol ?: 'N/A' }}.</p>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="{{ route('cryptofuturesignals.trade-signals.index') }}" class="btn btn-outline-secondary">Back to Structured Signals</a>
            @if ($tradeSignal->pastedSignal)
                <a href="{{ route('cryptofuturesignals.signals.preview', $tradeSignal->pastedSignal) }}" class="btn btn-primary">Edit Parsed Signal</a>
            @endif
            <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-outline-primary">Paste New Signal</a>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Header Summary</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-2"><div class="text-muted small">Symbol</div><div class="fw-semibold">{{ $display($tradeSignal->symbol) }}</div></div>
                <div class="col-md-2"><div class="text-muted small">Pair</div><div class="fw-semibold">{{ $display($tradeSignal->pair) }}</div></div>
                <div class="col-md-2">
                    <div class="text-muted small">Direction</div>
                    <span class="badge {{ $directionBadgeClasses[$tradeSignal->direction] ?? 'text-bg-secondary' }}">{{ $display($tradeSignal->direction) }}</span>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Status</div>
                    <span class="badge {{ $statusBadgeClasses[$tradeSignal->status] ?? 'text-bg-secondary' }}">{{ $display($tradeSignal->status) }}</span>
                </div>
                <div class="col-md-2"><div class="text-muted small">Trader Name</div><div class="fw-semibold">{{ $display($tradeSignal->trader_name) }}</div></div>
                <div class="col-md-2"><div class="text-muted small">Created At</div><div class="fw-semibold">{{ $dateDisplay($tradeSignal->created_at) }}</div></div>
            </div>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Structured Signal Details</h2>
        </div>
        <div class="card-body">
            <dl class="row mb-0">
                <dt class="col-md-3">Exchange</dt><dd class="col-md-3">{{ $display($tradeSignal->exchange) }}</dd>
                <dt class="col-md-3">Market Type</dt><dd class="col-md-3">{{ $display($tradeSignal->market_type) }}</dd>
                <dt class="col-md-3">Direction</dt><dd class="col-md-3">{{ $display($tradeSignal->direction) }}</dd>
                <dt class="col-md-3">Leverage</dt><dd class="col-md-3">{{ $leverageDisplay($tradeSignal->leverage) }}</dd>
                <dt class="col-md-3">Margin Mode</dt><dd class="col-md-3">{{ $display($tradeSignal->margin_mode) }}</dd>
                <dt class="col-md-3">Entry Type</dt><dd class="col-md-3">{{ $display($tradeSignal->entry_type) }}</dd>
                <dt class="col-md-3">Entry Min</dt><dd class="col-md-3">{{ $display($tradeSignal->entry_min) }}</dd>
                <dt class="col-md-3">Entry Max</dt><dd class="col-md-3">{{ $display($tradeSignal->entry_max) }}</dd>
                <dt class="col-md-3">Stop Loss</dt><dd class="col-md-3">{{ $display($tradeSignal->stop_loss) }}</dd>
                <dt class="col-md-3">TP1</dt><dd class="col-md-3">{{ $display($tradeSignal->tp1) }}</dd>
                <dt class="col-md-3">TP2</dt><dd class="col-md-3">{{ $display($tradeSignal->tp2) }}</dd>
                <dt class="col-md-3">TP3</dt><dd class="col-md-3">{{ $display($tradeSignal->tp3) }}</dd>
                <dt class="col-md-3">TP4</dt><dd class="col-md-3">{{ $display($tradeSignal->tp4) }}</dd>
                <dt class="col-md-3">Signal Time</dt><dd class="col-md-3">{{ $dateDisplay($tradeSignal->signal_time) }}</dd>
                <dt class="col-md-3">Expires At</dt><dd class="col-md-3">{{ $dateDisplay($tradeSignal->expires_at) }}</dd>
                <dt class="col-md-3">Notes</dt><dd class="col-md-9" style="white-space: pre-line;">{{ $display($tradeSignal->notes) }}</dd>
            </dl>
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white">
            <h2 class="h5 mb-0">Risk/Reward Preview</h2>
        </div>
        <div class="card-body">
            @if ($canCalculateRiskReward)
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th scope="col">Level</th>
                                <th scope="col">Move %</th>
                                <th scope="col">Leveraged Estimate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <th scope="row">Approx Entry Used</th>
                                <td colspan="2">{{ rtrim(rtrim(number_format($approxEntry, 12, '.', ''), '0'), '.') }}</td>
                            </tr>
                            <tr>
                                <th scope="row">Stop Loss Move %</th>
                                <td>{{ $percentDisplay($riskMove) }}</td>
                                <td>{{ $leverage !== null ? $percentDisplay($riskMove * $leverage) : 'N/A' }}</td>
                            </tr>
                            @foreach (['tp1' => 'TP1 Move %', 'tp2' => 'TP2 Move %', 'tp3' => 'TP3 Move %', 'tp4' => 'TP4 Move %'] as $tpField => $label)
                                <tr>
                                    <th scope="row">{{ $label }}</th>
                                    <td>{{ $percentDisplay($tpMoves[$tpField] ?? null) }}</td>
                                    <td>{{ $leverage !== null && ($tpMoves[$tpField] ?? null) !== null ? $percentDisplay($tpMoves[$tpField] * $leverage) : 'N/A' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-muted mb-0">Risk/reward preview unavailable.</p>
            @endif
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
                    <dt class="col-md-2">Pasted Signal ID</dt><dd class="col-md-10">{{ $tradeSignal->pastedSignal->id }}</dd>
                    <dt class="col-md-2">Trader Name</dt><dd class="col-md-10">{{ $display($tradeSignal->pastedSignal->trader_name) }}</dd>
                    <dt class="col-md-2">Parse Status</dt><dd class="col-md-10">{{ $display($tradeSignal->pastedSignal->parse_status) }}</dd>
                    <dt class="col-md-2">Source</dt><dd class="col-md-10">{{ $display($tradeSignal->pastedSignal->source) }}</dd>
                    <dt class="col-md-2">Pasted At</dt><dd class="col-md-10">{{ $dateDisplay($tradeSignal->pastedSignal->pasted_at) }}</dd>
                </dl>
                <pre class="bg-light border rounded p-3 mb-0" style="white-space: pre-wrap;">{{ $tradeSignal->pastedSignal->raw_text }}</pre>
            @else
                <p class="text-muted mb-0">No original pasted signal linked.</p>
            @endif
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-body">
            @if ($parserPayloadJson)
                <details>
                    <summary class="fw-semibold">Parser Payload</summary>
                    <pre class="bg-light border rounded p-3 mt-3 mb-0" style="white-space: pre-wrap;">{{ $parserPayloadJson }}</pre>
                </details>
            @else
                <p class="text-muted mb-0">No parser payload available.</p>
            @endif
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Simulated Trades</h2></div>
        <div class="card-body p-0">
            @if ($tradeSignal->simulatedTrades->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>ID</th><th>Status</th><th>Entry Price</th><th>Current Price</th><th>Max Price</th><th>Min Price</th><th>Max Leveraged P&amp;L %</th><th>Min Leveraged P&amp;L %</th><th>Exit Price</th><th>Exit Reason</th><th>Entry Triggered At</th><th>Closed At</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tradeSignal->simulatedTrades as $simulatedTrade)
                                <tr>
                                    <td>{{ $simulatedTrade->id }}</td>
                                    <td>{{ $display($simulatedTrade->status) }}</td>
                                    <td>{{ $display($simulatedTrade->entry_price) }}</td>
                                    <td>{{ $display($simulatedTrade->current_price) }}</td>
                                    <td>{{ $display($simulatedTrade->max_price) }}</td>
                                    <td>{{ $display($simulatedTrade->min_price) }}</td>
                                    <td>{{ $percentDisplay($simulatedTrade->max_leveraged_pnl_percent) }}</td>
                                    <td>{{ $percentDisplay($simulatedTrade->min_leveraged_pnl_percent) }}</td>
                                    <td>{{ $display($simulatedTrade->exit_price) }}</td>
                                    <td>{{ $display($simulatedTrade->exit_reason) }}</td>
                                    <td>{{ $dateDisplay($simulatedTrade->entry_triggered_at) }}</td>
                                    <td>{{ $dateDisplay($simulatedTrade->closed_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-muted">No simulated trade has been created yet. Monitoring will be added in a later task.</div>
            @endif
        </div>
    </div>

    <div class="card metric-card mb-4">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Tracking Events</h2></div>
        <div class="card-body p-0">
            @if ($tradeSignal->trackingEvents->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Event Type</th><th>Event Price</th><th>Actual Move %</th><th>Leveraged P&amp;L %</th><th>Event Timestamp</th><th>Notes</th></tr></thead>
                        <tbody>
                            @foreach ($tradeSignal->trackingEvents as $trackingEvent)
                                <tr>
                                    <td>{{ $display($trackingEvent->event_type) }}</td>
                                    <td>{{ $display($trackingEvent->event_price) }}</td>
                                    <td>{{ $percentDisplay($trackingEvent->actual_price_move_percent) }}</td>
                                    <td>{{ $percentDisplay($trackingEvent->leveraged_pnl_percent) }}</td>
                                    <td>{{ $dateDisplay($trackingEvent->event_timestamp) }}</td>
                                    <td>{{ $display($trackingEvent->notes) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-muted">No tracking events recorded yet.</div>
            @endif
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-header bg-white"><h2 class="h5 mb-0">Market Snapshots</h2></div>
        <div class="card-body p-0">
            @if ($tradeSignal->marketSnapshots->isNotEmpty())
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Snapshot Type</th><th>Price</th><th>Volume 24h</th><th>24h Change %</th><th>Funding Rate</th><th>Open Interest</th><th>Snapshot At</th></tr></thead>
                        <tbody>
                            @foreach ($tradeSignal->marketSnapshots as $marketSnapshot)
                                <tr>
                                    <td>{{ $display($marketSnapshot->snapshot_type) }}</td>
                                    <td>{{ $display($marketSnapshot->price) }}</td>
                                    <td>{{ $display($marketSnapshot->volume_24h) }}</td>
                                    <td>{{ $percentDisplay($marketSnapshot->price_change_24h_percent) }}</td>
                                    <td>{{ $display($marketSnapshot->funding_rate) }}</td>
                                    <td>{{ $display($marketSnapshot->open_interest) }}</td>
                                    <td>{{ $dateDisplay($marketSnapshot->snapshot_at) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-4 text-muted">No market snapshots recorded yet.</div>
            @endif
        </div>
    </div>
@endsection
