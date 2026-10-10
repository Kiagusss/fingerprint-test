<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Agen pemindai sidik jari di satu PC bersama.
 *
 * Agen membuktikan dirinya dengan menandatangani setiap request memakai HMAC
 * atas `secret` perangkat (lihat App\Http\Middleware\AuthenticateBiometricDevice).
 * `secret` adalah rahasia bersama, bukan kunci privat: nilai ini hanya
 * membuktikan "request ini datang dari agen yang terdaftar", tidak pernah
 * dipakai untuk membuat sesi login.
 */
#[Hidden(['secret'])]
class BiometricDevice extends Model
{
    protected $fillable = [
        'uuid',
        'name',
        'host_label',
        'platform',
        'secret',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'active' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    /** Rahasia perangkat baru. Ditampilkan sekali saat dibuat, lalu tidak lagi. */
    public static function generateSecret(): string
    {
        return Str::random(64);
    }

    /** @return HasMany<BiometricEnrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(BiometricEnrollment::class, 'source_device_id');
    }

    public function isActive(): bool
    {
        return $this->active === true;
    }
}
