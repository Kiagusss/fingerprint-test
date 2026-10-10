<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PasskeyManagementController;
use App\Http\Controllers\RoleTestController;
use App\Http\Controllers\TestResultsController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public
|--------------------------------------------------------------------------
*/

Route::get('/', LandingController::class)->name('landing');
Route::get('/locale/{locale}', LocaleController::class)->name('locale');

/*
|--------------------------------------------------------------------------
| Authentication (fallback username/password — primary is passkey)
|--------------------------------------------------------------------------
| The passkey endpoints themselves are registered by laravel/passkeys at:
|   GET  /passkeys/login/options   POST /passkeys/login
|   GET  /passkeys/confirm/options POST /passkeys/confirm
|   GET  /user/passkeys/options    POST /user/passkeys
|   DELETE /user/passkeys/{passkey}
*/

Route::middleware('guest:web')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:6,1')
        ->name('login.store');
});

/*
|--------------------------------------------------------------------------
| Authenticated
|--------------------------------------------------------------------------
*/

Route::middleware('auth:web')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Passkey management UI (list + register + revoke). Registration/revocation
    // themselves go through the package routes, which sit behind password.confirm.
    Route::get('/passkeys', PasskeyManagementController::class)->name('passkeys.index');

    // Role-gated demonstrator pages. The role is enforced from users.role by the
    // `role` middleware, never from anything the client supplies.
    Route::get('/admin-test', [RoleTestController::class, 'admin'])
        ->middleware('role:admin')
        ->name('admin.test');

    Route::get('/supervisor-test', [RoleTestController::class, 'supervisor'])
        ->middleware('role:supervisor')
        ->name('supervisor.test');

    // Test results board.
    Route::get('/test-results', [TestResultsController::class, 'index'])->name('test-results.index');
    Route::patch('/test-results/{testResult}', [TestResultsController::class, 'update'])->name('test-results.update');

    // Configuration documentation / runbook.
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation');
});
