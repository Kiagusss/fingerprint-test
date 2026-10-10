<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TestResult;
use App\Models\User;
use App\Services\Diagnostics;
use Database\Seeders\TestResultSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test for every page, for every role. Guards against view/controller
 * mismatch bugs (e.g. a Blade view referencing a variable the controller never
 * passed, which only shows up for one particular auth state).
 */
class PageSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_landing_page_renders_for_a_guest(): void
    {
        $this->get(route('landing'))->assertOk()->assertSee('Beranda Diagnostik');
    }

    public function test_landing_page_renders_while_authenticated(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($admin)
            ->get(route('landing'))
            ->assertOk()
            ->assertSee('test-admin')
            ->assertSee('Beranda Diagnostik');
    }

    public function test_login_page_renders(): void
    {
        $this->get(route('login'))->assertOk()->assertSee('Masuk');
    }

    public function test_admin_sees_every_page_it_is_entitled_to(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('passkeys.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.test'))->assertOk();
        $this->actingAs($admin)->get(route('biometric.index'))->assertOk();
        $this->actingAs($admin)->get(route('test-results.index'))->assertOk();
        $this->actingAs($admin)->get(route('documentation'))->assertOk();
        $this->actingAs($admin)->get(route('landing'))->assertOk();
    }

    public function test_supervisor_sees_every_page_it_is_entitled_to(): void
    {
        $supervisor = User::factory()->supervisor()->create(['username' => 'test-supervisor']);

        $this->actingAs($supervisor)->get(route('dashboard'))->assertOk();
        $this->actingAs($supervisor)->get(route('passkeys.index'))->assertOk();
        $this->actingAs($supervisor)->get(route('supervisor.test'))->assertOk();
        $this->actingAs($supervisor)->get(route('test-results.index'))->assertOk();
        $this->actingAs($supervisor)->get(route('documentation'))->assertOk();
        $this->actingAs($supervisor)->get(route('landing'))->assertOk();
    }

    public function test_locale_switch_renders_in_both_languages(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('locale', 'en'))->assertRedirect();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->actingAs($admin)->get(route('locale', 'id'))->assertRedirect();
        $this->actingAs($admin)->get(route('dashboard'))->assertOk();

        $this->actingAs($admin)->get(route('locale', 'fr'))->assertNotFound();
    }

    /**
     * The diagnostics snapshot must never expose raw secrets even though it is
     * rendered straight onto the public landing page.
     */
    public function test_diagnostics_snapshot_carries_no_secrets(): void
    {
        $snapshot = app(Diagnostics::class)->snapshot();

        $json = strtolower(json_encode($snapshot, JSON_THROW_ON_ERROR));

        foreach (['password', 'secret', 'private', 'app_key', 'biometric'] as $needle) {
            $this->assertStringNotContainsString($needle, $json);
        }

        $this->assertArrayNotHasKey('db_password', $snapshot);
        $this->assertArrayNotHasKey('app_key', $snapshot);
    }

    public function test_test_results_page_shows_not_tested_for_unrun_manual_cases(): void
    {
        $this->seed(TestResultSeeder::class);

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('test-results.index'))
            ->assertOk()
            ->assertSee('NOT TESTED');

        $this->assertSame(
            10,
            TestResult::query()->where('source', 'manual')->where('status', 'not_tested')->count()
        );
    }
}
