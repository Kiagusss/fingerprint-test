<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backing store for the "Hasil Pengujian" page.
 *
 * Each row is a test case. `source` distinguishes results produced by the
 * automated suite (auto) from results a human recorded (manual) and from cases
 * that were never executed (status = not_tested). A case is only PASS when it
 * was actually executed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_results', function (Blueprint $table) {
            $table->id();
            $table->string('case_key')->unique();
            $table->string('category');                 // webauthn | session | authorization | recovery
            $table->string('title');
            $table->string('source');                   // auto | manual
            $table->text('expected');
            $table->text('actual')->nullable();
            $table->string('status')->default('not_tested'); // pass | fail | not_tested
            $table->text('notes')->nullable();
            $table->string('automated_test')->nullable();    // PHPUnit method name for automated rows
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();

            $table->index(['category', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_results');
    }
};
