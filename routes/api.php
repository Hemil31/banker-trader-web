<?php

use App\Http\Controllers\Api\Admin\AdminUserController;
use App\Http\Controllers\Api\Admin\TradingSafetyController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Broker\BrokerConnectionController;
use App\Http\Controllers\Api\Broker\BrokerController;
use App\Http\Controllers\Api\Ipo\DematAccountController;
use App\Http\Controllers\Api\Ipo\IpoApplicationController;
use App\Http\Controllers\Api\Ipo\IpoController;
use App\Http\Controllers\Api\Ipo\PanCardController;
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

    // IPO module
    Route::get('/ipos', [IpoController::class, 'index'])->name('api.ipos');
    Route::get('/ipos/{slug}', [IpoController::class, 'show'])->name('api.ipos.show');
    Route::get('/pan-cards', [PanCardController::class, 'index'])->name('api.pan-cards');
    Route::post('/pan-cards', [PanCardController::class, 'store'])->name('api.pan-cards.store');
    Route::patch('/pan-cards/{panCard}', [PanCardController::class, 'update'])->name('api.pan-cards.update');
    Route::delete('/pan-cards/{panCard}', [PanCardController::class, 'destroy'])->name('api.pan-cards.destroy');
    Route::post('/pan-cards/{panCard}/verify', [PanCardController::class, 'verify'])->name('api.pan-cards.verify');
    Route::get('/demat-accounts', [DematAccountController::class, 'index'])->name('api.demat-accounts');
    Route::post('/demat-accounts', [DematAccountController::class, 'store'])->name('api.demat-accounts.store');
    Route::patch('/demat-accounts/{dematAccount}', [DematAccountController::class, 'update'])->name('api.demat-accounts.update');
    Route::delete('/demat-accounts/{dematAccount}', [DematAccountController::class, 'destroy'])->name('api.demat-accounts.destroy');
    Route::post('/demat-accounts/{dematAccount}/verify', [DematAccountController::class, 'verify'])->name('api.demat-accounts.verify');
    Route::get('/ipo-applications', [IpoApplicationController::class, 'index'])->name('api.ipo-applications');
    Route::post('/ipo-applications', [IpoApplicationController::class, 'store'])->name('api.ipo-applications.store');
    Route::post('/ipo-applications/{ipoApplication}/check-allotment', [IpoApplicationController::class, 'checkAllotment'])->name('api.ipo-applications.check-allotment');

    Route::get('/trading/config', [TradingConfigController::class, 'index'])->name('api.trading.config.index');
    Route::patch('/trading/config', [TradingConfigController::class, 'update'])
        ->middleware('is_admin')
        ->name('api.trading.config.update');

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
    Route::post('/broker/connect/{tradingAccount}/megabull', [BrokerConnectionController::class, 'megabullConnect'])
        ->name('api.broker.connect.megabull');
    Route::delete('/broker/disconnect/{tradingAccount}', [BrokerConnectionController::class, 'disconnect'])->name('api.broker.disconnect');
    Route::get('/broker/feed/{tradingAccount}/{type}', [BrokerConnectionController::class, 'feed'])
        ->where('type', 'market|portfolio')
        ->name('api.broker.feed');

    // Company admin
    Route::middleware('is_admin')->prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index'])->name('api.admin.users');
        Route::get('/users/{user}', [AdminUserController::class, 'show'])->name('api.admin.users.show');

        // Emergency controls — platform-wide by default, pass account_id to scope.
        Route::get('/safety/status', [TradingSafetyController::class, 'status'])->name('api.admin.safety.status');
        Route::post('/safety/halt', [TradingSafetyController::class, 'halt'])->name('api.admin.safety.halt');
        Route::post('/safety/resume', [TradingSafetyController::class, 'resume'])->name('api.admin.safety.resume');
        Route::post('/safety/cancel-pending', [TradingSafetyController::class, 'cancelPending'])->name('api.admin.safety.cancel-pending');
        Route::post('/safety/emergency-exit', [TradingSafetyController::class, 'emergencyExit'])->name('api.admin.safety.emergency-exit');
        Route::post('/safety/reconcile', [TradingSafetyController::class, 'reconcile'])->name('api.admin.safety.reconcile');
    });
});
