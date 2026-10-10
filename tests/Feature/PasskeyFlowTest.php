<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

/**
 * Full WebAuthn ceremonies against the real validator, driven by a software
 * authenticator. Every assertion here is cryptographic, not a stub.
 */
class PasskeyFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_passkey_can_be_registered_for_the_authenticated_user(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey(name: 'Windows A - test-admin');

        $this->assertDatabaseHas('passkeys', [
            'user_id' => $user->id,
            'name' => 'Windows A - test-admin',
        ]);

        $this->assertDatabaseHas('passkeys', [
            'user_id' => $user->id,
            'credential_id' => Base64UrlSafe::encodeUnpadded($auth->credentialId()),
        ]);

        $this->assertTrue($user->fresh()->hasPasskeysEnabled());
    }

    public function test_a_registered_passkey_can_log_the_user_in_without_a_password(): void
    {
        $user = User::factory()->supervisor()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $this->forgetAuthentication();

        $this->loginWithPasskey($auth, $user)->assertOk();

        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_passkey_is_bound_to_its_owner(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($admin);
        $this->registerPasskey();

        $this->assertSame(1, $admin->passkeys()->count());
        $this->assertSame(0, $supervisor->passkeys()->count());

        $passkey = $admin->passkeys()->firstOrFail();

        $this->assertSame($admin->id, $passkey->user->id);
        $this->assertNotSame($supervisor->id, $passkey->user_id);
    }

    public function test_a_user_may_hold_multiple_passkeys(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $this->registerPasskey(name: 'Windows A');
        $this->registerPasskey(name: 'Backup key');

        $this->assertSame(2, $user->fresh()->passkeys()->count());
    }

    public function test_a_revoked_passkey_can_no_longer_log_in(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $passkey = $user->passkeys()->firstOrFail();

        $this->confirmPassword();
        $this->deleteJson(route('passkey.destroy', $passkey))->assertOk();

        $this->assertSame(0, $user->fresh()->passkeys()->count());

        $this->forgetAuthentication();

        // The credential no longer exists, so the assertion must be rejected.
        $this->loginWithPasskey($auth, $user)->assertStatus(422);
        $this->assertGuest();
    }

    /**
     * A signature from a key the server never stored must be rejected, proving
     * the credential lookup + signature check really run.
     */
    public function test_a_credential_id_that_was_never_registered_is_rejected(): void
    {
        User::factory()->admin()->create();

        $this->forgetAuthentication();

        $rogue = $this->authenticator();

        $this->loginWithPasskey($rogue, User::factory()->make())->assertStatus(422);
        $this->assertGuest();
    }

    public function test_a_tampered_signature_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);
        $auth = $this->registerPasskey();

        $this->forgetAuthentication();

        $challenge = $this->passkeyLoginOptions()['challenge'];

        $this->postJson(route('passkey.login'), [
            'credential' => $auth->assertWithBrokenSignature($challenge, $this->userHandle($user)),
        ])->assertStatus(422);

        $this->assertGuest();
    }
}
