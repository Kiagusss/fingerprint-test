<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang verifikasi ulang untuk tindakan yang mengubah kredensial.
 *
 * Menggantikan `password.confirm` bawaan Laravel. Akun passwordless tidak punya
 * password untuk dikonfirmasi, jadi pengguna membuktikan kepemilikan passkey
 * yang sudah ada: POST /passkeys/confirm (verifikasi WebAuthn) yang memanggil
 * `$session->passwordConfirmed()` — persis penanda sesi yang sama, sehingga
 * endpoint paket yang memakai middleware ini tetap bekerja tanpa diubah.
 *
 * Bootstrap tanpa konfirmasi
 * --------------------------
 * Menjalankan ceremony passkey memerlukan WINDOWS HELLO, dan itu memerlukan
 * origin https (secure context). Di mesin pengembang yang menguji lewat
 * http://127.0.0.1:8099, ceremony TIDAK BISA berjalan sama sekali, sehingga
 * passkey pertama tidak akan pernah bisa didaftarkan. Karena itu HANYA di
 * environment `local`, sesi yang sudah terautentikasi diterima tanpa konfirmasi.
 *
 * Ini tidak bisa dicapai di deployment nyata: di sana aplikasi selalu https,
 * sehingga jalur ini tidak relevan. Setel PASSKEY_BOOTSTRAP_NO_CONFIRM=false
 * untuk menutup celah lokal ini juga saat pemeriksaan ketat.
 */
class EnsurePasskeyConfirmed
{
    public function handle(Request $request, Closure $next): Response
    {
        // Sudah dikonfirmasi lewat ceremony passkey (atau, di titik lain, password).
        if ($request->session()->has('auth.password_confirmed_at')) {
            return $next($request);
        }

        if (config('passkeys.bootstrap_without_confirmation', true) && app()->environment('local')) {
            return $next($request);
        }

        $message = 'Konfirmasi ulang dengan Windows Hello diperlukan.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 423)
            : redirect()->route('login')->withErrors(['credential' => $message]);
    }
}
