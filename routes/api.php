<?php

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
    });
