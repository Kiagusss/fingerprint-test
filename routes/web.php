<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BiometricDeviceManagementController;
use App\Http\Controllers\BootstrapController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\EmployeeEnrollmentController;
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
| Bootstrap (tanpa password, sekali jalan)
|--------------------------------------------------------------------------
| Register admin pertama dengan sidik jari saja. Hanya berfungsi selama belum
| ada user sama sekali, lalu menutup diri sendiri (lihat BootstrapController).
| Di luar environment `local`, wajib menyertakan ?key= yang cocok dengan
| BOOTSTRAP_KEY — bila kunci tidak disetel, rute gagal-tertutup.
*/

Route::prefix('bootstrap')->name('bootstrap.')->group(function () {
    Route::get('/', [BootstrapController::class, 'show'])->name('show');
    Route::post('/options', [BootstrapController::class, 'options'])->name('options');
    Route::post('/passkey', [BootstrapController::class, 'store'])->name('store');
});

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
| Jalur utama adalah passkey (passwordless, discoverable credential): server
| tidak meminta username sama sekali. Form username/password di /login hanya
| tampil bila AUTH_ENABLE_PASSWORD_LOGIN=true.
|
| Rute POST selalu terdaftar agar proteksi CSRF tetap dapat diuji, tetapi
| controller menutupnya dengan 404 selama flag pemulihan dimatikan — jadi
| tidak ada jalur password yang benar-benar aktif pada instalasi passwordless.
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
    // Halaman baca: cukup terautentikasi. (Karyawan tidak punya halaman ini,
    // tetapi admin/supervisor memakainya untuk diagnostik.)
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/passkeys', PasskeyManagementController::class)->name('passkeys.index');

    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    /*
     * Pendaftaran sidik jari karyawan oleh admin/IT. Admin memilih karyawan,
     * lalu sidik jari karyawan itu ditempelkan di perangkatnya. Endpoint bawaan
     * paket tidak bisa dipakai untuk ini karena selalu bekerja pada user yang
     * sedang login — karena itu ada controller khusus.
     *
     * Halaman daftar cukup role admin; SETIAP tindakan yang membuat/mengubah
     * kredensial wajib dikonfirmasi ulang (passkey.confirm).
     */
    Route::middleware('role:admin')->prefix('employees')->name('employees.')->group(function () {
        Route::get('/', [EmployeeEnrollmentController::class, 'index'])->name('index');

        Route::middleware('passkey.confirm')->group(function () {
            Route::post('/', [EmployeeEnrollmentController::class, 'create'])->name('create');
            Route::post('/cancel', [EmployeeEnrollmentController::class, 'cancel'])->name('cancel');
            Route::post('/{employee}/prepare', [EmployeeEnrollmentController::class, 'prepare'])->name('prepare');
            Route::post('/{employee}/options', [EmployeeEnrollmentController::class, 'options'])->name('options');
            Route::post('/{employee}/passkey', [EmployeeEnrollmentController::class, 'storePasskey'])->name('store-passkey');
            Route::delete('/passkeys/{passkey}', [EmployeeEnrollmentController::class, 'revoke'])->name('revoke');
        });
    });

    // The passkey package redirects here when a password.confirm-protected
    // endpoint is opened as a full-page navigation instead of a fetch() call
    // (fetch receives the documented 423 and re-verifies with a passkey). There
    // is no password-confirmation page by design, so send the user back to the
    // passkey screen instead of letting the framework bounce them to /login
    // while they are in fact still authenticated.
    Route::get('/password/confirm', function () {
        return redirect()->route('passkeys.index')->with(
            'status',
            'Konfirmasi ulang diperlukan. Tekan "Tambah Windows Hello" untuk memverifikasi di perangkat ini.',
        );
    })->name('password.confirm');

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

    /*
     * Perangkat sidik jari + pendaftaran jari.
     *
     * Hanya admin. Halaman ini mengelola AGEN di PC bersama; ia tidak membuat
     * sesi dari sidik jari. Endpoint yang dipakai agen ada di routes/api.php
     * dan memakai autentikasi per perangkat, bukan sesi browser.
     */
    Route::middleware('role:admin')->prefix('biometric-devices')->name('biometric.')->group(function () {
        Route::get('/', [BiometricDeviceManagementController::class, 'index'])->name('index');
        Route::post('/', [BiometricDeviceManagementController::class, 'store'])->name('store');
        Route::patch('/{device}', [BiometricDeviceManagementController::class, 'toggle'])->name('toggle');
        Route::post('/enrollments/{enrollment}', [BiometricDeviceManagementController::class, 'revoke'])->name('revoke');
    });

    // Configuration documentation / runbook.
    Route::get('/documentation', [DocumentationController::class, 'index'])->name('documentation');
});

/*
|--------------------------------------------------------------------------
| Passkey endpoints (registered by laravel/passkeys)
|--------------------------------------------------------------------------
|   GET  /passkeys/login/options   POST /passkeys/login
|   GET  /passkeys/confirm/options POST /passkeys/confirm
|   GET  /user/passkeys/options    POST /user/passkeys
|   DELETE /user/passkeys/{passkey}
|
| Rute manajemen (registrasi/penghapusan) memakai `passkey.confirm`, bukan
| `password.confirm` — lihat config/passkeys.php `management_middleware`.
*/
