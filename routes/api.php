<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Broker\BrokerConnectionController;
use App\Http\Controllers\Api\Broker\BrokerController;
use App\Http\Controllers\Api\News\NewsController;
use App\Http\Controllers\Api\Trading\PaperRunController;
use App\Http\Controllers\Api\Trading\PaperTradesController;
use App\Http\Controllers\Api\Trading\PortfolioController;
use App\Http\Controllers\Api\Trading\PositionsController;
use App\Http\Controllers\Api\Trading\SignalsController;
use App\Http\Controllers\Api\Trading\TradingConfigController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will be
| assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login'])->name('api.login');
Route::post('/refresh', [AuthController::class, 'refreshToken'])->name('api.refresh');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('api.forgot-password');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('api.reset-password');

// Broker OAuth callback (browser redirect from Upstox — no Passport auth).
Route::get('/broker/{slug}/callback', [BrokerConnectionController::class, 'callback'])
    ->where('slug', '[a-z]+')
    ->name('api.broker.callback');

Route::middleware('auth:api')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');
    Route::post('/change-password', [AuthController::class, 'changePassword'])->name('api.change-password');

    Route::get('/portfolio', [PortfolioController::class, 'index'])->name('api.portfolio');
    Route::get('/positions', [PositionsController::class, 'index'])->name('api.positions');
    Route::get('/signals', [SignalsController::class, 'index'])->name('api.signals');
    Route::get('/paper-trades', [PaperTradesController::class, 'index'])->name('api.paper-trades');
    Route::get('/news', [NewsController::class, 'index'])->name('api.news');

    Route::get('/trading/config', [TradingConfigController::class, 'index'])->name('api.trading.config.index');
    Route::patch('/trading/config', [TradingConfigController::class, 'update'])->name('api.trading.config.update');

    Route::post('/trading/run', [PaperRunController::class, 'store'])->name('api.trading.run');

    // Broker management
    Route::get('/brokers', [BrokerController::class, 'index'])->name('api.brokers');
    Route::get('/broker/accounts', [BrokerController::class, 'accounts'])->name('api.broker.accounts');
    Route::get('/broker/status/{tradingAccount}', [BrokerController::class, 'status'])->name('api.broker.status');
    Route::get('/broker/connect/{tradingAccount}/{slug}', [BrokerConnectionController::class, 'connect'])
        ->where('slug', '[a-z]+')
        ->name('api.broker.connect');
    Route::post('/broker/connect/{tradingAccount}/kotak', [BrokerConnectionController::class, 'kotakConnect'])
        ->name('api.broker.connect.kotak');
    Route::delete('/broker/disconnect/{tradingAccount}', [BrokerConnectionController::class, 'disconnect'])->name('api.broker.disconnect');
    Route::get('/broker/feed/{tradingAccount}/{type}', [BrokerConnectionController::class, 'feed'])
        ->where('type', 'market|portfolio')
        ->name('api.broker.feed');

    // Company admin
    Route::middleware('is_admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('api.admin.users');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('api.admin.users.show');
    });
});
