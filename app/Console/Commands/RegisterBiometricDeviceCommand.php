<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\BiometricDevice;
use App\Services\TestLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Mendaftarkan satu agen pemindai sidik jari dan menampilkan secret-nya sekali.
 *
 *   php artisan biometric:register-device "PC Resepsionis" --host=RESEPSI-01
 *
 * Secret ditampilkan SEKALI di sini (dan sekali lagi di halaman admin setelah
 * dibuat dari sana). Server menyimpannya terenkripsi; setelah perintah ini
 * selesai nilainya tidak dapat ditampilkan kembali.
 */
class RegisterBiometricDeviceCommand extends Command
{
    protected $signature = 'biometric:register-device
                            {name : Nama perangkat, mis. "PC Resepsionis"}
                            {--host= : Label host agen}
                            {--platform=windows : windows|linux|macos}
                            {--secret-out= : Tulis secret ke berkas ini (mode 0600) alih-alih mencetaknya}';

    protected $description = 'Daftarkan agen pemindai sidik jari di PC bersama dan tampilkan secret-nya sekali';

    public function handle(TestLogger $logger): int
    {
        $platform = (string) $this->option('platform');

        if (! in_array($platform, ['windows', 'linux', 'macos'], true)) {
            $this->error('Platform harus salah satu dari: windows, linux, macos.');

            return self::FAILURE;
        }

        $secretOut = $this->option('secret-out');

        if ($secretOut !== null && (string) $secretOut === '') {
            $this->error('--secret-out memerlukan path berkas.');

            return self::FAILURE;
        }

        $secret = BiometricDevice::generateSecret();

        $device = BiometricDevice::create([
            'uuid' => (string) Str::uuid(),
            'name' => (string) $this->argument('name'),
            'host_label' => $this->option('host') !== null ? (string) $this->option('host') : null,
            'platform' => $platform,
            'secret' => $secret,
            'active' => true,
        ]);

        $logger->log('biometric_device_created', [
            'user' => null,
            'meta' => [
                'device_uuid' => $device->uuid,
                'device_name' => $device->name,
                'platform' => $device->platform,
                'source' => 'artisan',
                'secret_written_to_file' => $secretOut !== null,
            ],
        ]);

        $written = $secretOut !== null ? $this->writeSecret((string) $secretOut, $device->uuid, $secret) : false;

        if ($secretOut !== null && ! $written) {
            $this->error("Gagal menulis secret ke {$secretOut}. Perangkat tetap dibuat; salin secret di bawah lalu hapus perangkat ini dan buat ulang.");

            return self::FAILURE;
        }

        $this->info("Perangkat \"{$device->name}\" terdaftar.");
        $this->newLine();
        $this->line('  Base URL : '.config('app.url').'/api/biometric');
        $this->line('  UUID     : '.$device->uuid);

        if ($written) {
            $this->line('  Secret   : ditulis ke '.$secretOut.' (mode 0600)');
        } else {
            $this->line('  Secret   : '.$secret);
        }

        $this->newLine();

        if ($written) {
            $this->info('Secret TIDAK dicetak. Berkasnya sudah 0600 — pindahkan ke PC agen lewat jalur yang aman, jangan lewat chat atau email.');
        } else {
            $this->warn('Salin secret sekarang. Server tidak menyimpannya dalam bentuk terbaca.');
        }

        $this->line('Verifikasi kredensial perangkat dengan:');
        $this->line("  php artisan biometric:probe-device {$device->uuid} --secret-file=<berkas>");
        $this->line('Agen menandatangani setiap permintaan dengan:');
        $this->line('  hex(hmac_sha256(secret, METHOD \n PATH \n TIMESTAMP \n sha256_hex(raw_body)))');
        $this->line('Header: X-Device-Id, X-Device-Timestamp, X-Device-Signature. Jendela jam: 300 detik.');

        return self::SUCCESS;
    }

    /**
     * Tulis secret ke berkas 0600. Lebih baik daripada mencetaknya: secret tidak
     * tertinggal di riwayat shell, log CI, atau salinan layar.
     */
    private function writeSecret(string $path, string $uuid, string $secret): bool
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            return false;
        }

        $payload = "# Perangkat biometrik login.heloklin.com\n".
            "BIOMETRIC_DEVICE_UUID={$uuid}\n".
            "BIOMETRIC_DEVICE_SECRET={$secret}\n";

        if (@file_put_contents($path, $payload) === false) {
            return false;
        }

        @chmod($path, 0600);

        return true;
    }
}
