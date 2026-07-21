<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('about-crypto-futures-signals', function () {
    $this->info('Crypto Futures Signal Analyzer bootstrap is ready.');
})->purpose('Display a short project readiness message');

Schedule::command('strategies:backtest --incremental')
    ->everyTenMinutes()
    ->withoutOverlapping();
