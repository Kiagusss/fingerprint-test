<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single WebAuthn / session test case rendered on the "Hasil Pengujian" page.
 *
 * A row may only reach `pass` when it was actually executed. Rows whose status
 * is `not_tested` explicitly mean "never run" — they must not be reported as PASS.
 */
class TestResult extends Model
{
    use HasFactory;

    public const STATUS_PASS = 'pass';

    public const STATUS_FAIL = 'fail';

    public const STATUS_NOT_TESTED = 'not_tested';

    public const SOURCE_AUTO = 'auto';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'case_key',
        'category',
        'title',
        'source',
        'expected',
        'actual',
        'status',
        'notes',
        'automated_test',
        'updated_by',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'executed_at' => 'datetime',
        ];
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PASS => 'PASS',
            self::STATUS_FAIL => 'FAIL',
            default => 'NOT TESTED',
        };
    }
}
