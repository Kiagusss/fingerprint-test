<?php

declare(strict_types=1);

namespace App\Services;

use Laravel\Passkeys\Passkeys;

/**
 * Read-only snapshot of the environment used by the diagnostic landing page.
 *
 * IMPORTANT: this reports what the SERVER is configured with. Browser
 * capabilities (HTTPS, WebAuthn API presence, platform authenticator) can only
 * be determined client-side and are collected in resources/js/passkey.js.
 */
class Diagnostics
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'server_https' => $this->isHttps(),
            'rp_id' => $this->rpId(),
            'allowed_origins' => $this->allowedOrigins(),
            'webauthn_timeout_ms' => $this->timeout(),
            'session_driver' => config('session.driver'),
            'session_lifetime_minutes' => (int) config('session.lifetime'),
            'session_secure_cookie' => (bool) config('session.secure'),
            'session_same_site' => config('session.same_site'),
            'session_http_only' => (bool) config('session.http_only'),
            'app_url' => config('app.url'),
            'app_env' => config('app.env'),
            'debug' => (bool) config('app.debug'),
        ];
    }

    private function isHttps(): bool
    {
        $url = (string) config('app.url');

        return str_starts_with($url, 'https://');
    }

    /**
     * Subset that is safe to render to an ANONYMOUS visitor on a public host:
     * everything a tester needs to check the WebAuthn ceremony, and nothing
     * about the software stack, debug state or session internals we are running.
     * The full snapshot stays behind authentication.
     *
     * @return array<string, mixed>
     */
    public function publicSnapshot(): array
    {
        return array_intersect_key($this->snapshot(), array_flip([
            'server_https',
            'rp_id',
            'allowed_origins',
            'webauthn_timeout_ms',
        ]));
    }

    private function rpId(): ?string
    {
        try {
            return Passkeys::relyingPartyId();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<string>
     */
    private function allowedOrigins(): array
    {
        try {
            return Passkeys::allowedOrigins();
        } catch (\Throwable) {
            return [];
        }
    }

    private function timeout(): ?int
    {
        try {
            return Passkeys::timeout();
        } catch (\Throwable) {
            return null;
        }
    }
}
