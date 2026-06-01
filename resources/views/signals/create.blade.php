@extends('layouts.app')

@section('title', 'Paste Signal | Crypto Futures Signal Analyzer')

@section('content')
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Paste Signal</h1>
            <p class="text-muted mb-0">Save a raw Telegram crypto futures signal for parsing in the next step.</p>
        </div>
        <a href="{{ route('cryptofuturesignals.signals.index') }}" class="btn btn-outline-secondary">Back to Signals</a>
    </div>

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

    <div class="card metric-card">
        <div class="card-body">
            <form method="POST" action="{{ route('cryptofuturesignals.signals.store') }}">
                @csrf

                <div class="mb-3">
                    <label for="trader_name" class="form-label">Trader Name / Channel Name</label>
                    <input
                        type="text"
                        id="trader_name"
                        name="trader_name"
                        value="{{ old('trader_name') }}"
                        class="form-control @error('trader_name') is-invalid @enderror"
                        placeholder="e.g. Binance Killers, Premium Futures, etc."
                    >
                    @error('trader_name')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="mb-4">
                    <label for="raw_text" class="form-label">Raw Signal Text <span class="text-danger">*</span></label>
                    <textarea
                        id="raw_text"
                        name="raw_text"
                        rows="12"
                        required
                        class="form-control @error('raw_text') is-invalid @enderror"
                        placeholder="BTC/USDT LONG&#10;Leverage: 10x&#10;Entry: 65000 - 65200&#10;Targets: 66000, 67000, 68000&#10;Stop Loss: 64000"
                    >{{ old('raw_text') }}</textarea>
                    @error('raw_text')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2">
                    <button type="submit" class="btn btn-primary">Save Signal</button>
                    <a href="{{ route('cryptofuturesignals.signals.index') }}" class="btn btn-outline-secondary">Back to Signals</a>
                </div>
            </form>
        </div>
    </div>
@endsection
