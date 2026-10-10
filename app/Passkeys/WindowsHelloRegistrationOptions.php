<?php

declare(strict_types=1);

namespace App\Passkeys;

use App\Passkeys\Concerns\HintsClientDevice;
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use RuntimeException;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * Opsi registrasi yang hanya menerima authenticator bawaan perangkat.
 *
 * `laravel/passkeys` memakai AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE, yang berarti
 * browser bebas memakai authenticator apa pun: Windows Hello (bawaan), ponsel
 * lewat QR lintas perangkat, security key USB, atau passkey yang disinkronkan ke
 * akun Microsoft/Google.
 *
 * Kebijakan "Windows Hello saja" disusun dari DUA lapisan, karena di Chromium
 * satu lapisan tidak cukup:
 *
 *   1. `authenticatorAttachment: platform` + userVerification/residentKey
 *      required — pembatas KERAS. Hanya kredensial dari authenticator platform
 *      yang diterima, dan login tanpa username tetap mungkin.
 *   2. `hints: ['client-device']` — pengarahan UI supaya browser langsung
 *      memakai authenticator di perangkat ini (Windows Hello). Ini yang
 *      menangani dialog pemilih: Chromium menampilkan dialognya sendiri lebih
 *      dulu untuk `residentKey: required`, dengan pilihan "gunakan ponsel" dan
 *      "gunakan security key".
 *
 * Kelas ini menggantikan action paket di container (lihat AppServiceProvider),
 * sehingga SEMUA jalur pendaftaran ikut terpengaruh tanpa menyalin vendor:
 *   - GET  /user/passkeys/options      (kelola kredensial sendiri)
 *   - POST /employees/{id}/options     (pendaftaran karyawan oleh admin)
 *   - POST /bootstrap/options          (admin pertama)
 *
 * Batas yang perlu diketahui
 * --------------------------
 * `platform` hanya membatasi JENIS authenticator, bukan jaminan kriptografis
 * bahwa kredensial tidak disinkronkan. Spesifikasi WebAuthn tidak menyediakan
 * cara memaksa kredensial device-bound: provider passkey pihak ketiga (Google
 * Password Manager, 1Password, dst.) juga mengaku sebagai platform authenticator
 * sehingga browser dapat menawarkannya. Jaminan yang benar-benar ketat menuntut
 * attestation "direct" + daftar-putih AAGUID Windows Hello, sementara paket ini
 * mengunci attestation ke "none" (lihat StorePasskey). Karena itu kelas ini
 * adalah pembatas kebijakan, bukan kontrol keamanan.
 *
 * `hints` juga bersifat saran, dan di Windows dialognya milik OS — lihat
 * App\Passkeys\Concerns\HintsClientDevice.
 */
class WindowsHelloRegistrationOptions extends GenerateRegistrationOptions
{
    use HintsClientDevice;

    /**
     * Opsi registrasi lengkap: selection criteria milik aplikasi + hints
     * client-device. Sisanya disalin apa adanya dari action paket.
     */
    public function __invoke(Authenticatable $user): PublicKeyCredentialCreationOptions
    {
        if (! $user instanceof PasskeyUser) {
            throw new RuntimeException('User model must implement the PasskeyUser contract.');
        }

        return PublicKeyCredentialCreationOptions::create(
            rp: $this->relyingParty(),
            user: $this->userEntity($user),
            challenge: random_bytes(32),
            pubKeyCredParams: $this->supportedAlgorithms(),
            authenticatorSelection: $this->authenticatorSelection(),
            // Sama seperti paket: rantai sertifikat perangkat tidak diverifikasi,
            // sehingga AAGUID tidak dapat dijadikan bukti identitas perangkat.
            attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            excludeCredentials: $this->excludedCredentials($user),
            timeout: Passkeys::timeout(),
            hints: $this->hints(),
        );
    }

    /**
     * Authenticator yang boleh dipakai saat mendaftar.
     *
     * Nilai diambil dari config supaya kebijakan dapat dilonggarkan tanpa
     * mengubah kode (mis. saat admin harus mendaftarkan dari security key USB).
     * Default: `platform` — hanya authenticator bawaan, yaitu Windows Hello.
     */
    public function authenticatorSelection(): AuthenticatorSelectionCriteria
    {
        return AuthenticatorSelectionCriteria::create(
            authenticatorAttachment: $this->authenticatorAttachment(),
            // Biometrik atau PIN, bukan sekadar kehadiran (tap).
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            // Discoverable credential: login tanpa mengetik username.
            residentKey: AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED,
        );
    }

    /**
     * Terjemahkan nilai config ke konstanta WebAuthn.
     */
    protected function authenticatorAttachment(): ?string
    {
        return match ((string) config('passkeys.authenticator_attachment', 'platform')) {
            'cross-platform' => AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_CROSS_PLATFORM,
            'no-preference' => AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_NO_PREFERENCE,
            default => AuthenticatorSelectionCriteria::AUTHENTICATOR_ATTACHMENT_PLATFORM,
        };
    }
}
