<?php

use App\Http\Middleware\AuthenticateBiometricDevice;
use App\Http\Middleware\EnsurePasskeyConfirmed;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Webauthn\Exception\WebauthnException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // TLS is terminated by a reverse proxy in front of PHP (Caddy locally,
        // nginx in staging). Trust the X-Forwarded-* headers so Laravel knows the
        // request is HTTPS and emits https:// URLs and Secure cookies.
        $middleware->trustProxies(at: '*');

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->web(append: [
            SetLocale::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'role' => EnsureRole::class,
            // Menggantikan password.confirm: akun passwordless membuktikan
            // kepemilikan passkey lewat ceremony, bukan lewat password.
            'passkey.confirm' => EnsurePasskeyConfirmed::class,
            // Agen pemindai sidik jari di PC bersama (HMAC per perangkat).
            'biometric.device' => AuthenticateBiometricDevice::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        /*
         * The WebAuthn library signals a failed ceremony by throwing
         * Webauthn\Exception\WebauthnException (wrong origin, wrong RP ID, bad
         * signature, replayed challenge, ...). Left alone that surfaces as a 500
         * and, with APP_DEBUG on, leaks the exact reason. Collapse every such
         * failure into one generic 422 so the client learns nothing it could use
         * to probe the ceremony, while the reason is still written to the log
         * without any credential material.
         */
        $exceptions->render(function (WebauthnException $e, Request $request) {
            Log::warning('WebAuthn ceremony rejected', [
                'reason' => $e->getMessage(),
                'path' => $request->path(),
                'ip' => $request->ip(),
            ]);

            $message = 'Verifikasi passkey gagal. Silakan coba lagi.';

            if ($request->expectsJson()) {
                return response()->json(['message' => $message], 422);
            }

            return back()->withErrors(['credential' => $message]);
        });

        /*
         * Malformed CBOR in an attacker-supplied credential makes the COSE/CBOR
         * layers throw InvalidArgumentException. Scoped to the passkey ceremony
         * endpoints only, so unrelated InvalidArgumentException bugs elsewhere in
         * the app still surface as a real error instead of a silent 422.
         */
        $exceptions->render(function (InvalidArgumentException $e, Request $request) {
            if (! $request->is('passkeys/*', 'user/passkeys/*')) {
                return null;
            }

            Log::warning('WebAuthn credential could not be parsed', [
                'reason' => $e->getMessage(),
                'path' => $request->path(),
            ]);

            $message = 'Verifikasi passkey gagal. Silakan coba lagi.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withErrors(['credential' => $message]);
        });
    })->create();
