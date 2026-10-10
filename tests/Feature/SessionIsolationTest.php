<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Session isolation. Two independent browsing profiles must keep two separate
 * Laravel identities, and a logout in one must not affect the other.
 */
class SessionIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sessions_keep_separate_identities(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);
        $supervisor = User::factory()->supervisor()->create(['username' => 'test-supervisor']);

        // ---- Profile "Windows A": sign in as the admin ----------------------
        $this->flushSession();
        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $adminSessionId = session()->getId();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('test-admin')
            ->assertDontSee('test-supervisor');

        // ---- Profile "Windows B": a brand new session as the supervisor -----
        $this->forgetAuthentication();
        $this->flushSession();

        $this->post(route('login.store'), [
            'username' => 'test-supervisor',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $supervisorSessionId = session()->getId();

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('test-supervisor')
            ->assertDontSee('test-admin');

        // The two sessions are genuinely different.
        $this->assertNotSame($adminSessionId, $supervisorSessionId);
    }

    public function test_logout_invalidates_the_session(): void
    {
        $user = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();

        // A protected route is no longer reachable with the old state.
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    }

    /**
     * A session probe is recorded for every dashboard visit, letting a tester
     * prove from the log which session served a request. The stored value is a
     * non-reversible fingerprint, never the raw session id.
     */
    public function test_dashboard_records_a_session_probe_fingerprint(): void
    {
        $user = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($user);

        $this->get(route('dashboard'))->assertOk();

        $probe = TestLog::query()->where('event', 'session_probe')->firstOrFail();

        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{16}$/', (string) $probe->session_fingerprint);

        // The fingerprint is a hash of the session id, never the id itself.
        $this->assertStringNotContainsString(session()->getId(), (string) $probe->session_fingerprint);
        $this->assertNotSame(session()->getId(), $probe->session_fingerprint);
    }

    /**
     * Two browsing profiles (Windows A and Windows B) run in different sessions,
     * so the probes they leave behind carry different fingerprints. This is the
     * server-side evidence that the two accounts never shared a session.
     */
    public function test_two_profiles_produce_different_session_fingerprints(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);
        $supervisor = User::factory()->supervisor()->create(['username' => 'test-supervisor']);

        $this->actingAs($admin);
        $this->get(route('dashboard'))->assertOk();
        $adminProbe = TestLog::query()->where('event', 'session_probe')->latest('id')->firstOrFail();

        $this->forgetAuthentication();
        $this->flushSession();

        $this->actingAs($supervisor);
        $this->get(route('dashboard'))->assertOk();
        $supervisorProbe = TestLog::query()->where('event', 'session_probe')->latest('id')->firstOrFail();

        $this->assertNotSame($adminProbe->session_fingerprint, $supervisorProbe->session_fingerprint);
        $this->assertSame($admin->id, $adminProbe->user_id);
        $this->assertSame($supervisor->id, $supervisorProbe->user_id);
    }

    public function test_switching_authenticated_user_does_not_leak_the_previous_identity(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);
        $supervisor = User::factory()->supervisor()->create(['username' => 'test-supervisor']);

        $this->actingAs($admin);
        $this->get(route('dashboard'))->assertSee('test-admin')->assertDontSee('test-supervisor');

        // Emulate the other Windows profile: a fresh session with no leftovers.
        $this->forgetAuthentication();
        $this->flushSession();

        $this->actingAs($supervisor);
        $this->get(route('dashboard'))->assertSee('test-supervisor')->assertDontSee('test-admin');
    }
}
