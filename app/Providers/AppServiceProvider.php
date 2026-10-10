<?php

namespace App\Providers;

use App\Enums\Role;
use App\Services\AuthAttempt;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
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
