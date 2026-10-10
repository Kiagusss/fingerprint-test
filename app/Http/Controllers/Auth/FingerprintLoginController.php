<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\BiometricLoginTicket;
use App\Models\User;
use App\Services\AuthAttempt;
use App\Services\TestLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Satu-satunya tempat di aplikasi ini di mana sidik jari membuat sesi login.
 *
 * Alurnya: agen di PC bersama mencocokkan jari (1:N), meminta TIKET sekali pakai
 * lewat endpoint bertanda tangan perangkat, lalu membukakan alamat tiket itu di
 * browser PC yang sama. Halaman ini menukar tiket menjadi sesi.
 *
 * Ini keputusan pemilik aplikasi (11 Okt 2026): jari saja cukup untuk masuk,
 * seperti Windows Hello. Batas yang menyertainya dijelaskan apa adanya di
 * docs/BIOMETRIC.md §2 — yang paling penting: kepercayaan ikut berpindah ke
 * agen dan ke PC itu, karena agen yang dikuasai orang lain bisa meminta tiket
 * untuk siapa pun. Tiket yang berumur pendek dan sekali pakai membatasi, bukan
 * menghapus, risiko itu.
 *
 * Token TIDAK pernah dicatat di log: ia adalah kredensial. Yang dicatat hanya
 * id tiket dan penunjuk template.
 */
class FingerprintLoginController extends Controller
{
    public function __construct(
        private readonly TestLogger $logger,
        private readonly AuthAttempt $attempt,
    ) {}

    public function consume(Request $request, string $token): RedirectResponse
    {
        $ticket = BiometricLoginTicket::query()
            ->with(['user', 'sourceDevice'])
            ->where('token_hash', BiometricLoginTicket::hash($token))
            ->first();

        if (! $ticket instanceof BiometricLoginTicket) {
            return $this->refuse($request, null, 'tiket_tidak_dikenal');
        }

        if (! $ticket->isUsable()) {
            return $this->refuse($request, $ticket, $ticket->rejectionReason());
        }

        // Sekali pakai: klaim lebih dulu di database. Syarat `consumed_at` masih
        // NULL membuat dua permintaan bersamaan tidak bisa dua-duanya menang.
        $claimed = BiometricLoginTicket::query()
            ->whereKey($ticket->getKey())
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->update([
                'consumed_at' => now(),
                'consumed_ip' => $request->ip(),
            ]);

        if ($claimed === 0) {
            return $this->refuse($request, $ticket, 'tiket_sudah_dipakai');
        }

        $user = $ticket->user;

        if (! $user instanceof User) {
            return $this->refuse($request, $ticket, 'pengguna_tidak_ada');
        }

        // Supaya pendengar Login melabeli ini sebagai sidik jari, bukan password.
        $this->attempt->method = 'fingerprint';
        $this->attempt->credentialId = $ticket->template_ref;

        Auth::guard('web')->login($user);

        // Cegah session fixation, sama seperti login password.
        $request->session()->regenerate();

        $this->logger->log('fingerprint_login_success', [
            'request' => $request,
            'user' => $user,
            'auth_method' => 'fingerprint',
            'role' => $user->role?->value,
            'credential_id' => $ticket->template_ref,
            'meta' => [
                'ticket_id' => $ticket->getKey(),
                'device_uuid' => $ticket->sourceDevice?->uuid,
                'device_name' => $ticket->sourceDevice?->name,
                'finger' => $ticket->finger,
            ],
        ]);

        return redirect()->intended(route('dashboard'));
    }

    /** Tiket tidak berlaku: catat alasannya, lalu kembalikan ke halaman login. */
    private function refuse(Request $request, ?BiometricLoginTicket $ticket, string $reason): RedirectResponse
    {
        $this->logger->log('fingerprint_login_failed', [
            'request' => $request,
            'user' => $ticket?->user,
            'auth_method' => 'fingerprint',
            'credential_id' => $ticket?->template_ref,
            'meta' => [
                'reason' => $reason,
                'ticket_id' => $ticket?->getKey(),
                'device_name' => $ticket?->sourceDevice?->name,
            ],
        ]);

        return redirect()->route('login')->withErrors([
            'fingerprint' => __('Tiket sidik jari tidak berlaku lagi. Tempelkan jari sekali lagi di PC itu.'),
        ]);
    }
}
