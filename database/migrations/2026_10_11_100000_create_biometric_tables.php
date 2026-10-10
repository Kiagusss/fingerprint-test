<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lapisan sidik jari: perangkat, pendaftaran jari, dan pencocokan di PC.
 *
 * Model keamanannya sengaja dipisah:
 *   - sidik jari menjawab "INI SIAPA" (identifikasi, 1:N) supaya karyawan tidak
 *     perlu mengetik username di PC bersama;
 *   - dan pada awalnya pembuktian identitas TETAP password/passkey milik
 *     karyawan itu sendiri.
 *
 * CATATAN PERUBAHAN (11 Okt 2026): pemilik aplikasi memilih sidik jari sebagai
 * autentikasi penuh, jadi sekarang ada jalur di mana jari saja cukup untuk masuk
 * — lewat tiket sekali pakai (tabel `biometric_login_tickets`). Karena itu tidak
 * ada kolom kunci privat di sini: yang dijaga bukan lagi "tidak pernah membuat
 * sesi", melainkan "agen tidak bisa mengarang identitas". Batas barunya, termasuk
 * risiko yang berpindah ke agen dan ke PC, tertulis di docs/BIOMETRIC.md §2.
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
