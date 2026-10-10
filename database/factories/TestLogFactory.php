<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TestLog;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestLog>
 */
class TestLogFactory extends Factory
{
    protected $model = TestLog::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'session_probe',
            'auth_method' => null,
            'meta' => null,
        ];
    }
}
