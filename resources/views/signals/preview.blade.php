@extends('layouts.app')

@section('title', 'Review Parsed Signal | Crypto Futures Signal Analyzer')

@php
    $fieldValue = fn (string $field, mixed $default = null): mixed => old($field, $parsedData[$field] ?? $default);
    $traderName = $fieldValue('trader_name', $pastedSignal->trader_name);
    $exchange = $fieldValue('exchange', 'coindcx');
    $marketType = $fieldValue('market_type', 'futures');
    $entryType = $fieldValue('entry_type', ($parsedData['entry_min'] ?? null) !== ($parsedData['entry_max'] ?? null) ? 'range' : 'single');
@endphp

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Review Parsed Signal</h1>
            <p class="text-muted mb-0">Confirm or correct the parsed fields before saving a structured trade signal.</p>
        </div>
        <a href="{{ route('cryptofuturesignals.signals.index') }}" class="btn btn-outline-secondary">Back to Signals</a>
    </div>

    @if (session('success'))
        <div class="alert alert-success" role="alert">
            {{ session('success') }}
        </div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-2">Please fix the following errors:</div>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($parserWarnings !== [])
        <div class="alert alert-warning" role="alert">
            <div class="fw-semibold mb-2">Parser warnings:</div>
            <ul class="mb-0">
                @foreach ($parserWarnings as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($parserErrors !== [])
        <div class="alert alert-danger" role="alert">
            <div class="fw-semibold mb-2">Parser errors:</div>
            <ul class="mb-0">
                @foreach ($parserErrors as $parserError)
                    <li>{{ $parserError }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-12 col-xl-5">
            <div class="card metric-card h-100">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">Original Pasted Signal</h2>
                </div>
                <div class="card-body">
                    <dl class="row mb-4">
                        <dt class="col-sm-4">Trader</dt>
                        <dd class="col-sm-8">{{ $pastedSignal->trader_name ?: 'N/A' }}</dd>

                        <dt class="col-sm-4">Pasted At</dt>
                        <dd class="col-sm-8">{{ $pastedSignal->pasted_at?->format('Y-m-d H:i:s') ?? 'N/A' }}</dd>
                    </dl>

                    <pre class="bg-light border rounded p-3 mb-0 text-break" style="white-space: pre-wrap;">{{ $pastedSignal->raw_text }}</pre>
                </div>
            </div>
        </div>

        <div class="col-12 col-xl-7">
            <div class="card metric-card">
                <div class="card-header bg-white">
                    <h2 class="h5 mb-0">Parsed / Editable Signal Fields</h2>
                </div>
                <div class="card-body">
                    <form method="POST" action="{{ route('cryptofuturesignals.signals.confirm', $pastedSignal) }}">
                        @csrf

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label for="trader_name" class="form-label">Trader Name</label>
                                <input type="text" id="trader_name" name="trader_name" value="{{ $traderName }}" class="form-control @error('trader_name') is-invalid @enderror">
                                @error('trader_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="exchange" class="form-label">Exchange <span class="text-danger">*</span></label>
                                <input type="text" id="exchange" name="exchange" value="{{ $exchange }}" required class="form-control @error('exchange') is-invalid @enderror">
                                @error('exchange')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="symbol" class="form-label">Symbol <span class="text-danger">*</span></label>
                                <input type="text" id="symbol" name="symbol" value="{{ $fieldValue('symbol') }}" required class="form-control @error('symbol') is-invalid @enderror" placeholder="BTCUSDT">
                                @error('symbol')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="pair" class="form-label">Pair</label>
                                <input type="text" id="pair" name="pair" value="{{ $fieldValue('pair') }}" class="form-control @error('pair') is-invalid @enderror" placeholder="BTC/USDT">
                                @error('pair')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="market_type" class="form-label">Market Type <span class="text-danger">*</span></label>
                                <input type="text" id="market_type" name="market_type" value="{{ $marketType }}" required class="form-control @error('market_type') is-invalid @enderror">
                                @error('market_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="direction" class="form-label">Direction <span class="text-danger">*</span></label>
                                <select id="direction" name="direction" required class="form-select @error('direction') is-invalid @enderror">
                                    <option value="">Select direction</option>
                                    <option value="LONG" @selected($fieldValue('direction') === 'LONG')>LONG</option>
                                    <option value="SHORT" @selected($fieldValue('direction') === 'SHORT')>SHORT</option>
                                </select>
                                @error('direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="leverage" class="form-label">Leverage</label>
                                <input type="number" step="0.01" min="1" max="125" id="leverage" name="leverage" value="{{ $fieldValue('leverage') }}" class="form-control @error('leverage') is-invalid @enderror">
                                @error('leverage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="margin_mode" class="form-label">Margin Mode</label>
                                <input type="text" id="margin_mode" name="margin_mode" value="{{ $fieldValue('margin_mode') }}" class="form-control @error('margin_mode') is-invalid @enderror" placeholder="cross or isolated">
                                @error('margin_mode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="entry_min" class="form-label">Entry Min <span class="text-danger">*</span></label>
                                <input type="number" step="any" id="entry_min" name="entry_min" value="{{ $fieldValue('entry_min') }}" required class="form-control @error('entry_min') is-invalid @enderror">
                                @error('entry_min')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="entry_max" class="form-label">Entry Max <span class="text-danger">*</span></label>
                                <input type="number" step="any" id="entry_max" name="entry_max" value="{{ $fieldValue('entry_max') }}" required class="form-control @error('entry_max') is-invalid @enderror">
                                @error('entry_max')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="entry_type" class="form-label">Entry Type</label>
                                <input type="text" id="entry_type" name="entry_type" value="{{ $entryType }}" class="form-control @error('entry_type') is-invalid @enderror" placeholder="single or range">
                                @error('entry_type')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="stop_loss" class="form-label">Stop Loss <span class="text-danger">*</span></label>
                                <input type="number" step="any" id="stop_loss" name="stop_loss" value="{{ $fieldValue('stop_loss') }}" required class="form-control @error('stop_loss') is-invalid @enderror">
                                @error('stop_loss')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            @foreach (['tp1' => 'TP1', 'tp2' => 'TP2', 'tp3' => 'TP3', 'tp4' => 'TP4'] as $targetField => $targetLabel)
                                <div class="col-md-6">
                                    <label for="{{ $targetField }}" class="form-label">{{ $targetLabel }} @if ($targetField === 'tp1')<span class="text-danger">*</span>@endif</label>
                                    <input type="number" step="any" id="{{ $targetField }}" name="{{ $targetField }}" value="{{ $fieldValue($targetField) }}" @if ($targetField === 'tp1') required @endif class="form-control @error($targetField) is-invalid @enderror">
                                    @error($targetField)<div class="invalid-feedback">{{ $message }}</div>@enderror
                                </div>
                            @endforeach

                            <div class="col-md-6">
                                <label for="signal_time" class="form-label">Signal Time</label>
                                <input type="datetime-local" id="signal_time" name="signal_time" value="{{ $fieldValue('signal_time') }}" class="form-control @error('signal_time') is-invalid @enderror">
                                @error('signal_time')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-md-6">
                                <label for="expires_at" class="form-label">Expires At</label>
                                <input type="datetime-local" id="expires_at" name="expires_at" value="{{ $fieldValue('expires_at') }}" class="form-control @error('expires_at') is-invalid @enderror">
                                @error('expires_at')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>

                            <div class="col-12">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea id="notes" name="notes" rows="4" class="form-control @error('notes') is-invalid @enderror">{{ $fieldValue('notes') }}</textarea>
                                @error('notes')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="d-flex flex-column flex-sm-row gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Save Structured Signal</button>
                            <a href="{{ route('cryptofuturesignals.signals.index') }}" class="btn btn-outline-secondary">Back to Signals</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
