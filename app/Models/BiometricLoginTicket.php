<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Tiket login sekali pakai hasil identifikasi sidik jari.
 *
 * Token mentahnya TIDAK pernah disimpan — hanya sha256-nya — sehingga bocornya
 * isi tabel ini tidak langsung memberi siapa pun cara masuk.
 *
 * Umur tiket sengaja pendek: cukup untuk membukakan browser di PC yang sama,
 * terlalu pendek untuk disalahgunakan bila alamatnya sempat terlihat orang lain.
 */
class BiometricLoginTicket extends Model
{
    /** Umur tiket dalam detik. */
    public const TTL_SECONDS = 60;

    /** @var list<string> */
    protected $fillable = [
        'token_hash',
        'user_id',
        'source_device_id',
        'template_ref',
        'finger',
        'expires_at',
        'consumed_at',
        'consumed_ip',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'source_device_id');
    }

    /**
     * Terbitkan tiket baru untuk satu pengguna.
     *
     * Mengembalikan pasangan [tiket, token mentah]. Token mentah hanya ada di
     * sini; sesudah ini yang tersimpan cuma hash-nya.
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(
        User $user,
        ?BiometricDevice $device,
        ?string $templateRef,
        ?string $finger = null,
    ): array {
        $token = Str::random(64);

        $ticket = self::create([
            'token_hash' => self::hash($token),
            'user_id' => $user->getKey(),
            'source_device_id' => $device?->getKey(),
            'template_ref' => $templateRef,
            'finger' => $finger,
            'expires_at' => now()->addSeconds(self::TTL_SECONDS),
        ]);

        return [$ticket, $token];
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at === null || $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isUsable(): bool
    {
        return ! $this->isConsumed() && ! $this->hasExpired();
    }

    /** Alasan tiket tidak bisa dipakai, untuk catatan audit. */
    public function rejectionReason(): string
    {
        if ($this->isConsumed()) {
            return 'tiket_sudah_dipakai';
        }

        if ($this->hasExpired()) {
            return 'tiket_kedaluwarsa';
        }

        return 'tiket_tidak_dikenal';
    }
}
