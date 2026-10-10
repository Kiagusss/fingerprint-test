<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\BiometricDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Membuktikan bahwa request datang dari agen pemindai yang terdaftar.
 *
 * Kontrak tanda tangan (dipakai agen):
 *
 *   canonical = METHOD \n PATH \n TIMESTAMP \n sha256_hex(raw_body)
 *   signature = hex(hmac_sha256(secret_perangkat, canonical))
 *
 * Header:
 *   X-Device-Id        : uuid perangkat
 *   X-Device-Timestamp : detik unix saat request dibuat
 *   X-Device-Signature : nilai signature di atas
 *
 * Yang diperiksa: perangkat ada dan aktif, selisih timestamp <= 300 detik,
 * dan HMAC cocok (dibandingkan konstan-waktu). Semua kegagalan menjawab 401
 * dengan pesan yang sama, supaya pemanggil tidak bisa memetakan bagian mana
 * yang salah.
 *
 * Batas yang diketahui: jendela 300 detik membuat replay request yang sama
 * masih mungkin di dalam jendela itu. Untuk endpoint pendaftaran dampaknya
 * kecil (idempoten karena satu jari hanya boleh punya satu baris aktif), dan
 * endpoint identifikasi nanti akan memakai challenge per-sesi yang sekali
 * pakai sehingga replay tidak berlaku di sana.
 *
 * Middleware ini TIDAK pernah membuat sesi login. Ia hanya menempelkan
 * perangkat ke request.
 */
class AuthenticateBiometricDevice
{
    /** Toleransi selisih jam klien-server, dalam detik. */
    public const TIMESTAMP_WINDOW = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $device = $this->device($request);

        if (! $device instanceof BiometricDevice || ! $device->isActive()) {
            return $this->reject($request, 'perangkat tidak dikenal atau nonaktif');
        }

        $timestamp = (string) $request->header('X-Device-Timestamp', '');

        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            return $this->reject($request, 'timestamp tidak ada atau tidak valid');
        }

        if (abs(time() - (int) $timestamp) > self::TIMESTAMP_WINDOW) {
            return $this->reject($request, 'timestamp di luar jendela yang diterima');
        }

        $provided = (string) $request->header('X-Device-Signature', '');

        if ($provided === '' || ! hash_equals($this->expected($request, $device, $timestamp), $provided)) {
            return $this->reject($request, 'tanda tangan tidak cocok');
        }

        $device->forceFill([
            'last_seen_at' => now(),
            'last_seen_ip' => $request->ip(),
        ])->saveQuietly();

        $request->attributes->set('biometric_device', $device);

        return $next($request);
    }

    private function device(Request $request): ?BiometricDevice
    {
        $identifier = (string) $request->header('X-Device-Id', '');

        if ($identifier === '') {
            return null;
        }

        return BiometricDevice::query()->where('uuid', $identifier)->first();
    }

    /** Nilai yang seharusnya dikirim agen. */
    private function expected(Request $request, BiometricDevice $device, string $timestamp): string
    {
        $canonical = implode("\n", [
            $request->getMethod(),
            '/'.ltrim($request->path(), '/'),
            $timestamp,
            hash('sha256', $request->getContent()),
        ]);

        return hash_hmac('sha256', $canonical, (string) $device->secret);
    }

    private function reject(Request $request, string $reason): Response
    {
        // Alasan lengkap hanya masuk log; pemanggil menerima satu pesan generik.
        Log::warning('Permintaan perangkat biometrik ditolak', [
            'reason' => $reason,
            'device' => $request->header('X-Device-Id'),
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        return response()->json(['message' => 'Perangkat tidak diizinkan.'], 401);
    }
}
