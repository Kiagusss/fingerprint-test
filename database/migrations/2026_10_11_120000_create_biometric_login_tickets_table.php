<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tiket login sekali pakai dari identifikasi sidik jari.
 *
 * KEPUTUSAN PEMILIK APLIKASI (11 Okt 2026): sidik jari dipakai sebagai
 * AUTENTIKASI, bukan lagi hanya identifikasi — cukup satu langkah, seperti
 * Windows Hello. Konsekuensinya harus disadari dan tertulis di
 * docs/BIOMETRIC.md §2: siapa pun yang bisa menjalankan agen di PC itu bisa
 * memalsukan hasil identifikasi dan masuk sebagai pengguna mana pun, dan
 * sensornya belum punya deteksi liveness.
 *
 * Alurnya: agen (sesudah 1:N cocok) meminta tiket lewat endpoint bertanda tangan
 * perangkat, lalu membukakan alamatnya di browser PC yang sama. Halaman itu
 * menukar tiket menjadi sesi. Tiket:
 *   - menyimpan HASH token (sha256) saja, jadi bocornya isi tabel tidak langsung
 *     memberi kredensial;
 *   - sekali pakai (`consumed_at`);
 *   - berumur pendek (`expires_at`, 60 detik);
 *   - menunjuk perangkat asal dan jari yang cocok, supaya jejaknya bisa diaudit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('biometric_login_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_device_id')->nullable()
                ->constrained('biometric_devices')->nullOnDelete();
            $table->string('template_ref')->nullable();
            $table->string('finger')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->string('consumed_ip', 45)->nullable();
            $table->timestamps();

            $table->index('expires_at');
            $table->index('consumed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('biometric_login_tickets');
    }
};
