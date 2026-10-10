<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satu jari milik satu user.
 *
 * `template` kosong artinya template hanya hidup di PC tempat pendaftaran
 * dilakukan (perangkat match-on-host seperti Chipsailing CS9711). Bila diisi,
 * agen lain boleh menariknya supaya pendaftaran cukup sekali per karyawan.
 *
 * Model ini TIDAK menyimpan kunci privat apa pun dan tidak pernah dipakai untuk
 * membuat sesi. Ia hanya menjawab "jari ini milik siapa".
 */
#[Hidden(['template'])]
class BiometricEnrollment extends Model
{
    protected $fillable = [
        'user_id',
        'finger',
        'template_ref',
        'template',
        'template_hash',
        'quality',
        'source_device_id',
        'enrolled_by',
        'consent_at',
        'revoked_at',
        'revoked_by',
    ];

    protected function casts(): array
    {
        return [
            'template' => 'encrypted',
            'quality' => 'integer',
            'consent_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<BiometricDevice, $this> */
    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'source_device_id');
    }

    /** @return BelongsTo<User, $this> */
    public function enrolledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enrolled_by');
    }

    /** @return BelongsTo<User, $this> */
    public function revokedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    /** @param Builder<BiometricEnrollment> $query */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('revoked_at');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }

    public function revoke(?User $actor = null): void
    {
        $this->forceFill([
            'revoked_at' => now(),
            'revoked_by' => $actor?->getKey(),
        ])->save();
    }
}
