<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Diagnostic event log for the Windows Hello / passkey PoC.
 *
 * Stores ONLY non-sensitive metadata. It must never contain raw biometric data,
 * private keys, raw challenges, raw cookies or raw session identifiers.
 * `session_fingerprint` is an HMAC/hash prefix, not the session id itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('test_logs', function (Blueprint $table) {
            $table->id();
            $table->string('event');                                   // login_success | login_failed | passkey_registered | ...
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('auth_method')->nullable();                 // password | passkey
            $table->string('role')->nullable();
            $table->string('os_account_label')->nullable();            // label typed by the tester (NOT a Windows identity proof)
            $table->string('credential_fingerprint')->nullable();      // sha256 prefix of the credential id, non-reversible
            $table->string('session_fingerprint')->nullable();         // sha256 prefix of the session id, non-reversible
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('test_logs');
    }
};
