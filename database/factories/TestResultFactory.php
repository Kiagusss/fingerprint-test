<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TestResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestResult>
 */
class TestResultFactory extends Factory
{
    protected $model = TestResult::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'case_key' => fake()->unique()->slug(3),
            'category' => 'webauthn',
            'title' => fake()->sentence(),
            'source' => TestResult::SOURCE_MANUAL,
            'expected' => fake()->sentence(),
            'status' => TestResult::STATUS_NOT_TESTED,
        ];
    }
}
