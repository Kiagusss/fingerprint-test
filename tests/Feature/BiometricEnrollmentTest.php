<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\SignsDeviceRequests;
use Tests\TestCase;

/**
 * Lapisan identifikasi sidik jari: perangkat, pendaftaran jari, dan batasnya.
 *
 * Dua hal yang dijaga ketat di sini:
 *   1. Endpoint agen hanya menerima permintaan yang ditandatangani benar oleh
 *      perangkat terdaftar — bukan sekadar "ada header".
 *   2. Lapisan ini TIDAK PERNAH membuat sesi login. Ia hanya mencatat jari milik
 *      siapa; pembuktian identitas tetap password/passkey di /login.
 */
class BiometricEnrollmentTest extends TestCase
{
    use RefreshDatabase;
    use SignsDeviceRequests;

    // ------------------------------------------------------------ perangkat

    public function test_agent_endpoints_reject_a_request_without_signature_headers(): void
    {
        $this->getJson('/api/biometric/heartbeat')->assertUnauthorized();
        $this->postJson('/api/biometric/enrollments', [])->assertUnauthorized();
    }

    public function test_agent_endpoints_reject_an_unknown_device(): void
    {
        [, $secret] = $this->device();

        // Tanda tangan benar, tetapi perangkat dengan UUID itu tidak terdaftar.
        $headers = $this->signature('GET', '/api/biometric/heartbeat', '', $secret, (string) Str::uuid());

        $this->call('GET', '/api/biometric/heartbeat', [], [], [], $this->serverVars($headers), '')
            ->assertUnauthorized();
    }

    public function test_agent_endpoints_reject_a_stale_timestamp(): void
    {
        [$device, $secret] = $this->device();

        $stale = time() - 3600;

        $this->deviceGet($device, $secret, 'api/biometric/heartbeat', $stale)->assertUnauthorized();
    }

    public function test_agent_endpoints_reject_a_tampered_body(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        // Ditandatangani untuk satu body, dikirim body lain.
        $signed = json_encode($this->enrollmentPayload(), JSON_THROW_ON_ERROR);
        $sent = json_encode($this->enrollmentPayload('budi', ['quality' => 5]), JSON_THROW_ON_ERROR);

        $this->withHeaders(
            $this->signature('POST', '/api/biometric/enrollments', $signed, $secret, $device->uuid)
                + ['Accept' => 'application/json', 'Content-Type' => 'application/json']
        )->call('POST', '/api/biometric/enrollments', [], [], [], [], $sent)
            ->assertUnauthorized();

        $this->assertDatabaseCount('biometric_enrollments', 0);
    }

    public function test_a_deactivated_device_is_rejected(): void
    {
        [$device, $secret] = $this->device();

        $this->deviceGet($device, $secret, 'api/biometric/heartbeat')->assertOk();

        $device->update(['active' => false]);

        $this->deviceGet($device->fresh(), $secret, 'api/biometric/heartbeat')->assertUnauthorized();
    }

    public function test_heartbeat_marks_the_device_as_seen(): void
    {
        [$device, $secret] = $this->device();

        $this->assertNull($device->last_seen_at);

        $this->deviceGet($device, $secret, 'api/biometric/heartbeat')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('device.uuid', $device->uuid);

        $this->assertNotNull($device->fresh()->last_seen_at);
    }

    // ------------------------------------------------------------ pendaftaran

    public function test_a_finger_is_enrolled_and_then_visible_to_the_agent(): void
    {
        [$device, $secret] = $this->device();
        $employee = $this->employee();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())
            ->assertCreated()
            ->assertJsonPath('enrollment.username', 'budi')
            ->assertJsonPath('enrollment.finger', 'right-index-finger');

        $this->assertDatabaseHas('biometric_enrollments', [
            'user_id' => $employee->id,
            'finger' => 'right-index-finger',
            'template_ref' => 'tpl-budi-1',
            'source_device_id' => $device->id,
        ]);

        $this->deviceGet($device, $secret, 'api/biometric/enrollments')
            ->assertOk()
            ->assertJsonPath('enrollments.0.username', 'budi')
            ->assertJsonPath('enrollments.0.is_local', true);
    }

    public function test_enrollment_requires_consent_and_a_quality_score(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', ['consent' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('consent');

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', ['quality' => 900]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('quality');

        $this->assertDatabaseCount('biometric_enrollments', 0);
    }

    public function test_enrolling_an_unknown_user_is_rejected(): void
    {
        [$device, $secret] = $this->device();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('tidak-ada'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('username');
    }

    public function test_the_same_finger_needs_replace_and_the_old_row_is_revoked(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())->assertCreated();

        // Pendaftaran ulang tanpa replace harus ditolak, bukan menimpa diam-diam.
        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', ['template_ref' => 'tpl-budi-2']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('finger');

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', [
            'template_ref' => 'tpl-budi-2',
            'replace' => true,
        ]))->assertCreated();

        $this->assertSame(1, BiometricEnrollment::query()->active()->count());
        $this->assertSame(2, BiometricEnrollment::query()->count());
        $this->assertSame('tpl-budi-2', BiometricEnrollment::query()->active()->value('template_ref'));
    }

    public function test_a_stored_template_is_encrypted_at_rest(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $raw = random_bytes(512);

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', [
            'template' => base64_encode($raw),
        ]))->assertCreated();

        // Nilai mentah di kolom harus ciphertext, bukan template aslinya.
        $stored = \DB::table('biometric_enrollments')->value('template');

        $this->assertIsString($stored);
        $this->assertStringNotContainsString(base64_encode($raw), (string) $stored);

        // Dan model tetap mengembalikannya utuh.
        $this->assertSame($raw, BiometricEnrollment::query()->firstOrFail()->template);
    }

    public function test_an_oversized_template_is_rejected(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload('budi', [
            'template' => base64_encode(random_bytes(32 * 1024)),
        ]))->assertStatus(422)->assertJsonValidationErrors('template');
    }

    // ------------------------------------------------------------ batas keamanan

    public function test_the_biometric_layer_never_creates_a_session(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $this->deviceGet($device, $secret, 'api/biometric/heartbeat')->assertOk();
        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())->assertCreated();
        $this->deviceGet($device, $secret, 'api/biometric/enrollments')->assertOk();

        // Identifikasi sidik jari tidak boleh cukup untuk masuk.
        $this->assertGuest();
    }

    public function test_only_admins_reach_the_management_page(): void
    {
        $this->get(route('biometric.index'))->assertRedirect(route('login'));

        $employee = $this->employee();

        $this->actingAs($employee)->get(route('biometric.index'))->assertForbidden();

        $admin = User::factory()->admin()->create(['username' => 'admin-1']);

        $this->actingAs($admin)->get(route('biometric.index'))->assertOk()->assertSee('Perangkat Sidik Jari');
    }

    public function test_creating_a_device_from_the_ui_shows_the_secret_once_and_stores_it_encrypted(): void
    {
        $admin = User::factory()->admin()->create(['username' => 'admin-1']);

        $response = $this->actingAs($admin)->post(route('biometric.store'), [
            'name' => 'PC Kasir',
            'host_label' => 'KASIR-02',
            'platform' => 'windows',
        ]);

        $response->assertRedirect(route('biometric.index'));
        $response->assertSessionHas('biometric_device_secret');

        $plaintext = session('biometric_device_secret');

        $this->assertIsString($plaintext);
        $this->assertNotSame('', $plaintext);

        // Kolom mentah bukan plaintext, tetapi model mengembalikannya utuh.
        $this->assertNotSame($plaintext, (string) \DB::table('biometric_devices')->value('secret'));
        $this->assertSame($plaintext, BiometricDevice::query()->firstOrFail()->secret);
    }

    public function test_an_admin_can_revoke_an_enrollment_and_the_agent_stops_receiving_it(): void
    {
        [$device, $secret] = $this->device();
        $this->employee();

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())->assertCreated();

        $enrollment = BiometricEnrollment::query()->firstOrFail();

        $this->deviceGet($device, $secret, 'api/biometric/enrollments')
            ->assertOk()
            ->assertJsonCount(1, 'enrollments');

        $admin = User::factory()->admin()->create(['username' => 'admin-1']);

        $this->actingAs($admin)
            ->post(route('biometric.revoke', $enrollment))
            ->assertRedirect();

        $this->assertNotNull($enrollment->fresh()->revoked_at);
        $this->assertSame($admin->id, $enrollment->fresh()->revoked_by);

        $this->deviceGet($device, $secret, 'api/biometric/enrollments')
            ->assertOk()
            ->assertJsonCount(0, 'enrollments');
    }

    // ------------------------------------------------- identifikasi (catatan audit)

    public function test_a_successful_identification_is_logged_without_creating_a_session(): void
    {
        [$device, $secret] = $this->device();
        $this->employee('budi');

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())->assertCreated();

        $this->devicePost($device, $secret, 'api/biometric/identifications', [
            'matched' => true,
            'template_ref' => 'tpl-budi-1',
            'quality' => 0,
        ])
            ->assertCreated()
            ->assertJsonPath('identification.matched', true)
            ->assertJsonPath('identification.username', 'budi')
            ->assertJsonPath('identification.finger', 'right-index-finger');

        $this->assertDatabaseHas('test_logs', [
            'event' => 'biometric_identified',
            'user_id' => User::query()->where('username', 'budi')->value('id'),
            'auth_method' => 'fingerprint',
        ]);

        // Sidik jari mengidentifikasi; ia tidak pernah mengautentikasi.
        $this->assertGuest();
    }

    public function test_an_identification_with_an_unknown_template_is_rejected(): void
    {
        [$device, $secret] = $this->device();

        $this->devicePost($device, $secret, 'api/biometric/identifications', [
            'matched' => true,
            'template_ref' => 'tpl-tidak-pernah-didaftarkan',
        ])->assertStatus(422);

        $this->assertDatabaseCount('test_logs', 0);
    }

    public function test_a_revoked_enrollment_can_no_longer_be_claimed_as_a_match(): void
    {
        [$device, $secret] = $this->device();
        $this->employee('budi');

        $this->devicePost($device, $secret, 'api/biometric/enrollments', $this->enrollmentPayload())->assertCreated();

        BiometricEnrollment::query()->firstOrFail()->revoke();

        $this->devicePost($device, $secret, 'api/biometric/identifications', [
            'matched' => true,
            'template_ref' => 'tpl-budi-1',
        ])->assertStatus(422);
    }

    public function test_a_failed_match_is_logged_as_well(): void
    {
        [$device, $secret] = $this->device();

        $this->devicePost($device, $secret, 'api/biometric/identifications', ['matched' => false])
            ->assertCreated()
            ->assertJsonPath('identification.matched', false)
            ->assertJsonPath('identification.username', null);

        $this->assertDatabaseHas('test_logs', [
            'event' => 'biometric_identified',
            'user_id' => null,
        ]);

        $this->assertGuest();
    }

    public function test_identification_rejects_an_unsigned_or_tampered_request(): void
    {
        [$device, $secret] = $this->device();

        $this->postJson('/api/biometric/identifications', ['matched' => false])->assertUnauthorized();

        // Ditandatangani untuk satu body, dikirim body lain.
        $signed = json_encode(['matched' => false], JSON_THROW_ON_ERROR);
        $sent = json_encode(['matched' => true, 'template_ref' => 'tpl-budi-1'], JSON_THROW_ON_ERROR);

        $this->withHeaders(
            $this->signature('POST', '/api/biometric/identifications', $signed, $secret, $device->uuid)
                + ['Accept' => 'application/json', 'Content-Type' => 'application/json']
        )->call('POST', '/api/biometric/identifications', [], [], [], [], $sent)
            ->assertUnauthorized();

        $this->assertDatabaseCount('test_logs', 0);
    }
}
