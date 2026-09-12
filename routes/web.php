<?php

use App\Http\Controllers\Admin\AdminController;
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
});
