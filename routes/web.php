<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PastedSignalController;
use Illuminate\Support\Facades\Route;

Route::prefix('cryptofuturesignals')
    ->group(function (): void {
        Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
        Route::post('/login', [AuthController::class, 'login'])->name('cryptofuturesignals.login.store');
        Route::middleware('auth')
            ->name('cryptofuturesignals.')
            ->group(function (): void {
                Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
                Route::get('/dashboard', DashboardController::class)->name('dashboard');
                Route::get('/signals/create', [PastedSignalController::class, 'create'])->name('signals.create');
                Route::post('/signals', [PastedSignalController::class, 'store'])->name('signals.store');
                Route::get('/signals/{pastedSignal}/preview', [PastedSignalController::class, 'preview'])->name('signals.preview');
                Route::post('/signals/{pastedSignal}/confirm', [PastedSignalController::class, 'confirm'])->name('signals.confirm');
                Route::get('/signals', [PastedSignalController::class, 'index'])->name('signals.index');
            });
    });
