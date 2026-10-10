<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Passwordless employee enrollment.
 *
 * Karyawan masuk dengan sidik jari (Windows Hello) dan tidak pernah menerima
 * password, sehingga `users.password` dan `users.email` menjadi NULL-able.
 * Nilai `users.role` bertambah satu nilai: `employee`.
 *
 * Catatan: bukan kolom `last_auth_method` yang diubah — kolom ini sudah
 * NULL-able sejak awal, jadi akun tanpa password tetap aman disimpan.
 *
 * Terisolasi di database aplikasi ini (`login_heloklin` di produksi). Jangan
 * dijalankan ke schema aplikasi lain di server yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Kolom tidak bisa dikembalikan ke NOT NULL selama masih ada baris
        // passwordless. Ini PoC: akun tanpa password/email dihapus agar rollback
        // schema tetap deterministik.
        DB::table('users')
            ->whereNull('password')
            ->orWhereNull('email')
            ->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
