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
            <form method="POST" action="{{ route('cryptofuturesignals.signals.store') }}" id="paste-signal-form">
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

                <div class="mb-4 d-none" id="coindcx-manual-section">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="coindcx_symbol" class="form-label">Symbol <span class="text-danger">*</span></label>
                            <input type="text" id="coindcx_symbol" name="coindcx_symbol" value="{{ old('coindcx_symbol') }}" class="form-control @error('coindcx_symbol') is-invalid @enderror" placeholder="SOL/USDT" disabled>
                            @error('coindcx_symbol')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_direction" class="form-label">Direction <span class="text-danger">*</span></label>
                            <select id="coindcx_direction" name="coindcx_direction" class="form-select @error('coindcx_direction') is-invalid @enderror" disabled>
                                <option value="">Select direction</option>
                                <option value="LONG" @selected(old('coindcx_direction') === 'LONG')>LONG</option>
                                <option value="SHORT" @selected(old('coindcx_direction') === 'SHORT')>SHORT</option>
                            </select>
                            @error('coindcx_direction')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_leverage" class="form-label">Leverage <span class="text-danger">*</span></label>
                            <input type="number" min="1" step="1" id="coindcx_leverage" name="coindcx_leverage" value="{{ old('coindcx_leverage') }}" class="form-control @error('coindcx_leverage') is-invalid @enderror" placeholder="14" disabled>
                            @error('coindcx_leverage')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_expected_profit" class="form-label">Expected Profit</label>
                            <div class="input-group">
                                <input type="number" min="0" step="any" id="coindcx_expected_profit" name="coindcx_expected_profit" value="{{ old('coindcx_expected_profit') }}" class="form-control @error('coindcx_expected_profit') is-invalid @enderror" placeholder="18" disabled>
                                <span class="input-group-text">%</span>
                                @error('coindcx_expected_profit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_entry_price_1" class="form-label">Entry Price 1 <span class="text-danger">*</span></label>
                            <input type="number" step="any" id="coindcx_entry_price_1" name="coindcx_entry_price_1" value="{{ old('coindcx_entry_price_1') }}" class="form-control @error('coindcx_entry_price_1') is-invalid @enderror" disabled>
                            @error('coindcx_entry_price_1')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_entry_price_2" class="form-label">Entry Price 2 <span class="text-danger">*</span></label>
                            <input type="number" step="any" id="coindcx_entry_price_2" name="coindcx_entry_price_2" value="{{ old('coindcx_entry_price_2') }}" class="form-control @error('coindcx_entry_price_2') is-invalid @enderror" disabled>
                            @error('coindcx_entry_price_2')<div class="invalid-feedback">{{ $message }}</div>@enderror
                            <div class="form-text">Enter both values displayed in the CoinDCX Entry Range. Their order does not matter.</div>
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_stop_loss" class="form-label">Stop Loss <span class="text-danger">*</span></label>
                            <input type="number" step="any" id="coindcx_stop_loss" name="coindcx_stop_loss" value="{{ old('coindcx_stop_loss') }}" class="form-control @error('coindcx_stop_loss') is-invalid @enderror" disabled>
                            @error('coindcx_stop_loss')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>

                        <div class="col-md-6">
                            <label for="coindcx_take_profit" class="form-label">Take Profit <span class="text-danger">*</span></label>
                            <input type="number" step="any" id="coindcx_take_profit" name="coindcx_take_profit" value="{{ old('coindcx_take_profit') }}" class="form-control @error('coindcx_take_profit') is-invalid @enderror" disabled>
                            @error('coindcx_take_profit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
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
            const coindcxSection = document.getElementById('coindcx-manual-section');
            const rawText = document.getElementById('raw_text');
            const coindcxInputs = coindcxSection.querySelectorAll('input, select');
            const telegramSource = '{{ \App\Models\TradeSignal::SOURCE_TELEGRAM }}';
            const coindcxSource = '{{ \App\Models\TradeSignal::SOURCE_COINDCX }}';
            const requiredCoinDcxFields = [
                'coindcx_symbol',
                'coindcx_direction',
                'coindcx_leverage',
                'coindcx_entry_price_1',
                'coindcx_entry_price_2',
                'coindcx_stop_loss',
                'coindcx_take_profit',
            ];

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

                coindcxInputs.forEach((input) => {
                    input.disabled = isTelegram;
                    input.required = ! isTelegram && requiredCoinDcxFields.includes(input.name);
                });
            }

            sourceSelect.addEventListener('change', updateSignalSourceFields);
            window.addEventListener('pageshow', updateSignalSourceFields);
            updateSignalSourceFields();
        });
    </script>
@endsection
