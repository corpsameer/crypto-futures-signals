@extends('layouts.app')

@section('title', 'Paste Signal | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $selectedSignalSource = in_array(old('signal_source'), [\App\Models\TradeSignal::SOURCE_TELEGRAM, \App\Models\TradeSignal::SOURCE_COINDCX], true)
            ? old('signal_source')
            : \App\Models\TradeSignal::SOURCE_TELEGRAM;
    @endphp
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Paste Signal</h1>
            <p class="text-muted mb-0">Save a Telegram crypto futures signal or CoinDCX Expert Pick screenshot for parsing in the next step.</p>
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
            <form method="POST" action="{{ route('cryptofuturesignals.signals.store') }}" enctype="multipart/form-data" id="paste-signal-form">
                @csrf

                <div class="mb-3">
                    <label for="signal_source" class="form-label">Signal Source <span class="text-danger">*</span></label>
                    <select
                        id="signal_source"
                        name="signal_source"
                        class="form-select @error('signal_source') is-invalid @enderror"
                        required
                    >
                        <option value="{{ \App\Models\TradeSignal::SOURCE_TELEGRAM }}" @selected($selectedSignalSource === \App\Models\TradeSignal::SOURCE_TELEGRAM)>Telegram</option>
                        <option value="{{ \App\Models\TradeSignal::SOURCE_COINDCX }}" @selected($selectedSignalSource === \App\Models\TradeSignal::SOURCE_COINDCX)>CoinDCX Expert Pick</option>
                    </select>
                    @error('signal_source')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

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

                <div class="mb-4" id="telegram-signal-section">
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

                <div class="mb-4 d-none" id="coindcx-screenshot-section">
                    <label for="source_image" class="form-label">CoinDCX Expert Pick Screenshot <span class="text-danger">*</span></label>
                    <input
                        type="file"
                        id="source_image"
                        name="source_image"
                        accept="image/png,image/jpeg,image/webp"
                        class="form-control @error('source_image') is-invalid @enderror"
                        disabled
                    >
                    <div class="form-text">Upload a CoinDCX Expert Pick screenshot as PNG, JPEG, or WebP (max 10 MB).</div>
                    @error('source_image')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="d-flex flex-column flex-sm-row gap-2">
                    <button type="submit" class="btn btn-primary" id="paste-signal-submit">Save Signal</button>
                    <a href="{{ route('cryptofuturesignals.signals.index') }}" class="btn btn-outline-secondary">Back to Signals</a>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const sourceSelect = document.getElementById('signal_source');
            const telegramSection = document.getElementById('telegram-signal-section');
            const coindcxSection = document.getElementById('coindcx-screenshot-section');
            const rawText = document.getElementById('raw_text');
            const sourceImage = document.getElementById('source_image');
            const telegramSource = '{{ \App\Models\TradeSignal::SOURCE_TELEGRAM }}';
            const coindcxSource = '{{ \App\Models\TradeSignal::SOURCE_COINDCX }}';

            function updateSignalSourceFields() {
                const selectedSource = [telegramSource, coindcxSource].includes(sourceSelect.value)
                    ? sourceSelect.value
                    : telegramSource;

                sourceSelect.value = selectedSource;

                const isTelegram = selectedSource === telegramSource;

                telegramSection.classList.toggle('d-none', ! isTelegram);
                coindcxSection.classList.toggle('d-none', isTelegram);

                rawText.disabled = ! isTelegram;
                rawText.required = isTelegram;

                sourceImage.disabled = isTelegram;
                sourceImage.required = ! isTelegram;
            }

            sourceSelect.addEventListener('change', updateSignalSourceFields);
            window.addEventListener('pageshow', updateSignalSourceFields);
            updateSignalSourceFields();
        });
    </script>
@endsection
