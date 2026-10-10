<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\Support\SoftwareAuthenticator;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

abstract class TestCase extends BaseTestCase
{
    /**
     * The origin browser and server share in tests. Mirrors config('passkeys.allowed_origins').
     */
    protected function origin(): string
    {
        return (string) config('passkeys.allowed_origins.0');
    }

    protected function rpId(): string
    {
        return (string) config('passkeys.relying_party_id');
    }

    /** A fresh software authenticator bound to the app's RP ID and origin. */
    protected function authenticator(): SoftwareAuthenticator
    {
        return new SoftwareAuthenticator($this->rpId(), $this->origin());
    }

    // ------------------------------------------------------------ passkey helpers

    /**
     * Fetch registration options for the current session, returning them as a
     * PublicKeyCredentialCreationOptions object plus the raw challenge bytes.
     *
     * @return array{options: PublicKeyCredentialCreationOptions, challenge: string, browser: array<string, mixed>}
     */
    protected function passkeyRegistrationOptions(): array
    {
        // Registration endpoints sit behind password.confirm. In real use the
        // UI confirms with Windows Hello first; mirror that here so the ceremony
        // itself (not the confirmation gate) is what the test exercises.
        $this->confirmPassword();

        $response = $this->getJson(route('passkey.registration-options'));
        $response->assertOk();

        $browser = $response->json('options');

        return [
            'options' => $this->rehydrateCreationOptions($browser),
            'challenge' => Base64UrlSafe::decodeNoPadding($browser['challenge']),
            'browser' => $browser,
        ];
    }

    /**
     * Register a passkey for the current authenticated session and return the
     * authenticator that owns it (so the same key can later be used to log in).
     */
    protected function registerPasskey(?SoftwareAuthenticator $auth = null, string $name = 'Test Passkey'): SoftwareAuthenticator
    {
        $auth ??= $this->authenticator();

        $challenge = $this->passkeyRegistrationOptions()['challenge'];

        $this->postJson(route('passkey.store'), [
            'name' => $name,
            'credential' => $auth->attest($challenge),
        ])->assertOk();

        return $auth;
    }

    /**
     * Assert an existing passkey and submit it to the passwordless login endpoint.
     */
    protected function loginWithPasskey(SoftwareAuthenticator $auth, User $user): TestResponse
    {
        $challenge = $this->passkeyLoginOptions()['challenge'];

        return $this->postJson(route('passkey.login'), [
            'credential' => $auth->assert($challenge, $this->userHandle($user)),
        ]);
    }

    /**
     * Drop any authenticated state and start a clean session, simulating a new
     * browser profile / fresh visit.
     */
    protected function forgetAuthentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
    }

    /** Mark the password as recently confirmed (bypasses password.confirm). */
    protected function confirmPassword(): void
    {
        $this->withSession(['auth.password_confirmed_at' => time()]);
    }

    /**
     * Fetch passwordless login options, returning the raw challenge bytes.
     *
     * @return array{challenge: string, browser: array<string, mixed>}
     */
    protected function passkeyLoginOptions(): array
    {
        $response = $this->getJson(route('passkey.login-options'));
        $response->assertOk();

        $browser = $response->json('options');

        return [
            'challenge' => Base64UrlSafe::decodeNoPadding($browser['challenge']),
            'browser' => $browser,
        ];
    }

    /** Fetch confirmation options for the authenticated user. */
    protected function passkeyConfirmationChallenge(): string
    {
        $response = $this->getJson(route('passkey.confirm-options'));
        $response->assertOk();

        return Base64UrlSafe::decodeNoPadding($response->json('options.challenge'));
    }

    /** The stable WebAuthn user handle the server derives for a user. */
    protected function userHandle(User $user): string
    {
        return $user->getPasskeyUserHandle();
    }

    /**
     * Rebuild a PublicKeyCredentialCreationOptions object from the browser array
     * so the software authenticator can read the raw challenge and RP ID.
     *
     * @param  array<string, mixed>  $browser
     */
    private function rehydrateCreationOptions(array $browser): PublicKeyCredentialCreationOptions
    {
        return WebAuthn::fromJson(
            json_encode($browser, JSON_THROW_ON_ERROR),
            PublicKeyCredentialCreationOptions::class,
        );
    }

    /**
     * Rebuild a PublicKeyCredentialRequestOptions object from the browser array.
     *
     * @param  array<string, mixed>  $browser
     */
    protected function rehydrateRequestOptions(array $browser): PublicKeyCredentialRequestOptions
    {
        return WebAuthn::fromJson(
            json_encode($browser, JSON_THROW_ON_ERROR),
            PublicKeyCredentialRequestOptions::class,
        );
    }
}
