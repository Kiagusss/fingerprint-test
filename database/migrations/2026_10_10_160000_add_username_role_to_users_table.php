<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the PoC-specific columns to the stock `users` table:
 *  - username          : separate from `name`, used as the WebAuthn account identifier.
 *  - role              : application-level role (admin | supervisor), enforced server-side.
 *  - last_authenticated_at / last_auth_method : diagnostics for the test dashboard.
 *
 * Isolated to the `hello_test` database. Never run against the production schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('name');
            $table->string('role')->default('user')->after('username');
            $table->timestamp('last_authenticated_at')->nullable()->after('email_verified_at');
            $table->string('last_auth_method')->nullable()->after('last_authenticated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn(['username', 'role', 'last_authenticated_at', 'last_auth_method']);
        });
    }
};
