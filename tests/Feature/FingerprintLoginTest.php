<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\BiometricLoginTicket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/**
 * Masuk dengan sidik jari, satu langkah.
 *
 * Ini keputusan pemilik aplikasi (11 Okt 2026): jari saja cukup untuk masuk,
 * seperti Windows Hello. Batas yang menyertainya tertulis di docs/BIOMETRIC.md
 * §2; yang dijaga tes ini adalah bentuk teknisnya:
 *
 *   1. Tiket hanya terbit untuk agen terdaftar, dan hanya bila `template_ref`
 *      menunjuk pendaftaran yang MASIH AKTIF.
 *   2. Tiket sekali pakai dan berumur pendek.
 *   3. Token mentah tidak pernah tersimpan — hanya sha256-nya.
 *   4. Login ini tercatat sebagai 'fingerprint', bukan 'password'.
 */
class FingerprintLoginTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    /** Bikin karyawan + pendaftaran jarinya lewat jalur agen yang sebenarnya. */
    private function enrolledEmployee(BiometricDevice $device, string $secret, string $username = 'budi'): void
    {
        $this->employee($username);

        $this->devicePost(
            $device,
            $secret,
            'api/biometric/enrollments',
            $this->enrollmentPayload($username),
        )->assertCreated();
    }

    /** Path relatif dari URL tiket yang dikembalikan server. */
    private function pathOf(string $url): string
    {
        return (string) parse_url($url, PHP_URL_PATH);
    }

    private function issueTicket(BiometricDevice $device, string $secret, string $templateRef = 'tpl-budi-1')
    {
        return $this->devicePost($device, $secret, 'api/biometric/login-tickets', [
            'template_ref' => $templateRef,
        ]);
    }

    public function test_a_matching_fingerprint_lets_the_worker_in(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $this->assertGuest();

        $response = $this->issueTicket($device, $secret)->assertCreated();

        $response->assertJsonPath('login.username', 'budi');
        $this->assertStringContainsString('/fingerprint/login/', (string) $response->json('login.url'));

        // Agen membukakan URL itu di browser PC yang sama.
        $this->get($this->pathOf((string) $response->json('login.url')))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs(User::query()->where('username', 'budi')->firstOrFail());
        $this->assertDatabaseHas('test_logs', [
            'event' => 'fingerprint_login_success',
            'auth_method' => 'fingerprint',
        ]);
    }

    public function test_a_ticket_can_only_be_used_once(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $path = $this->pathOf((string) $this->issueTicket($device, $secret)->json('login.url'));

        $this->get($path)->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        // Keluar dulu — seperti orang berikutnya di PC bersama.
        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertGuest();

        // Tiket yang sama tidak bisa dipakai dua kali.
        $this->get($path)->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertDatabaseHas('test_logs', ['event' => 'fingerprint_login_failed']);
    }

    public function test_an_expired_ticket_is_refused(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $path = $this->pathOf((string) $this->issueTicket($device, $secret)->json('login.url'));

        // Tiket berumur 60 detik; percepat waktu alih-alih menunggu.
        $this->travel(2)->minutes();

        $this->get($path)->assertRedirect(route('login'));
        $this->assertGuest();

        $this->travelBack();
    }

    public function test_a_ticket_cannot_be_issued_for_an_unknown_or_revoked_enrollment(): void
    {
        [$device, $secret] = $this->device();

        $this->issueTicket($device, $secret, 'tpl-tidak-pernah-ada')->assertStatus(422);
        $this->assertDatabaseCount('biometric_login_tickets', 0);

        $this->enrolledEmployee($device, $secret);
        BiometricEnrollment::query()->firstOrFail()->revoke();

        $this->issueTicket($device, $secret)->assertStatus(422);
        $this->assertDatabaseCount('biometric_login_tickets', 0);
    }

    public function test_only_a_signed_device_can_issue_a_ticket(): void
    {
        $this->postJson('/api/biometric/login-tickets', ['template_ref' => 'tpl-budi-1'])
            ->assertUnauthorized();

        // Ditandatangani dengan secret yang salah.
        [$device] = $this->device();
        $this->employee();

        $headers = $this->signature('POST', '/api/biometric/login-tickets', '{}', 'secret-yang-salah', $device->uuid);

        $this->withHeaders($headers + ['Accept' => 'application/json'])
            ->postJson('/api/biometric/login-tickets', ['template_ref' => 'tpl-budi-1'])
            ->assertUnauthorized();
    }

    public function test_the_raw_token_is_never_stored(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $url = (string) $this->issueTicket($device, $secret)->assertCreated()->json('login.url');
        $token = basename($this->pathOf($url));

        $ticket = BiometricLoginTicket::query()->firstOrFail();

        $this->assertNotSame($token, $ticket->token_hash);
        $this->assertSame(BiometricLoginTicket::hash($token), $ticket->token_hash);
        $this->assertSame($device->id, $ticket->source_device_id);
        $this->assertSame('right-index-finger', $ticket->finger);
        $this->assertSame('budi', $ticket->user?->username);
        $this->assertTrue($ticket->isUsable());
    }

    public function test_the_login_is_recorded_as_a_fingerprint_login(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $path = $this->pathOf((string) $this->issueTicket($device, $secret)->json('login.url'));

        $this->get($path)->assertRedirect(route('dashboard'));

        $user = User::query()->where('username', 'budi')->firstOrFail();

        $this->assertSame('fingerprint', $user->last_auth_method);
        $this->assertNotNull($user->last_authenticated_at);

        // Pendengar Login tetap mencatat login_success; metodenya harus sidik jari.
        $this->assertDatabaseHas('test_logs', [
            'event' => 'login_success',
            'auth_method' => 'fingerprint',
        ]);
    }

    public function test_the_agent_ticket_route_is_audited_as_an_identification(): void
    {
        [$device, $secret] = $this->device();
        $this->enrolledEmployee($device, $secret);

        $this->issueTicket($device, $secret)->assertCreated();

        $this->assertDatabaseHas('test_logs', [
            'event' => 'biometric_identified',
            'auth_method' => 'fingerprint',
        ]);
    }
}
