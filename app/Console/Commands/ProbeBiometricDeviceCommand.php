<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Klien uji untuk endpoint agen: memastikan kredensial perangkat benar-benar
 * diterima server, dan (opsional) mencoba satu siklus pendaftaran.
 *
 * PENTING: perintah ini TIDAK membaca sidik jari. Ia tidak menyentuh sensor dan
 * tidak dapat mendaftarkan jari siapa pun. Gunanya dua:
 *   1. memverifikasi kredensial agen + kesamaan jam sebelum agen asli dipasang;
 *   2. menjadi referensi tanda tangan HMAC yang harus ditiru agen.
 *
 *   php artisan biometric:probe-device <uuid> --secret-file=<berkas>
 *   php artisan biometric:probe-device <uuid> --secret-file=<berkas> --enroll=bud
 */
class ProbeBiometricDeviceCommand extends Command
{
    protected $signature = 'biometric:probe-device
                            {uuid : UUID perangkat}
                            {--secret-file= : Berkas 0600 berisi BIOMETRIC_DEVICE_SECRET=...}
                            {--secret= : Secret langsung (hindari; terlihat di riwayat shell)}
                            {--base-url= : Default: config app.url}
                            {--enroll= : Username yang akan dicoba didaftarkan (opsional)}
                            {--finger=right-index-finger}
                            {--quality=72}
                            {--replace : Ganti pendaftaran yang sudah ada untuk jari itu}';

    protected $description = 'Cek kredensial agen ke server (heartbeat, opsional satu pendaftaran). Tidak membaca sidik jari.';

    public function handle(): int
    {
        $uuid = (string) $this->argument('uuid');
        $secret = $this->resolveSecret();

        if ($secret === null) {
            return self::FAILURE;
        }

        $base = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');

        $this->line("Base URL : {$base}");

        // 1. Heartbeat — membuktikan perangkat terdaftar dan jam klien sinkron.
        $response = $this->signedRequest('GET', $base.'/api/biometric/heartbeat', $uuid, $secret, '');
        $body = $this->decode($response->body());

        if ($response->status() !== 200) {
            $this->error("Heartbeat gagal (HTTP {$response->status()}). ".($body['message'] ?? ''));

            return self::FAILURE;
        }

        $this->info('Heartbeat OK.');
        $this->line('  Perangkat   : '.($body['device']['name'] ?? '?').' ('.($body['device']['platform'] ?? '?').')');
        $this->line('  Server time : '.($body['server_time'] ?? '?'));

        // 2. Opsional: satu siklus pendaftaran.
        $username = $this->option('enroll');

        if ($username !== null && (string) $username !== '') {
            $finger = (string) $this->option('finger');
            $templateRef = 'probe-'.$username.'-'.$finger;

            $payload = json_encode([
                'username' => (string) $username,
                'finger' => $finger,
                'template_ref' => $templateRef,
                'quality' => (int) $this->option('quality'),
                'consent' => true,
                'replace' => (bool) $this->option('replace'),
            ], JSON_THROW_ON_ERROR);

            $enroll = $this->signedRequest('POST', $base.'/api/biometric/enrollments', $uuid, $secret, $payload);
            $enrollBody = $this->decode($enroll->body());

            if ($enroll->status() !== 201) {
                $this->error("Pendaftaran gagal (HTTP {$enroll->status()}).");
                $this->line('  '.json_encode($enrollBody, JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->info('Pendaftaran tercatat.');
            $this->line('  id          : '.($enrollBody['enrollment']['id'] ?? '?'));
            $this->line('  user        : '.($enrollBody['enrollment']['username'] ?? '?'));
            $this->line('  jari        : '.($enrollBody['enrollment']['finger'] ?? '?'));
            $this->line('  template_ref: '.($enrollBody['enrollment']['template_ref'] ?? '?'));
            $this->warn('  Ini template_ref karangan: TIDAK ada sidik jari yang dibaca. Ganti dengan `--replace` saat jari sungguhan didaftarkan.');
        }

        // 3. Apa yang akan diterima agen pada sinkronisasi.
        $list = $this->signedRequest('GET', $base.'/api/biometric/enrollments', $uuid, $secret, '');
        $listBody = $this->decode($list->body());

        if ($list->status() !== 200) {
            $this->error("Gagal membaca daftar (HTTP {$list->status()}).");

            return self::FAILURE;
        }

        $rows = $listBody['enrollments'] ?? [];

        $this->info('Daftar pendaftaran yang akan diterima agen: '.count($rows));

        foreach (array_slice($rows, 0, 10) as $row) {
            $this->line(sprintf(
                '  %-14s %-20s mutu %3d  %s%s',
                (string) ($row['username'] ?? '?'),
                (string) ($row['finger'] ?? '?'),
                (int) ($row['quality'] ?? 0),
                (string) ($row['template_ref'] ?? '?'),
                ($row['is_local'] ?? false) ? '  [lokal]' : '',
            ));
        }

        if (count($rows) > 10) {
            $this->line('  ... '.(count($rows) - 10).' lagi');
        }

        return self::SUCCESS;
    }

    /** Secret dari berkas 0600 (disarankan) atau dari opsi. */
    private function resolveSecret(): ?string
    {
        $path = $this->option('secret-file');

        if ($path !== null && (string) $path !== '') {
            if (! is_readable((string) $path)) {
                $this->error("Berkas secret tidak bisa dibaca: {$path}");

                return null;
            }

            $contents = (string) file_get_contents((string) $path);

            if (preg_match('/^BIOMETRIC_DEVICE_SECRET=(.+)$/m', $contents, $matches) === 1) {
                return trim($matches[1]);
            }

            // Berkas yang hanya berisi secret mentah juga diterima.
            $raw = trim($contents);

            if ($raw !== '' && ! str_contains($raw, '=')) {
                return $raw;
            }

            $this->error('Tidak menemukan BIOMETRIC_DEVICE_SECRET di berkas itu.');

            return null;
        }

        $secret = (string) $this->option('secret');

        if ($secret === '') {
            $this->error('Sertakan --secret-file=<berkas>, atau --secret=... (kurang aman).');

            return null;
        }

        return $secret;
    }

    /** Request bertanda tangan — inilah kontrak yang harus ditiru agen. */
    private function signedRequest(string $method, string $url, string $uuid, string $secret, string $body)
    {
        $path = '/'.ltrim((string) parse_url($url, PHP_URL_PATH), '/');
        $timestamp = (string) time();

        $canonical = implode("\n", [$method, $path, $timestamp, hash('sha256', $body)]);

        return $this->pending()
            ->withHeaders([
                'X-Device-Id' => $uuid,
                'X-Device-Timestamp' => $timestamp,
                'X-Device-Signature' => hash_hmac('sha256', $canonical, $secret),
            ])
            ->withBody($body, 'application/json')
            ->send($method, $url);
    }

    private function pending(): PendingRequest
    {
        return Http::timeout(15)->acceptJson();
    }

    /** @return array<string, mixed> */
    private function decode(string $body): array
    {
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }
}
