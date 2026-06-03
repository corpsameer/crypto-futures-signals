<?php

use App\Http\Controllers\Api\LocalTestApiController;
use App\Http\Controllers\Api\MonitorApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptofuturesignals/api')
    ->middleware('python.api')
    ->name('cryptofuturesignals.api.')
    ->group(function (): void {
        Route::get('/health', function () {
            return response()->json([
                'success' => true,
                'message' => 'Crypto Futures Signal Analyzer API is reachable.',
                'timestamp' => now()->toDateTimeString(),
            ]);
        })->name('health');

        Route::get('/trade-signals/pending', [MonitorApiController::class, 'pendingSignals'])->name('trade-signals.pending');
        Route::post('/trade-signals/mark-entry-missed', [MonitorApiController::class, 'markEntryMissed'])->name('trade-signals.mark-entry-missed');
        Route::get('/simulated-trades/active', [MonitorApiController::class, 'activeTrades'])->name('simulated-trades.active');
        Route::get('/simulated-trades/post-sl-tracking', [MonitorApiController::class, 'postSlTrackingTrades'])->name('simulated-trades.post-sl-tracking');
        Route::post('/simulated-trades/entry-triggered', [MonitorApiController::class, 'entryTriggered'])->name('simulated-trades.entry-triggered');
        Route::post('/trade-events/store', [MonitorApiController::class, 'storeTradeEvent'])->name('trade-events.store');
        Route::post('/simulated-trades/update-metrics', [MonitorApiController::class, 'updateMetrics'])->name('simulated-trades.update-metrics');
        Route::post('/simulated-trades/close', [MonitorApiController::class, 'closeTrade'])->name('simulated-trades.close');
        Route::post('/market-snapshots/store', [MonitorApiController::class, 'storeMarketSnapshot'])->name('market-snapshots.store');
        Route::get('/local-test/signals', [LocalTestApiController::class, 'signals'])->name('local-test.signals');
        Route::get('/local-test/trade/{simulatedTrade}/state', [LocalTestApiController::class, 'tradeState'])->name('local-test.trade-state');
        Route::get('/local-test/signal/{tradeSignal}/state', [LocalTestApiController::class, 'signalState'])->name('local-test.signal-state');
    });
