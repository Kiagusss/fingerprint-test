<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\Role;
use App\Models\BiometricDevice;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Helper bersama untuk menguji endpoint agen: perangkat terdaftar, tanda tangan
 * HMAC, dan pemanggilan GET/POST yang benar.
 *
 * Dipakai `BiometricEnrollmentTest` dan `FingerprintLoginTest` supaya aturan
 * tanda tangan hanya ditulis di satu tempat — termasuk jebakan body: tanda
 * tangan mencakup hash body MENTAH, jadi agen harus menandatangani persis byte
 * yang ia kirim ("[]" dan "" menghasilkan tanda tangan berbeda).
 */
trait SignsDeviceRequests
{
    /** Perangkat terdaftar + secret jelasnya (di DB tersimpan terenkripsi). */
    protected function device(array $attributes = []): array
    {
        $secret = BiometricDevice::generateSecret();

        $device = BiometricDevice::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'name' => 'PC Resepsionis',
            'host_label' => 'RESEPSI-01',
            'platform' => 'windows',
            'secret' => $secret,
            'active' => true,
        ], $attributes));

        return [$device, $secret];
    }

    /**
     * Header tanda tangan yang benar untuk sebuah request.
     *
     * @return array<string, string>
     */
    protected function signature(
        string $method,
        string $path,
        string $body,
        string $secret,
        string $uuid,
        ?int $timestamp = null,
    ): array {
        $timestamp ??= time();

        $canonical = implode("\n", [
            $method,
            $path,
            (string) $timestamp,
            hash('sha256', $body),
        ]);

        return [
            'X-Device-Id' => $uuid,
            'X-Device-Timestamp' => (string) $timestamp,
            'X-Device-Signature' => hash_hmac('sha256', $canonical, $secret),
        ];
    }

    /**
     * Header menjadi variabel server yang dipahami Symfony.
     *
     * Wajib dibuat manual karena `call()` TIDAK menerapkan `withHeaders()` —
     * hanya `json()`/`get()`/`post()` yang melakukannya (lewat
     * transformHeadersToServerVars). Tanpa ini, header X-Device-* hilang dan
     * setiap request tampak seperti request tanpa tanda tangan.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    protected function serverVars(array $headers): array
    {
        $server = [];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }

    /**
     * GET bertanda tangan ke endpoint agen.
     *
     * Body dikirim eksplisit kosong — bukan lewat `getJson()`, yang diam-diam
     * mengirim body "[]".
     */
    protected function deviceGet(BiometricDevice $device, string $secret, string $path, ?int $timestamp = null)
    {
        $full = '/'.$path;
        $body = '';

        return $this->call('GET', $full, [], [], [], $this->serverVars(
            $this->signature('GET', $full, $body, $secret, $device->uuid, $timestamp)
        ), $body);
    }

    /** POST bertanda tangan, dengan isi body apa adanya (bukan array). */
    protected function devicePost(BiometricDevice $device, string $secret, string $path, array $payload, ?int $timestamp = null)
    {
        $full = '/'.$path;
        $body = json_encode($payload, JSON_THROW_ON_ERROR);

        $server = $this->serverVars(
            $this->signature('POST', $full, $body, $secret, $device->uuid, $timestamp)
        );
        $server['CONTENT_TYPE'] = 'application/json';

        return $this->call('POST', $full, [], [], [], $server, $body);
    }

    protected function employee(string $username = 'budi'): User
    {
        return User::create([
            'username' => $username,
            'name' => 'Budi Santoso',
            'email' => null,
            'password' => null,
            'role' => Role::Employee,
        ]);
    }

    /** @return array<string, mixed> */
    protected function enrollmentPayload(string $username = 'budi', array $overrides = []): array
    {
        return array_merge([
            'username' => $username,
            'finger' => 'right-index-finger',
            'template_ref' => 'tpl-'.$username.'-1',
            'quality' => 72,
            'consent' => true,
        ], $overrides);
    }
}
