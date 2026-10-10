<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Server-side authorisation. The role is always read from users.role; anything
 * the client supplies is ignored.
 */
class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.test'))->assertRedirect(route('login'));
        $this->get(route('supervisor.test'))->assertRedirect(route('login'));
        $this->get(route('passkeys.index'))->assertRedirect(route('login'));
        $this->get(route('test-results.index'))->assertRedirect(route('login'));
        $this->get(route('documentation'))->assertRedirect(route('login'));
    }

    public function test_cross_role_access_is_forbidden(): void
    {
        $admin = User::factory()->admin()->create();
        $supervisor = User::factory()->supervisor()->create();

        // Admin reaches the admin page, is refused the supervisor page.
        $this->actingAs($admin)->get(route('admin.test'))->assertOk();
        $this->actingAs($admin)->get(route('supervisor.test'))->assertForbidden();

        // Supervisor reaches the supervisor page, is refused the admin page.
        $this->actingAs($supervisor)->get(route('supervisor.test'))->assertOk();
        $this->actingAs($supervisor)->get(route('admin.test'))->assertForbidden();

        // A plain user with no role reaches neither.
        $plain = User::factory()->create();
        $this->actingAs($plain)->get(route('admin.test'))->assertForbidden();
        $this->actingAs($plain)->get(route('supervisor.test'))->assertForbidden();
    }

    public function test_client_supplied_role_is_ignored(): void
    {
        $supervisor = User::factory()->supervisor()->create();

        // Role smuggled via query string, form body and headers: all ignored.
        $this->actingAs($supervisor)
            ->get(route('admin.test').'?role=admin&username=test-admin')
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->withHeaders(['X-Role' => 'admin', 'X-User' => 'test-admin'])
            ->get(route('admin.test'))
            ->assertForbidden();

        $this->actingAs($supervisor)
            ->getJson(route('admin.test'), ['X-Role' => 'admin'])
            ->assertForbidden();

        // A POST carrying a forged role never reaches the page either (the route
        // is GET-only, so it is rejected before any handler runs).
        $this->actingAs($supervisor)
            ->post(route('admin.test'), ['role' => 'admin'])
            ->assertStatus(405);
    }

    public function test_passkey_registration_requires_authentication(): void
    {
        $this->getJson(route('passkey.registration-options'))->assertUnauthorized();

        $this->postJson(route('passkey.store'), [
            'name' => 'nope',
            'credential' => ['id' => 'x'],
        ])->assertUnauthorized();
    }

    public function test_passkey_registration_requires_password_confirmation(): void
    {
        $user = User::factory()->admin()->create();

        // Authenticated but not confirmed -> password.confirm returns 423.
        $this->actingAs($user)
            ->getJson(route('passkey.registration-options'))
            ->assertStatus(423);
    }

    public function test_passkey_management_routes_are_gated_by_confirmation(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)->getJson(route('passkey.confirm-options'))->assertOk();
    }

    public function test_post_without_csrf_token_is_rejected(): void
    {
        // Force the app out of the "testing" env so the CSRF middleware is not
        // skipped by runningUnitTests() — this must prove the real check runs.
        $this->app['env'] = 'local';

        $this->post(route('login.store'), [
            'username' => 'test-admin',
            'password' => 'password',
        ])->assertStatus(419);
    }
}
