<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\ZernioController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The web is the company-admin console. Regular users authenticate only
| through the mobile client via the Passport API (routes/api.php), so the
| only public web page is the admin login (registered by Fortify).
|
*/

Route::redirect('/', '/login')->name('home');

Route::middleware(['auth', 'is_admin'])->group(function () {
    Route::get('admin', [AdminController::class, 'index'])->name('admin.dashboard');
    Route::get('admin/settings', [AdminController::class, 'settings'])->name('admin.settings');
    Route::post('admin/settings', [AdminController::class, 'updateSetting'])->name('admin.settings.update');

    Route::prefix('admin/zernio')->name('admin.zernio.')->group(function () {
        Route::get('accounts', [ZernioController::class, 'accounts'])->name('accounts');
        Route::post('accounts/sync', [ZernioController::class, 'syncAccounts'])->name('accounts.sync');
        Route::get('posts', [ZernioController::class, 'posts'])->name('posts');
        Route::post('posts', [ZernioController::class, 'storePost'])->name('posts.store');
        Route::post('posts/{post}/refresh', [ZernioController::class, 'refreshPostStatus'])->name('posts.refresh');
        Route::post('media/presign', [ZernioController::class, 'presignMedia'])->name('media.presign');
    });
});
