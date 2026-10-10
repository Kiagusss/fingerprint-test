<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fallback username/password authentication: session regeneration, generic error
 * messages, and non-sensitive diagnostic logging.
 */
class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Jalur pemulihan password nonaktif secara default pada instalasi
        // passwordless. Aktifkan agar tes ini tetap menguji perilakunya.
        config(['auth.enable_password_login' => true]);
    }

    public function test_password_login_succeeds_and_regenerates_session(): void
    {
        $user = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->get(route('login'));

        $before = session()->getId();

        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        // Session id must change on login (fixation defence).
        $this->assertNotSame($before, session()->getId());

        $this->assertSame('password', $user->fresh()->last_auth_method);
        $this->assertNotNull($user->fresh()->last_authenticated_at);

        $this->assertDatabaseHas('test_logs', [
            'event' => 'login_success',
            'user_id' => $user->id,
            'auth_method' => 'password',
        ]);
    }

    public function test_login_failure_message_does_not_leak_user_existence(): void
    {
        User::factory()->create(['username' => 'test-admin']);

        $unknown = $this->post(route('login.store'), [
            'username' => 'does-not-exist',
            'password' => 'whatever',
        ]);

        $wrongPassword = $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'wrong',
        ]);

        $unknown->assertSessionHasErrors('username');
        $wrongPassword->assertSessionHasErrors('username');

        $this->assertSame(
            $unknown->getSession()->get('errors')->first('username'),
            $wrongPassword->getSession()->get('errors')->first('username'),
        );

        $this->assertGuest();
    }

    public function test_login_is_rate_limited(): void
    {
        User::factory()->create(['username' => 'test-admin']);

        for ($i = 0; $i < 6; $i++) {
            $this->post(route('login.store'), [
                'username' => 'test-admin',
                'password' => 'wrong',
            ]);
        }

        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'wrong',
        ])->assertStatus(429);
    }

    public function test_diagnostic_logs_never_contain_secrets(): void
    {
        $user = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'password',
        ]);

        $this->post(route('logout'));

        $this->assertDatabaseHas('test_logs', ['event' => 'login_success']);
        $this->assertDatabaseHas('test_logs', ['event' => 'logout']);

        // No stored column may carry a raw session id or credential material;
        // the session is only ever recorded as a non-reversible fingerprint.
        foreach (TestLog::all() as $log) {
            $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{16}$/', (string) $log->session_fingerprint);
            $this->assertNull($log->credential_fingerprint);

            // meta is an allow-list of scalars; a secret-looking key must never
            // survive into it.
            foreach (array_keys($log->meta ?? []) as $key) {
                $this->assertDoesNotMatchRegularExpression('/secret|token|password|key|challenge|cookie/i', $key);
            }
        }
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
