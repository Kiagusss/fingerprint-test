<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\TestResult;
use App\Services\TestLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Hasil Pengujian" page.
 *
 * Auto rows are owned by the automated suite (see TestResultsSyncCommand) and are
 * read-only here. Manual rows are recorded by a human tester and carry who set
 * them and when. A manual row may only be PASS/FAIL after it was actually run —
 * the form never offers a bare "mark as passed".
 */
class TestResultsController extends Controller
{
    public function __construct(private readonly TestLogger $logger) {}

    public function index(Request $request): View
    {
        $results = TestResult::query()
            // Failures first, then untested, then passes. CASE WHEN is portable
            // across MySQL and SQLite (unlike MySQL's FIELD()).
            ->orderByRaw("CASE status WHEN 'fail' THEN 1 WHEN 'not_tested' THEN 2 WHEN 'pass' THEN 3 ELSE 4 END")
            ->orderBy('category')
            ->orderBy('id')
            ->get();

        $summary = [
            'pass' => $results->where('status', TestResult::STATUS_PASS)->count(),
            'fail' => $results->where('status', TestResult::STATUS_FAIL)->count(),
            'not_tested' => $results->where('status', TestResult::STATUS_NOT_TESTED)->count(),
            'total' => $results->count(),
        ];

        return view('test-results', [
            'results' => $results->groupBy('category'),
            'summary' => $summary,
            'categories' => TestResult::query()->distinct()->orderBy('category')->pluck('category'),
        ]);
    }

    public function update(Request $request, TestResult $testResult): RedirectResponse
    {
        abort_if($testResult->source !== TestResult::SOURCE_MANUAL, 403,
            'Baris hasil otomatis hanya dapat diperbarui oleh test suite.');

        $data = $request->validate([
            'status' => ['required', 'in:pass,fail,not_tested'],
            'actual' => ['nullable', 'string', 'max:2000'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        // A PASS/FAIL requires a recorded observation; not_tested clears it.
        if ($data['status'] !== TestResult::STATUS_NOT_TESTED) {
            $request->validate([
                'actual' => ['required', 'string', 'max:2000'],
            ]);
        }

        $testResult->forceFill([
            'status' => $data['status'],
            'actual' => $data['actual'] ?? null,
            'notes' => $data['notes'] ?? null,
            'updated_by' => $request->user()->getKey(),
            'executed_at' => $data['status'] === TestResult::STATUS_NOT_TESTED ? null : now(),
        ])->save();

        $this->logger->log('test_result_updated', [
            'request' => $request,
            'user' => $request->user(),
            'meta' => [
                'case_key' => $testResult->case_key,
                'status' => $testResult->status,
            ],
        ]);

        return redirect()->route('test-results.index')->with('status', 'Hasil pengujian disimpan.');
    }
}
