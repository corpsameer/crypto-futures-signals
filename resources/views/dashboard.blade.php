@extends('layouts.app')

@section('title', 'Dashboard | Crypto Futures Signal Analyzer')

@section('content')
    @php
        $formatPercent = function ($value): string {
            return $value === null ? 'N/A' : number_format((float) $value, 2) . '%';
        };

        $bestTrader = $summary['best_trader'] ?? null;
        $worstTrader = $summary['worst_trader'] ?? null;
        $bestMarketCondition = $summary['best_market_condition'] ?? null;

        $cards = [
            [
                'label' => 'Total Signals',
                'value' => $summary['total_signals'],
                'class' => 'border-secondary',
                'url' => route('cryptofuturesignals.trade-signals.index'),
            ],
            [
                'label' => 'Pending Entry',
                'value' => $summary['pending_entry'],
                'class' => 'border-warning',
                'url' => route('cryptofuturesignals.trade-signals.index', ['status' => 'pending_entry']),
            ],
            [
                'label' => 'Entry Triggered',
                'value' => $summary['entry_triggered'],
                'class' => 'border-success',
            ],
            [
                'label' => 'Entry Missed',
                'value' => $summary['entry_missed'],
                'class' => 'border-warning',
                'url' => route('cryptofuturesignals.trade-signals.index', ['status' => 'entry_missed']),
            ],
            [
                'label' => 'Active Trades',
                'value' => $summary['active_trades'],
                'class' => 'border-secondary',
                'url' => route('cryptofuturesignals.trades.index', ['status' => 'active']),
            ],
            [
                'label' => 'SL Hit',
                'value' => $summary['sl_hit'],
                'class' => 'border-danger',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => 'TP1 Hit',
                'value' => $summary['tp1_hit'],
                'class' => 'border-success',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => 'TP2 Hit',
                'value' => $summary['tp2_hit'],
                'class' => 'border-success',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => 'TP3 Hit',
                'value' => $summary['tp3_hit'],
                'class' => 'border-success',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => 'TP4 Hit',
                'value' => $summary['tp4_hit'],
                'class' => 'border-success',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => '3.5% Gain Hit',
                'value' => $summary['gain_3_5_hit'],
                'class' => 'border-success',
                'url' => route('cryptofuturesignals.trades.index'),
            ],
            [
                'label' => 'Average Max Gain',
                'value' => $formatPercent($summary['average_max_gain']),
                'class' => 'border-success',
            ],
            [
                'label' => 'Average Max Loss',
                'value' => $formatPercent($summary['average_max_loss']),
                'class' => 'border-danger',
            ],
            [
                'label' => 'Best Trader',
                'value' => $bestTrader?->trader_name ?? 'N/A',
                'subtext' => $bestTrader
                    ? 'Avg Max Gain: ' . $formatPercent($bestTrader->avg_max_gain_percent) . ' | Trades: ' . $bestTrader->trade_count
                    : 'No trader performance data yet',
                'class' => 'border-success',
            ],
            [
                'label' => 'Worst Trader',
                'value' => $worstTrader?->trader_name ?? 'N/A',
                'subtext' => $worstTrader
                    ? 'Avg Max Loss: ' . $formatPercent($worstTrader->avg_max_loss_percent) . ' | Trades: ' . $worstTrader->trade_count
                    : 'No trader loss data yet',
                'class' => 'border-danger',
            ],
            [
                'label' => 'Best Market Condition',
                'value' => $bestMarketCondition?->market_condition ? ucfirst($bestMarketCondition->market_condition) : 'N/A',
                'subtext' => $bestMarketCondition
                    ? 'Avg Max Gain: ' . $formatPercent($bestMarketCondition->avg_max_gain_percent) . ' | Trades: ' . $bestMarketCondition->trade_count
                    : 'No entry market condition data yet',
                'class' => 'border-secondary',
            ],
        ];
    @endphp

    <div class="mb-4">
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-muted mb-0">High-level performance summaries for structured signals and simulated trade tracking.</p>
    </div>

    <div class="row g-4">
        @foreach ($cards as $card)
            <div class="col-sm-6 col-xl-3">
                <div class="card metric-card h-100 border-start border-4 {{ $card['class'] }} position-relative">
                    <div class="card-body">
                        <div class="text-muted small text-uppercase fw-semibold">{{ $card['label'] }}</div>
                        <div class="metric-value text-break">{{ $card['value'] }}</div>
                        @if (! empty($card['subtext']))
                            <div class="small text-muted mt-2">{{ $card['subtext'] }}</div>
                        @endif
                        @if (! empty($card['url']))
                            <a href="{{ $card['url'] }}" class="stretched-link" aria-label="Open {{ $card['label'] }} details"></a>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    <div class="alert alert-info mt-4 mb-0">
        Dashboard summaries are based on saved structured signals, simulated trades, and tracking events generated by the Python monitor.
    </div>
@endsection
