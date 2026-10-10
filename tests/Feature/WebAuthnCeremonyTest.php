<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Passkeys;
use Laravel\Passkeys\Support\WebAuthn;
use Tests\Support\SoftwareAuthenticator;
use Tests\TestCase;
use Webauthn\CredentialRecord;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Ceremony-level guarantees: challenge freshness, anti-replay, origin and RP ID
 * binding. These call the validator the same way the HTTP layer does.
 */
class WebAuthnCeremonyTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_options_cannot_be_replayed(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $this->forgetAuthentication();

        $options = $this->passkeyLoginOptions();
        $challenge = $options['challenge'];

        $this->postJson(route('passkey.login'), [
            'credential' => $auth->assert($challenge, $this->userHandle($user)),
        ])->assertOk();

        $this->assertAuthenticatedAs($user->fresh());

        // Second submission with the SAME challenge/options must be rejected:
        // the challenge was pulled from the session on first use.
        $this->forgetAuthentication();

        $this->postJson(route('passkey.login'), [
            'credential' => $auth->assert($challenge, $this->userHandle($user)),
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_login_without_session_challenge_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();
        $this->forgetAuthentication();

        // No passkey.login-options call => no challenge stored in the session.
        $this->postJson(route('passkey.login'), [
            'credential' => $auth->assert(random_bytes(32), $this->userHandle($user)),
        ])->assertStatus(422);

        $this->assertGuest();
    }

    public function test_invalid_origin_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        // Same registration ceremony, but the client claims a foreign origin.
        $options = $this->passkeyRegistrationOptions();
        $auth = $this->authenticator();

        $this->postJson(route('passkey.store'), [
            'name' => 'Evil origin',
            'credential' => $auth->attestWithOrigin($options['challenge'], 'https://evil.example.com'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('passkeys', 0);
    }

    public function test_rp_id_mismatch_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $options = $this->passkeyRegistrationOptions();

        // An authenticator bound to a different RP ID produces an rpIdHash the
        // server cannot match.
        $foreign = new SoftwareAuthenticator('attacker.example.com', $this->origin());

        $this->postJson(route('passkey.store'), [
            'name' => 'Foreign RP',
            'credential' => $foreign->attest($options['challenge']),
        ])->assertStatus(422);

        $this->assertDatabaseCount('passkeys', 0);
    }

    /**
     * The assertion validator itself enforces origin + rpIdHash when fed a
     * credential record stored under this RP ID. This is the exact code path the
     * package uses, invoked directly for a precise, low-level check.
     */
    public function test_assertion_validator_enforces_origin_and_rp_id(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $passkey = $user->passkeys()->firstOrFail();

        $this->forgetAuthentication();

        $requestOptions = $this->rehydrateRequestOptions(
            $this->passkeyLoginOptions()['browser']
        );

        $record = WebAuthn::fromJson(
            json_encode($passkey->credential, JSON_THROW_ON_ERROR),
            CredentialRecord::class,
        );

        // Correct origin + RP ID -> accepted.
        $accepted = $auth->assert(
            $requestOptions->challenge,
            $this->userHandle($user),
        );

        $result = $this->validateAssertion($record, $accepted, $requestOptions, $this->userHandle($user));

        $this->assertInstanceOf(CredentialRecord::class, $result);

        // Wrong origin -> rejected by the origin check.
        $wrongOrigin = $auth->assertWithOrigin(
            $requestOptions->challenge,
            $this->userHandle($user),
            'https://evil.example.com',
        );

        $this->expectException(WebauthnException::class);
        $this->validateAssertion($record, $wrongOrigin, $requestOptions, $this->userHandle($user));
    }

    public function test_validator_rejects_a_larger_sign_counter_reset_to_detect_cloning(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $passkey = $user->passkeys()->firstOrFail();

        $this->forgetAuthentication();

        // Bump the stored counter high, then assert with a lower one: the
        // library flags a possible cloned authenticator.
        $passkey->forceFill([
            'credential' => array_merge($passkey->credential, ['counter' => 99]),
        ])->save();

        $record = WebAuthn::fromJson(
            json_encode($passkey->fresh()->credential, JSON_THROW_ON_ERROR),
            CredentialRecord::class,
        );

        $requestOptions = $this->rehydrateRequestOptions(
            $this->passkeyLoginOptions()['browser']
        );

        $assertion = $auth->assert(
            $requestOptions->challenge,
            $this->userHandle($user),
        );

        $this->expectException(WebauthnException::class);
        $this->validateAssertion($record, $assertion, $requestOptions, $this->userHandle($user));
    }

    /**
     * @param  array<string, mixed>  $assertion
     */
    private function validateAssertion(
        CredentialRecord $record,
        array $assertion,
        PublicKeyCredentialRequestOptions $options,
        string $userHandle,
    ): CredentialRecord {
        $response = WebAuthn::fromJson(
            json_encode([
                'id' => $assertion['id'],
                'rawId' => $assertion['rawId'],
                'type' => $assertion['type'],
                'response' => $assertion['response'],
            ], JSON_THROW_ON_ERROR),
            PublicKeyCredential::class,
        )->response;

        return WebAuthn::assertionValidator()->check(
            credentialRecord: $record,
            authenticatorAssertionResponse: $response,
            publicKeyCredentialRequestOptions: $options,
            host: Passkeys::relyingPartyId(),
            userHandle: $userHandle,
        );
    }
}
