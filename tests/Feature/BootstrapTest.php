<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

/**
 * Bootstrap admin pertama tanpa password.
 *
 * Rute ini adalah satu-satunya cara masuk pada instalasi passwordless yang
 * benar-benar baru, jadi batasannya harus diuji: hanya sebelum ada user, dan di
 * luar environment local hanya dengan kunci yang cocok (gagal-tertutup).
 */
class BootstrapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Tes ini mengubah app env menjadi `local`/`production`, yang membuat
        // CSRF middleware aktif. Daripada mematikannya (dan berisiko membuat tes
        // tidak lagi merepresentasikan permintaan nyata), sediakan token yang
        // cocok persis seperti browser sungguhan mengirimkannya.
        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf');
    }

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_bootstrap_registers_the_first_admin_with_a_passkey_only(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('bootstrap.show'))->assertOk();

        $optionsResponse = $this->postJson(route('bootstrap.options'), [
            'username' => 'admin',
            'name' => 'Admin IT',
        ])->assertOk();

        $admin = User::query()->where('username', 'admin')->firstOrFail();

        // Nol password, nol email yang bisa dipakai.
        $this->assertNull($admin->password);
        $this->assertNull($admin->email);
        $this->assertSame(Role::Admin, $admin->role);

        $browser = $optionsResponse->json('options');
        $challenge = Base64UrlSafe::decodeNoPadding($browser['challenge']);
        $auth = $this->authenticator();

        $this->postJson(route('bootstrap.store'), [
            'name' => 'Windows Hello — admin',
            'credential' => $auth->attest($challenge),
        ])->assertOk();

        $this->assertSame(1, $admin->fresh()->passkeys()->count());

        // Admin langsung bisa masuk dengan sidik jarinya. flushSession() di
        // dalam forgetAuthentication() juga menghapus token CSRF, jadi tanam
        // ulang sebelum POST berikutnya.
        $this->forgetAuthentication();
        $this->withSession(['_token' => 'test-csrf']);

        $this->loginWithPasskey($auth, $admin)->assertOk();
        $this->assertAuthenticatedAs($admin->fresh());
    }

    public function test_bootstrap_closes_itself_once_any_user_exists(): void
    {
        $this->app['env'] = 'local';

        $this->get(route('bootstrap.show'))->assertOk();

        User::factory()->create();

        $this->get(route('bootstrap.show'))->assertNotFound();
        $this->postJson(route('bootstrap.options'), ['username' => 'admin', 'name' => 'Admin IT'])
            ->assertNotFound();
    }

    public function test_bootstrap_is_fail_closed_outside_local_without_a_key(): void
    {
        $this->app['env'] = 'production';

        // Tidak ada user, tetapi tidak ada BOOTSTRAP_KEY -> 404.
        $this->get(route('bootstrap.show'))->assertNotFound();
        $this->postJson(route('bootstrap.options'), ['username' => 'admin', 'name' => 'Admin IT'])
            ->assertNotFound();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_bootstrap_requires_a_matching_key_outside_local(): void
    {
        $this->app['env'] = 'production';
        config(['auth.bootstrap_key' => 'rahasia-sekali']);

        $this->get(route('bootstrap.show', ['key' => 'salah']))->assertNotFound();
        $this->get(route('bootstrap.show', ['key' => 'rahasia-sekali']))->assertOk();
    }
}
