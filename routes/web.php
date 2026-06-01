<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptofuturesignals')
    ->group(function (): void {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('cryptofuturesignals.login.store');
        Route::post('/logout', [AuthController::class, 'logout'])->name('cryptofuturesignals.logout');

        Route::middleware('auth')
            ->name('cryptofuturesignals.')
            ->group(function (): void {
                Route::get('/dashboard', DashboardController::class)->name('dashboard');
            });
    });
