<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\TestResult;
use App\Support\TestCatalog;
use Illuminate\Database\Seeder;

/**
 * Idempotent: creates any catalog case that does not exist yet and refreshes the
 * non-volatile definition (title/expected/automated_test). It never touches the
 * recorded `status`, `actual` or `notes`, so re-running it cannot fake a PASS or
 * wipe a manual observation.
 */
class TestResultSeeder extends Seeder
{
    public function run(): void
    {
        foreach (TestCatalog::cases() as $case) {
            $existing = TestResult::query()->where('case_key', $case['key'])->first();

            if ($existing) {
                $existing->forceFill([
                    'category' => $case['category'],
                    'title' => $case['title'],
                    'source' => $case['source'],
                    'expected' => $case['expected'],
                    'automated_test' => $case['automated_test'],
                ])->save();

                continue;
            }

            TestResult::query()->create([
                'case_key' => $case['key'],
                'category' => $case['category'],
                'title' => $case['title'],
                'source' => $case['source'],
                'expected' => $case['expected'],
                'status' => TestResult::STATUS_NOT_TESTED,
                'automated_test' => $case['automated_test'],
            ]);
        }
    }
}
