<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Kebijakan "Windows Hello saja".
 *
 * Paket `laravel/passkeys` meminta authenticator apa pun (no-preference). Opsi
 * yang diterbitkan server harus dipersempit ke authenticator platform bawaan
 * perangkat, supaya karyawan tidak bisa mendaftarkan ponsel lewat QR, security
 * key USB, atau passkey yang disinkronkan ke akun Microsoft/Google — melainkan
 * hanya perangkat kerja masing-masing.
 *
 * Yang diuji di sini adalah KONTRAK yang dikirim server ke browser, karena
 * itulah yang menentukan apa yang ditawarkan browser kepada pengguna:
 *   - authenticatorSelection.authenticatorAttachment = platform (pembatas keras);
 *   - hints = ['client-device'] (pengarahan UI ke authenticator perangkat ini).
 *
 * Semua jalur diperiksa: registrasi mandiri, pendaftaran karyawan oleh admin,
 * bootstrap admin pertama, login passwordless, dan konfirmasi ulang.
 */
class WindowsHelloOnlyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Uji gerbang konfirmasi secara eksplisit, bukan jalur kenyamanan lokal.
        config(['passkeys.bootstrap_without_confirmation' => false]);
    }

    /** Admin terautentikasi yang sudah melewati gerbang konfirmasi. */
    private function confirmedAdmin(): User
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($admin);
        $this->confirmPassword();

        return $admin;
    }

    /** Karyawan tanpa password dan tanpa email. */
    private function employee(): User
    {
        return User::create([
            'username' => 'budi',
            'name' => 'Budi Santoso',
            'email' => null,
            'password' => null,
            'role' => Role::Employee,
        ]);
    }

    public function test_self_service_registration_only_accepts_platform_authenticators(): void
    {
        $this->confirmedAdmin();

        $options = $this->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->json('options');

        $this->assertSame('platform', $options['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame('required', $options['authenticatorSelection']['residentKey']);
        $this->assertSame('required', $options['authenticatorSelection']['userVerification']);

        // Tanpa hint ini, Chromium menampilkan pemilihnya sendiri lebih dulu
        // (ponsel / security key) karena residentKey diwajibkan.
        $this->assertSame(['client-device'], $options['hints']);
    }

    public function test_employee_enrollment_only_accepts_platform_authenticators(): void
    {
        $this->confirmedAdmin();

        $employee = $this->employee();

        $this->post(route('employees.prepare', $employee))
            ->assertRedirect(route('employees.index'));

        $options = $this->postJson(route('employees.options', $employee))
            ->assertOk()
            ->json('options');

        $this->assertSame('platform', $options['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame(['client-device'], $options['hints']);
    }

    public function test_bootstrap_only_accepts_platform_authenticators(): void
    {
        // Seperti BootstrapTest: mengubah env ke `local` mengaktifkan CSRF,
        // jadi sertakan token yang cocok alih-alih mematikan middleware.
        $this->app['env'] = 'local';
        $this->withSession(['_token' => 'test-csrf'])
            ->withHeader('X-CSRF-TOKEN', 'test-csrf');

        $options = $this->postJson(route('bootstrap.options'), [
            'username' => 'admin',
            'name' => 'Admin IT',
        ])->assertOk()->json('options');

        $this->assertSame('platform', $options['authenticatorSelection']['authenticatorAttachment']);
        $this->assertSame(['client-device'], $options['hints']);
    }

    public function test_passwordless_login_options_ask_for_the_client_device(): void
    {
        // Sisi login adalah permintaan guest, dan `hints` adalah satu-satunya
        // tuas UI di sini: PublicKeyCredentialRequestOptions tidak punya
        // authenticatorAttachment, sementara allowCredentials sengaja kosong
        // (discoverable credential) — kombinasi yang memicu pemilih bawaan
        // Chromium berisi "gunakan ponsel, tablet, atau security key".
        $this->assertSame(
            ['client-device'],
            $this->getJson(route('passkey.login-options'))->assertOk()->json('options.hints'),
        );
    }

    public function test_confirmation_options_ask_for_the_client_device(): void
    {
        $this->confirmedAdmin();

        $this->assertSame(
            ['client-device'],
            $this->getJson(route('passkey.confirm-options'))->assertOk()->json('options.hints'),
        );
    }

    public function test_policy_can_be_relaxed_through_config(): void
    {
        $this->confirmedAdmin();

        config([
            'passkeys.authenticator_attachment' => 'no-preference',
            'passkeys.hints' => '',
        ]);

        $options = $this->getJson(route('passkey.registration-options'))
            ->assertOk()
            ->json('options');

        // no-preference = null, dan paket membuang nilai null dari payload browser.
        $this->assertArrayNotHasKey('authenticatorAttachment', $options['authenticatorSelection']);

        // Hints kosong = tidak ada preferensi; kuncinya hilang dari payload.
        $this->assertArrayNotHasKey('hints', $options);
    }
}
