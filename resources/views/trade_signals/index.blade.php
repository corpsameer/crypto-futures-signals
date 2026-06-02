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

@section('title', 'Structured Trade Signals | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Structured Trade Signals</h1>
            <p class="text-muted mb-0">Confirmed parsed signals saved for monitoring and analysis.</p>
        </div>
        <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste New Signal</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success" role="alert">
            {{ session('success') }}
        </div>
    @endif

    <div class="card metric-card mb-4">
        <div class="card-body">
            <form method="GET" action="{{ route('cryptofuturesignals.trade-signals.index') }}" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="search" class="form-label">Search</label>
                    <input type="search" name="search" id="search" value="{{ $filters['search'] }}" class="form-control" placeholder="BTC, ICP, Mohan">
                </div>
                <div class="col-md-2">
                    <label for="status" class="form-label">Status</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">All statuses</option>
                        @foreach ($availableStatuses as $status)
                            <option value="{{ $status }}" @selected($filters['status'] === $status)>{{ $status }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="direction" class="form-label">Direction</label>
                    <select name="direction" id="direction" class="form-select">
                        <option value="">All directions</option>
                        @foreach ($availableDirections as $direction)
                            <option value="{{ $direction }}" @selected($filters['direction'] === $direction)>{{ $direction }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="trader_name" class="form-label">Trader</label>
                    <input type="text" name="trader_name" id="trader_name" value="{{ $filters['trader_name'] }}" class="form-control" placeholder="Mohan or Sumit">
                </div>
                <div class="col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">Filter</button>
                    <a href="{{ route('cryptofuturesignals.trade-signals.index') }}" class="btn btn-outline-secondary">Clear</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card metric-card">
        <div class="card-body p-0">
            @if ($tradeSignals->count() > 0)
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th scope="col">ID</th>
                                <th scope="col">Signal Time / Created</th>
                                <th scope="col">Trader</th>
                                <th scope="col">Symbol</th>
                                <th scope="col">Direction</th>
                                <th scope="col">Leverage</th>
                                <th scope="col">Entry</th>
                                <th scope="col">Stop Loss</th>
                                <th scope="col">Targets</th>
                                <th scope="col">Status</th>
                                <th scope="col">Source Paste</th>
                                <th scope="col">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($tradeSignals as $tradeSignal)
                                <tr>
                                    <td>{{ $tradeSignal->id }}</td>
                                    <td>{{ ($tradeSignal->signal_time ?? $tradeSignal->created_at)?->format('Y-m-d H:i') ?? 'N/A' }}</td>
                                    <td>{{ $tradeSignal->trader_name ?: 'N/A' }}</td>
                                    <td>
                                        <div class="fw-semibold">{{ $tradeSignal->symbol ?: 'N/A' }}</div>
                                        @if ($tradeSignal->pair)
                                            <div class="small text-muted">{{ $tradeSignal->pair }}</div>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $directionBadgeClasses[$tradeSignal->direction] ?? 'text-bg-secondary' }}">
                                            {{ $tradeSignal->direction ?: 'N/A' }}
                                        </span>
                                    </td>
                                    <td>{{ $tradeSignal->leverage !== null ? rtrim(rtrim((string) $tradeSignal->leverage, '0'), '.') . 'x' : 'N/A' }}</td>
                                    <td>{{ $tradeSignal->entry_display }}</td>
                                    <td>{{ $tradeSignal->stop_loss ?? 'N/A' }}</td>
                                    <td>
                                        @if (count($tradeSignal->tp_levels) > 0)
                                            <div class="d-flex flex-column gap-1">
                                                @foreach ($tradeSignal->tp_levels as $label => $value)
                                                    <span><span class="text-muted">{{ strtoupper($label) }}:</span> {{ $value }}</span>
                                                @endforeach
                                            </div>
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge {{ $statusBadgeClasses[$tradeSignal->status] ?? 'text-bg-secondary' }}">
                                            {{ $tradeSignal->status ?: 'N/A' }}
                                        </span>
                                    </td>
                                    <td>
                                        @if ($tradeSignal->pastedSignal)
                                            <a href="{{ route('cryptofuturesignals.signals.preview', $tradeSignal->pastedSignal) }}" class="btn btn-sm btn-outline-secondary">Review Paste</a>
                                        @else
                                            N/A
                                        @endif
                                    </td>
                                    <td>
                                        <div class="d-flex flex-column gap-2">
                                            <a href="{{ route('cryptofuturesignals.trade-signals.show', $tradeSignal) }}" class="btn btn-sm btn-outline-primary">View Detail</a>
                                            @if ($tradeSignal->pastedSignal)
                                                <a href="{{ route('cryptofuturesignals.signals.preview', $tradeSignal->pastedSignal) }}" class="btn btn-sm btn-outline-secondary">Edit Parsed Signal</a>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-5 text-center text-muted">
                    <p class="mb-3">No structured trade signals saved yet.</p>
                    <a href="{{ route('cryptofuturesignals.signals.create') }}" class="btn btn-primary">Paste First Signal</a>
                </div>
            @endif
        </div>
    </div>

    @if ($tradeSignals->hasPages())
        <div class="mt-4">
            {{ $tradeSignals->links() }}
        </div>
    @endif
@endsection
