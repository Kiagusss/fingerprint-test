<?php

declare(strict_types=1);

namespace App\Passkeys\Concerns;

/**
 * Hint "client-device" untuk permintaan WebAuthn.
 *
 * Lapisan kedua kebijakan Windows Hello. `authenticatorAttachment: platform`
 * adalah pembatas KERAS (server menolak kredensial di luar platform), tetapi ia
 * tidak mengubah tampilan: Chromium sengaja menampilkan dialognya sendiri lebih
 * dulu untuk permintaan `residentKey: required` (registrasi) dan untuk `get()`
 * yang `allowCredentials`-nya kosong (login passwordless) — dan dialog itu
 * menawarkan "gunakan ponsel" serta "gunakan security key" di samping Windows
 * Hello.
 *
 * `hints` (WebAuthn L3, Chrome/Edge 129+) adalah satu-satunya cara yang
 * disediakan platform web untuk mengarahkan UI ke authenticator di perangkat
 * ini saja. `client-device` berarti "authenticator platform di perangkat klien",
 * yaitu Windows Hello, Touch ID, atau sejenisnya.
 *
 * Batas yang jujur
 * ----------------
 * `hints` bersifat SARAN, bukan pembatas. Catatan resmi Chrome:
 *   - "In Chrome, authenticatorAttachment is still respected for now" — bila
 *     `authenticatorAttachment` diisi, Chrome memakai nilai itu dan mengabaikan
 *     hints.
 *   - "These hints may not be respected on platforms such as Windows where the
 *     UI is not controlled by Chrome" — dialog passkey di Windows milik OS,
 *     bukan browser.
 *
 * Jadi hints tidak dijamin menghilangkan pilihan ponsel/security key pada dialog
 * Windows. Bila dialog Windows Security tetap hanya menawarkan ponsel dan
 * security key, penyebabnya ada di sisi OS: Windows Hello belum dikonfigurasi
 * (minimal PIN), akses passkey untuk browser ditolak, atau ada penyedia passkey
 * lain yang aktif. Lihat PRODUCTION.md.
 */
trait HintsClientDevice
{
    /**
     * Daftar hint yang dikirim ke browser, berurutan sesuai prioritas.
     *
     * @return string[]
     */
    protected function hints(): array
    {
        $configured = (string) config('passkeys.hints', 'client-device');

        return array_values(array_filter(array_map('trim', explode(',', $configured))));
    }
}
