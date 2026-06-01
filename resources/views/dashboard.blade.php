@extends('layouts.app')

@section('title', 'Dashboard | Crypto Futures Signal Analyzer')

@section('content')
    <div class="mb-4">
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-muted mb-0">Phase 1 bootstrap for manual crypto futures signal tracking and simulated analysis.</p>
    </div>

    <div class="row g-4">
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Total Signals</div>
                    <div class="metric-value">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Active Simulated Trades</div>
                    <div class="metric-value">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Closed Trades</div>
                    <div class="metric-value">0</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Best Trader</div>
                    <div class="metric-value">N/A</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Worst Trader</div>
                    <div class="metric-value">N/A</div>
                </div>
            </div>
        </div>
        <div class="col-md-6 col-xl-4">
            <div class="card metric-card h-100">
                <div class="card-body">
                    <div class="text-muted">Average Leveraged P&amp;L</div>
                    <div class="metric-value">0%</div>
                </div>
            </div>
        </div>
    </div>
@endsection
