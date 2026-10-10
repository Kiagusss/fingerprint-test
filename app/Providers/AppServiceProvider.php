<?php

namespace App\Providers;

use App\Enums\Role;
use App\Passkeys\WindowsHelloRegistrationOptions;
use App\Passkeys\WindowsHelloVerificationOptions;
use App\Services\AuthAttempt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Passkeys;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Request-scoped: tells the generic login listener which ceremony
        // (password vs passkey) authenticated the user.
        $this->app->scoped(AuthAttempt::class);

        /*
         * Kebijakan "Windows Hello saja".
         *
         * Paket meminta authenticator apa pun (no-preference), sehingga karyawan
         * bisa mendaftarkan ponsel lewat QR, security key USB, atau passkey yang
         * disinkronkan ke akun Microsoft/Google — bukan perangkat kerjanya.
         * Mengganti action ini membuat SEMUA endpoint pendaftaran (kelola
         * kredensial sendiri, pendaftaran karyawan oleh admin, bootstrap admin
         * pertama) hanya menerima authenticator platform bawaan perangkat.
         *
         * Controller paket maupun aplikasi menyelesaikan action ini lewat
         * container, jadi satu binding ini menutup seluruh permukaan.
         */
        $this->app->bind(GenerateRegistrationOptions::class, WindowsHelloRegistrationOptions::class);

        /*
         * Sisi verifikasi (login passwordless + konfirmasi ulang) tidak punya
         * `authenticatorAttachment` sama sekali, jadi UI-nya hanya bisa
         * diarahkan lewat `hints: ['client-device']`. Tanpa ini, login
         * discoverable selalu lebih dulu menampilkan pemilih "ponsel, tablet,
         * atau security key" sebelum Windows Hello.
         */
        $this->app->bind(GenerateVerificationOptions::class, WindowsHelloVerificationOptions::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
         * Keep the whole surface on https so a stray http:// hit cannot drop the
         * Secure session cookie. Enabled only when the app is explicitly
         * configured as https (local behind Caddy, or staging behind nginx).
         */
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        /*
         * Server-side authorisation hook for passwordless login.
         *
         * Passkeys::allowsLogin() runs inside the package's PasskeyLoginController
         * AFTER the assertion has been cryptographically verified and AFTER the
         * passkey has been resolved to its owner, but BEFORE the session guard logs
         * anybody in. Returning false makes the login throw an authentication
         * exception. Note this gate is purely additive: it can only *reject*, it can
         * never log in a user the WebAuthn verification did not already resolve.
         *
         * Here we refuse to authenticate accounts that do not carry one of the two
         * test-account roles, so a stray/leftover account cannot be used to sign in.
         */
        Passkeys::authorizeLoginUsing(
            fn ($request, $user, $passkey): bool => $user->hasRole(Role::Admin, Role::Supervisor, Role::Employee),
        );
    }
}
