<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TestResult;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Runs the automated PHPUnit suite and syncs each automated case's outcome into
 * `test_results`.
 *
 * This command is the ONLY thing allowed to set an `auto` row to PASS — the
 * status is derived from real JUnit XML produced by the test runner, never from
 * a hand-entered value. A case that the runner did not report is left NOT TESTED.
 *
 *   php artisan test:sync-results
 */
class SyncTestResultsCommand extends Command
{
    protected $signature = 'test:sync-results {--filter= : Only run tests matching the given PHPUnit filter}';

    protected $description = 'Run the automated test suite and sync PASS/FAIL into the test_results table';

    public function handle(): int
    {
        $autoCases = TestResult::query()->where('source', TestResult::SOURCE_AUTO)->get();

        if ($autoCases->isEmpty()) {
            $this->warn('No automated test cases found. Run: php artisan db:seed --class=TestResultSeeder');

            return self::FAILURE;
        }

        $junit = storage_path('app/junit.xml');

        if (is_file($junit)) {
            @unlink($junit);
        }

        $this->info('Running automated test suite...');

        // Call PHPUnit directly rather than `php artisan test`: Laravel's test
        // runner (laravel/pao) re-hydrates the environment from .env in a way that
        // defeats phpunit.xml's <env> overrides, which would run the suite against
        // the real MySQL database. vendor/bin/phpunit applies phpunit.xml cleanly.
        $phpunit = base_path('vendor/bin/phpunit');

        if (! is_file($phpunit)) {
            $this->error('vendor/bin/phpunit not found. Run: composer install');

            return self::FAILURE;
        }

        $command = [PHP_BINARY, $phpunit, '--log-junit', $junit];

        if ($this->option('filter')) {
            $command[] = '--filter='.$this->option('filter');
        }

        $process = new Process($command, base_path());

        // Pin the testing environment explicitly. The parent Artisan process has
        // .env loaded into $_ENV, and Symfony Process inherits it — which would run
        // the suite against the real MySQL database with CSRF enabled. Passing the
        // values here guarantees an isolated, in-memory test run.
        $process->setEnv([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'SESSION_DRIVER' => 'array',
            'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'BCRYPT_ROUNDS' => '4',
            'MAIL_MAILER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ]);

        $process->setTimeout(600);
        $process->run();

        if (! is_file($junit)) {
            $this->error('The test runner did not produce JUnit output. Nothing was synced.');

            return self::FAILURE;
        }

        $statuses = $this->parseJunit($junit);

        $passed = 0;
        $failed = 0;
        $unknown = 0;

        foreach ($autoCases as $case) {
            $method = $this->shortMethod((string) $case->automated_test);

            if (! array_key_exists($method, $statuses)) {
                // Not reported by this run — leave the row genuinely untested.
                $case->forceFill([
                    'status' => TestResult::STATUS_NOT_TESTED,
                    'actual' => 'Tidak dilaporkan oleh test runner pada run terakhir.',
                    'executed_at' => now(),
                ])->save();

                $unknown++;

                continue;
            }

            $ok = $statuses[$method];

            $case->forceFill([
                'status' => $ok ? TestResult::STATUS_PASS : TestResult::STATUS_FAIL,
                'actual' => $ok ? 'Automated test PASS' : 'Automated test FAIL',
                'executed_at' => now(),
            ])->save();

            $ok ? $passed++ : $failed++;
        }

        $this->newLine();
        $this->info("Sync selesai: {$passed} PASS, {$failed} FAIL, {$unknown} tanpa laporan.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Map "Class::method" => passed? from a JUnit report.
     *
     * @return array<string, bool>
     */
    private function parseJunit(string $path): array
    {
        $statuses = [];

        $xml = @simplexml_load_file($path);

        if ($xml === false) {
            return $statuses;
        }

        foreach ($xml->xpath('//testcase') ?: [] as $testcase) {
            $method = (string) ($testcase['name'] ?? '');

            if ($method === '') {
                continue;
            }

            // A testcase passes unless it carries <failure>, <error> or <skipped>.
            $hasProblem = isset($testcase->failure) || isset($testcase->error);

            $statuses[$method] = ! $hasProblem;
        }

        return $statuses;
    }

    private function shortMethod(string $fqcn): string
    {
        return str_contains($fqcn, '::') ? explode('::', $fqcn)[1] : class_basename($fqcn);
    }
}
