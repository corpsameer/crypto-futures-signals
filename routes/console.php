<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about-crypto-futures-signals', function () {
    $this->info('Crypto Futures Signal Analyzer bootstrap is ready.');
})->purpose('Display a short project readiness message');
