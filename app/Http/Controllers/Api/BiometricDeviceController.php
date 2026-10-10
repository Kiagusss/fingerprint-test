<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Models\User;
use App\Services\TestLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Endpoint untuk agen pemindai sidik jari (bukan browser).
 *
 * Tiga operasi, semuanya hanya melayani PENDAFTARAN jari. Tidak ada satu pun
 * jalur di sini yang membuat sesi login: identifikasi sidik jari dipakai untuk
 * memilih akun, sedangkan pembuktian identitas tetap password/passkey milik
 * karyawan itu. Itu sebabnya controller ini tidak menyentuh Auth.
 *
 * Kepercayaan pada agen dibatasi: `secret` perangkat membuktikan "request ini
 * datang dari agen terdaftar", bukan bahwa agen jujur. Agen yang nakal hanya
 * bisa menciptakan pendaftaran palsu — yang tidak cukup untuk masuk ke akun
 * siapa pun, dan terlihat di halaman admin sebagai riwayat pendaftaran.
 */
class BiometricDeviceController
{
    /** Maksimum jari aktif per user, supaya pendaftaran tidak bisa dipakai untuk memblokir akun. */
    public const MAX_ACTIVE_FINGERS_PER_USER = 10;

    /** Maksimum ukuran template yang diterima (byte, sebelum base64). */
    public const MAX_TEMPLATE_BYTES = 16384;

    public function __construct(private readonly TestLogger $logger) {}

    /** Denyut nadi: agen memastikan dirinya masih terdaftar dan sinkron jam. */
    public function heartbeat(Request $request): JsonResponse
    {
        $device = $this->device($request);

        return response()->json([
            'ok' => true,
            'device' => [
                'uuid' => $device->uuid,
                'name' => $device->name,
                'host_label' => $device->host_label,
                'platform' => $device->platform,
            ],
            'server_time' => now()->toIso8601String(),
            'enrolled_count' => BiometricEnrollment::query()->active()->count(),
        ]);
    }

    /**
     * Daftar pendaftaran aktif, supaya agen dapat menyusun penyimpanan lokalnya.
     *
     * Template hanya ikut dikirim bila memang tersimpan di server; pada pemindai
     * match-on-host (Chipsailing CS9711) template hidup di PC tempat pendaftaran
     * dan `template_ref` adalah satu-satunya penunjuk yang berlaku di sana.
     */
    public function enrollments(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $rows = BiometricEnrollment::query()
            ->active()
            ->with('user:id,username,name,role')
            ->orderBy('user_id')
            ->get()
            ->map(fn (BiometricEnrollment $enrollment): array => [
                'id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'username' => $enrollment->user?->username,
                'display_name' => $enrollment->user?->name,
                'finger' => $enrollment->finger,
                'template_ref' => $enrollment->template_ref,
                'template_hash' => $enrollment->template_hash,
                // null = hanya ada di perangkat tempat pendaftaran dilakukan.
                'template' => $enrollment->template,
                'quality' => $enrollment->quality,
                'source_device_id' => $enrollment->source_device_id,
                'is_local' => $enrollment->source_device_id === $device->id,
            ])
            ->all();

        return response()->json(['enrollments' => $rows]);
    }

    /** Mencatat satu jari yang baru selesai didaftarkan di agen. */
    public function storeEnrollment(Request $request): JsonResponse
    {
        $device = $this->device($request);

        $data = $request->validate([
            'username' => ['required', 'string', 'max:64'],
            'finger' => ['required', 'string', 'max:32', 'regex:/^[a-z]+(-[a-z]+)*$/'],
            'template_ref' => ['required', 'string', 'max:191'],
            'template' => ['nullable', 'string'],
            'quality' => ['required', 'integer', 'between:0,100'],
            // Data biometrik tidak boleh masuk tanpa persetujuan pemiliknya.
            'consent' => ['required', 'accepted'],
            'replace' => ['nullable', 'boolean'],
        ]);

        $user = User::query()->where('username', $data['username'])->first();

        if (! $user instanceof User) {
            throw ValidationException::withMessages([
                'username' => 'Pengguna dengan username itu tidak ada.',
            ]);
        }

        $template = $this->normaliseTemplate($data['template'] ?? null);

        $replace = (bool) ($data['replace'] ?? false);

        $enrollment = DB::transaction(function () use ($user, $device, $data, $template, $replace): BiometricEnrollment {
            $existing = BiometricEnrollment::query()
                ->active()
                ->where('user_id', $user->getKey())
                ->where('finger', $data['finger'])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof BiometricEnrollment) {
                if (! $replace) {
                    throw ValidationException::withMessages([
                        'finger' => 'Jari itu sudah terdaftar untuk pengguna ini. Kirim replace=true untuk menggantinya.',
                    ]);
                }

                $existing->revoke();
            } elseif (
                BiometricEnrollment::query()->active()->where('user_id', $user->getKey())->count()
                >= self::MAX_ACTIVE_FINGERS_PER_USER
            ) {
                throw ValidationException::withMessages([
                    'username' => 'Batas jumlah jari aktif untuk pengguna ini sudah tercapai.',
                ]);
            }

            return BiometricEnrollment::create([
                'user_id' => $user->getKey(),
                'finger' => $data['finger'],
                'template_ref' => $data['template_ref'],
                'template' => $template,
                'template_hash' => hash('sha256', $template ?? $data['template_ref']),
                'quality' => $data['quality'],
                'source_device_id' => $device->getKey(),
                'enrolled_by' => null, // pendaftaran oleh perangkat, bukan oleh user login
                'consent_at' => now(),
            ]);
        });

        $this->logger->log('biometric_enrolled', [
            'request' => $request,
            'user' => $user,
            'auth_method' => 'fingerprint',
            'role' => $user->role?->value,
            'credential_id' => $enrollment->template_ref,
            'meta' => [
                'device_uuid' => $device->uuid,
                'device_name' => $device->name,
                'finger' => $enrollment->finger,
                'quality' => $enrollment->quality,
                'template_stored' => $template !== null,
            ],
        ]);

        return response()->json([
            'enrollment' => [
                'id' => $enrollment->id,
                'user_id' => $enrollment->user_id,
                'username' => $user->username,
                'finger' => $enrollment->finger,
                'template_ref' => $enrollment->template_ref,
                'template_hash' => $enrollment->template_hash,
                'quality' => $enrollment->quality,
            ],
        ], 201);
    }

    /**
     * Perangkat selalu ditempelkan middleware; null hanya mungkin bila route
     * dipanggil tanpa middleware, dan itu kesalahan konfigurasi yang harus
     * terlihat, bukan diam-diam lolos.
     */
    private function device(Request $request): BiometricDevice
    {
        $device = $request->attributes->get('biometric_device');

        if (! $device instanceof BiometricDevice) {
            abort(500, 'Route biometrik dipanggil tanpa middleware perangkat.');
        }

        return $device;
    }

    /** Template opsional: harus base64 valid dan tidak melebihi batas ukuran. */
    private function normaliseTemplate(?string $raw): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        $decoded = base64_decode($raw, true);

        if ($decoded === false) {
            throw ValidationException::withMessages([
                'template' => 'Template harus base64 yang valid.',
            ]);
        }

        if (strlen($decoded) > self::MAX_TEMPLATE_BYTES) {
            throw ValidationException::withMessages([
                'template' => 'Template terlalu besar.',
            ]);
        }

        return $decoded;
    }
}
