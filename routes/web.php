<?php

use App\Http\Controllers\Trading\DashboardController;
use App\Http\Controllers\Trading\PaperRunController;
use App\Http\Controllers\Trading\PaperTradesController;
use App\Http\Controllers\Trading\PositionsController;
use App\Http\Controllers\Trading\SignalsController;
use App\Http\Controllers\Trading\TradingConfigController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::get('trading/signals', [SignalsController::class, 'index'])->name('trading.signals');
    Route::get('trading/positions', [PositionsController::class, 'index'])->name('trading.positions');
    Route::get('trading/paper-trades', [PaperTradesController::class, 'index'])->name('trading.paper-trades');

    Route::get('trading/config', [TradingConfigController::class, 'index'])->name('trading.config');
    Route::patch('trading/config', [TradingConfigController::class, 'update'])->name('trading.config.update');

    Route::post('trading/run', [PaperRunController::class, 'store'])->name('trading.run');
});

require __DIR__.'/settings.php';
