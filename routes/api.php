<?php

declare(strict_types=1);

use App\Http\Controllers\Api\BiometricDeviceController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Endpoint agen pemindai sidik jari
|--------------------------------------------------------------------------
|
| Dipakai oleh agen desktop di PC bersama, BUKAN oleh browser. Autentikasi
| memakai HMAC per perangkat (lihat App\Http\Middleware\AuthenticateBiometricDevice).
|
| Tidak ada sesi, cookie, atau CSRF di sini: grup middleware `api` memang tanpa
| session, dan yang lebih penting — tidak satu pun route ini membuat sesi login.
| Sidik jari hanya menjawab "ini siapa"; pembuktian identitas tetap
| password/passkey milik karyawan itu di /login.
|
| Skema lengkap untuk penulis agen: docs/BIOMETRIC.md
|
*/

Route::middleware('biometric.device')->prefix('biometric')->name('biometric.')->group(function () {
    // Agen memastikan dirinya masih terdaftar dan jam kliennya masih sinkron.
    Route::get('/heartbeat', [BiometricDeviceController::class, 'heartbeat'])
        ->name('heartbeat');

    // Penyimpanan template lokal agen (dan penarikannya untuk agen baru).
    Route::get('/enrollments', [BiometricDeviceController::class, 'enrollments'])
        ->name('enrollments.index');

    // Laporan satu jari yang baru selesai didaftarkan.
    Route::post('/enrollments', [BiometricDeviceController::class, 'storeEnrollment'])
        ->name('enrollments.store');

    // Laporan hasil pencocokan satu jari. Murni catatan audit: route ini tidak
    // membuat sesi, token, maupun cookie — lihat storeIdentification().
    Route::post('/identifications', [BiometricDeviceController::class, 'storeIdentification'])
        ->name('identifications.store');

    // Terbitkan TIKET login sekali pakai untuk jari yang baru cocok. Agen
    // membukakan alamat tiket itu di browser PC yang sama; di situlah sesi
    // dibuat (lihat Auth\FingerprintLoginController). Ini satu-satunya jalur
    // agen yang berujung pada sesi login, dan hanya pengguna yang jarinya
    // benar-benar cocok dengan pendaftaran AKTIF yang bisa mendapat tiket.
    Route::post('/login-tickets', [BiometricDeviceController::class, 'storeLoginTicket'])
        ->name('login-tickets.store');
});
