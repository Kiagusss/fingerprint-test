<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan identifikasi sidik jari (bukan autentikasi).
 *
 * Model keamanannya sengaja dipisah:
 *   - sidik jari menjawab "INI SIAPA" (identifikasi, 1:N) supaya karyawan tidak
 *     perlu mengetik username di PC bersama;
 *   - pembuktian identitas TETAP password/passkey milik karyawan itu sendiri.
 *
 * Karena itu tidak ada kolom kunci privat di sini: server tidak pernah
 * mempercayai lapisan ini sebagai bukti identitas, sehingga tidak perlu ada
 * rahasia yang bisa dipakai untuk memalsukan login. Lihat docs/BIOMETRIC.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Perangkat pemindai. Satu baris = satu agen di satu PC bersama.
         * `secret` disimpan terenkripsi (cast `encrypted`, memakai APP_KEY)
         * karena dipakai untuk memverifikasi tanda tangan HMAC request;
         * hash satu arah tidak bisa dipakai untuk itu.
         */
        Schema::create('biometric_devices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('host_label')->nullable();
            $table->string('platform')->nullable(); // windows | linux | macos
            $table->text('secret');
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_seen_ip', 45)->nullable();
            $table->timestamps();
        });

        /*
         * Pendaftaran satu jari milik satu user.
         *
         * `template` boleh kosong: pemindai Chipsailing CS9711 adalah perangkat
         * match-on-host, jadi secara bawaan template hanya ada di PC tempat jari
         * itu didaftarkan (`template_ref` menunjuk template lokal itu). Bila
         * `template` diisi, agen mana pun dapat menariknya dan mencocokkan
         * secara lokal — supaya pendaftaran cukup sekali per karyawan, bukan
         * sekali per karyawan x jumlah PC bersama.
         *
         * Data biometrik = data pribadi bersifat spesifik (UU PDP 27/2022),
         * karena itu `consent_at` wajib diisi sebelum baris dibuat.
         */
        Schema::create('biometric_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('finger'); // right-index-finger, left-thumb, ...
            $table->string('template_ref'); // id template lokal di agen
            $table->text('template')->nullable(); // opsional, terenkripsi
            $table->string('template_hash', 64); // sha256 hex atas template
            $table->unsignedTinyInteger('quality')->default(0); // 0-100
            $table->foreignId('source_device_id')->nullable()
                ->constrained('biometric_devices')->nullOnDelete();
            $table->foreignId('enrolled_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('consent_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'finger']);
            $table->index('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_enrollments');
        Schema::dropIfExists('biometric_devices');
    }
};
