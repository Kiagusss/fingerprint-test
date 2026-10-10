<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Diagnostic event log. Read-only from the UI; only the app writes to it.
 *
 * NON-SENSITIVE BY DESIGN. See TestLog::record() for the allow-list of fields.
 *
 * @property int $id
 * @property string $event
 * @property int|null $user_id
 * @property string|null $auth_method
 */
class TestLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'event',
        'user_id',
        'auth_method',
        'role',
        'os_account_label',
        'credential_fingerprint',
        'session_fingerprint',
        'ip_address',
        'user_agent',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
