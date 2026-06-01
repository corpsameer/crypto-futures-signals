<?php

use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptofuturesignals')
    ->name('cryptofuturesignals.')
    ->group(function () {
        Route::get('/dashboard', DashboardController::class)->name('dashboard');
    });
