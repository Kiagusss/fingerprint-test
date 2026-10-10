<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Tests\TestCase;

/**
 * Pendaftaran sidik jari karyawan oleh admin — ceremony WebAuthn asli, bukan
 * stub. Bukti yang dikejar:
 *   - akun karyawan benar-benar tanpa password dan tanpa email yang bisa dipakai;
 *   - sidik jari tersimpan sebagai milik karyawan, bukan milik admin yang
 *     menjalankan ceremony;
 *   - karyawan bisa masuk dengan sidik jarinya sendiri (passwordless);
 *   - otorisasi: hanya admin, hanya untuk target ber-role employee, dan hanya
 *     bila sesi memang dikunci untuk target itu.
 */
class EmployeeEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Uji gerbang konfirmasi secara eksplisit, bukan jalur kenyamanan lokal.
        config(['passkeys.bootstrap_without_confirmation' => false]);
    }

    /** Admin membuat akun karyawan lewat controller (tanpa password). */
    private function createEmployee(User $admin, string $username, string $name): User
    {
        $this->actingAs($admin)
            ->post(route('employees.create'), ['username' => $username, 'name' => $name])
            ->assertRedirect(route('employees.index'));

        return User::query()->where('username', $username)->firstOrFail();
    }

    /** Admin siap: terautentikasi dan sudah melewati gerbang konfirmasi. */
    private function actingAsConfirmedAdmin(): User
    {
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($admin);
        // Menandai sesi persis seperti ceremony passkey.confirm (session()->passwordConfirmed()).
        $this->confirmPassword();

        return $admin;
    }

    public function test_admin_enrolls_a_fingerprint_for_an_employee_end_to_end(): void
    {
        $admin = $this->actingAsConfirmedAdmin();

        $employee = $this->createEmployee($admin, 'budi', 'Budi Santoso');

        // Akun karyawan: nol password, nol email yang bisa dipakai.
        $this->assertNull($employee->password);
        $this->assertNull($employee->email);
        $this->assertSame(Role::Employee, $employee->role);

        // Kunci sesi ke karyawan ini.
        $this->post(route('employees.prepare', $employee))
            ->assertRedirect(route('employees.index'));

        // Opsi registrasi harus diterbitkan untuk KARYAWAN.
        $optionsResponse = $this->postJson(route('employees.options', $employee))->assertOk();
        $browser = $optionsResponse->json('options');

        $this->assertSame(
            Base64UrlSafe::encodeUnpadded($employee->getPasskeyUserHandle()),
            $browser['user']['id'],
        );
        $this->assertSame('budi', $browser['user']['name']);
        $this->assertSame('Budi Santoso', $browser['user']['displayName']);

        // Tempelkan "sidik jari" karyawan (software authenticator).
        $auth = $this->authenticator();
        $challenge = Base64UrlSafe::decodeNoPadding($browser['challenge']);

        $this->postJson(route('employees.store-passkey', $employee), [
            'name' => 'Windows Hello — budi',
            'credential' => $auth->attest($challenge),
        ])->assertOk();

        // Kredensial milik karyawan, BUKAN milik admin yang menjalankan ceremony.
        $this->assertSame(1, $employee->fresh()->passkeys()->count());
        $this->assertSame(0, $admin->fresh()->passkeys()->count());

        $passkey = $employee->passkeys()->firstOrFail();
        $this->assertSame($employee->id, $passkey->user_id);

        // Karyawan masuk dengan sidik jarinya, tanpa password.
        $this->forgetAuthentication();
        $this->loginWithPasskey($auth, $employee)->assertOk();
        $this->assertAuthenticatedAs($employee->fresh());

        // Jejak audit: aksi tercatat pada admin, hasil pada karyawan.
        $this->assertDatabaseHas('test_logs', [
            'event' => 'employee_created',
            'user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('test_logs', [
            'event' => 'employee_passkey_enrolled',
            'user_id' => $admin->id,
        ]);
    }

    public function test_target_must_be_an_employee_account(): void
    {
        $this->actingAsConfirmedAdmin();
        $supervisor = User::factory()->supervisor()->create();

        $this->post(route('employees.prepare', $supervisor))->assertNotFound();
        $this->postJson(route('employees.options', $supervisor))->assertNotFound();
    }

    public function test_options_are_rejected_when_the_session_is_not_locked_to_that_employee(): void
    {
        $admin = $this->actingAsConfirmedAdmin();

        $budi = $this->createEmployee($admin, 'budi', 'Budi');
        $siti = $this->createEmployee($admin, 'siti', 'Siti');

        // Sesi dikunci untuk Budi...
        $this->post(route('employees.prepare', $budi))->assertRedirect();

        // ...sehingga permintaan opsi untuk Siti harus ditolak.
        $this->postJson(route('employees.options', $siti))->assertStatus(409);
    }

    public function test_only_an_admin_can_reach_the_enrollment_page(): void
    {
        $admin = $this->actingAsConfirmedAdmin();
        $employee = $this->createEmployee($admin, 'budi', 'Budi');

        // Karyawan: ditolak.
        $this->actingAs($employee)
            ->get(route('employees.index'))
            ->assertForbidden();

        // Supervisor: ditolak.
        $supervisor = User::factory()->supervisor()->create();
        $this->actingAs($supervisor)
            ->get(route('employees.index'))
            ->assertForbidden();

        // Admin: diterima (halaman baca tidak butuh konfirmasi ulang).
        $this->actingAs($admin)
            ->get(route('employees.index'))
            ->assertOk();
    }

    public function test_enrollment_actions_require_confirmation(): void
    {
        // Admin, tetapi BELUM dikonfirmasi ulang.
        $admin = User::factory()->admin()->create(['username' => 'test-admin']);

        $this->actingAs($admin)
            ->postJson(route('employees.create'), ['username' => 'budi', 'name' => 'Budi'])
            ->assertStatus(423);

        $this->assertDatabaseMissing('users', ['username' => 'budi']);
    }

    public function test_admin_can_revoke_an_employee_passkey(): void
    {
        $admin = $this->actingAsConfirmedAdmin();
        $employee = $this->createEmployee($admin, 'budi', 'Budi');

        $this->post(route('employees.prepare', $employee));
        $challenge = Base64UrlSafe::decodeNoPadding(
            $this->postJson(route('employees.options', $employee))->json('options.challenge')
        );

        $this->postJson(route('employees.store-passkey', $employee), [
            'name' => 'Windows Hello — budi',
            'credential' => $this->authenticator()->attest($challenge),
        ])->assertOk();

        $passkey = $employee->passkeys()->firstOrFail();

        $this->deleteJson(route('employees.revoke', $passkey))->assertOk();

        $this->assertSame(0, $employee->fresh()->passkeys()->count());
        $this->assertDatabaseHas('test_logs', [
            'event' => 'employee_passkey_revoked',
            'user_id' => $admin->id,
        ]);
    }

    public function test_supervisor_cannot_revoke_an_employee_passkey(): void
    {
        $admin = $this->actingAsConfirmedAdmin();
        $employee = $this->createEmployee($admin, 'budi', 'Budi');

        $this->post(route('employees.prepare', $employee));
        $challenge = Base64UrlSafe::decodeNoPadding(
            $this->postJson(route('employees.options', $employee))->json('options.challenge')
        );
        $this->postJson(route('employees.store-passkey', $employee), [
            'name' => 'key',
            'credential' => $this->authenticator()->attest($challenge),
        ])->assertOk();

        $passkey = $employee->passkeys()->firstOrFail();

        $supervisor = User::factory()->supervisor()->create();

        $this->actingAs($supervisor)
            ->deleteJson(route('employees.revoke', $passkey))
            ->assertForbidden();

        $this->assertSame(1, $employee->fresh()->passkeys()->count());
    }
}
