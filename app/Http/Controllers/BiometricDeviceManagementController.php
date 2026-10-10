<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BiometricDevice;
use App\Models\BiometricEnrollment;
use App\Services\TestLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Halaman admin untuk perangkat pemindai dan pendaftaran sidik jari.
 *
 * Hanya role admin. Halaman ini TIDAK membuat sesi dari sidik jari: ia hanya
 * mengelola perangkat yang boleh melaporkan pendaftaran, menampilkan apa yang
 * sudah terdaftar, dan mencabutnya.
 */
class BiometricDeviceManagementController extends Controller
{
    public function __construct(private readonly TestLogger $logger) {}

    public function index(Request $request): View
    {
        $enrollments = BiometricEnrollment::query()
            ->with(['user:id,username,name,role', 'sourceDevice:id,name', 'revokedBy:id,username'])
            ->orderBy('user_id')
            ->orderBy('finger')
            ->get();

        return view('biometric.index', [
            'devices' => BiometricDevice::query()
                ->withCount(['enrollments' => fn ($query) => $query->whereNull('revoked_at')])
                ->orderBy('name')
                ->get(),
            'activeEnrollments' => $enrollments->whereNull('revoked_at')->values(),
            'revokedEnrollments' => $enrollments->whereNotNull('revoked_at')
                ->sortByDesc('revoked_at')
                ->take(20)
                ->values(),
            // Ditampilkan sekali saja: rahasia perangkat tidak disimpan dalam
            // bentuk terbaca di mana pun setelah halaman ini ditutup.
            'newDeviceSecret' => $request->session()->get('biometric_device_secret'),
            'newDeviceUuid' => $request->session()->get('biometric_device_uuid'),
            'sessionFingerprint' => $this->logger->sessionFingerprint(
                $request->hasSession() ? $request->session()->getId() : null
            ),
        ]);
    }

    /** Buat perangkat baru. Secret ditampilkan sekali, lalu tidak bisa dilihat lagi. */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'host_label' => ['nullable', 'string', 'max:64'],
            'platform' => ['required', 'string', 'in:windows,linux,macos'],
        ]);

        $secret = BiometricDevice::generateSecret();

        $device = BiometricDevice::create([
            'uuid' => (string) Str::uuid(),
            'name' => $data['name'],
            'host_label' => $data['host_label'] ?? null,
            'platform' => $data['platform'],
            'secret' => $secret,
            'active' => true,
        ]);

        $this->logger->log('biometric_device_created', [
            'request' => $request,
            'user' => $request->user(),
            'meta' => [
                'device_uuid' => $device->uuid,
                'device_name' => $device->name,
                'platform' => $device->platform,
            ],
        ]);

        return redirect()
            ->route('biometric.index')
            ->with('status', "Perangkat \"{$device->name}\" dibuat. Salin secret-nya sebelum meninggalkan halaman ini.")
            ->with('biometric_device_secret', $secret)
            ->with('biometric_device_uuid', $device->uuid);
    }

    /** Aktif/nonaktifkan perangkat. Nonaktif = semua request agen ditolak 401. */
    public function toggle(Request $request, BiometricDevice $device): RedirectResponse
    {
        $device->update(['active' => ! $device->active]);

        return back()->with(
            'status',
            $device->active
                ? "Perangkat \"{$device->name}\" diaktifkan."
                : "Perangkat \"{$device->name}\" dinonaktifkan; semua permintaannya akan ditolak.",
        );
    }

    /** Cabut satu pendaftaran jari. Agen tidak akan menerimanya lagi saat sinkronisasi. */
    public function revoke(Request $request, BiometricEnrollment $enrollment): RedirectResponse
    {
        $owner = $enrollment->user;

        $enrollment->revoke($request->user());

        $this->logger->log('biometric_enrollment_revoked', [
            'request' => $request,
            'user' => $owner,
            'auth_method' => 'fingerprint',
            'credential_id' => $enrollment->template_ref,
            'meta' => [
                'finger' => $enrollment->finger,
                'template_ref' => $enrollment->template_ref,
            ],
        ]);

        return back()->with(
            'status',
            "Pendaftaran {$enrollment->finger} untuk \"{$owner?->username}\" dicabut.",
        );
    }
}
