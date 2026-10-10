<?php

declare(strict_types=1);

namespace App\Passkeys;

use App\Passkeys\Concerns\HintsClientDevice;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\Passkeys;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Opsi verifikasi (login passwordless + konfirmasi ulang) dengan hint
 * client-device.
 *
 * Sisi ini justru lebih penting daripada sisi registrasi: login aplikasi ini
 * memakai discoverable credential, jadi `allowCredentials` sengaja kosong —
 * dan Chromium selalu menampilkan dialognya sendiri untuk `get()` dengan
 * `allowCredentials` kosong, lengkap dengan "gunakan ponsel, tablet, atau
 * security key". `PublicKeyCredentialRequestOptions` tidak punya
 * `authenticatorAttachment`, sehingga `hints` adalah satu-satunya tuas yang
 * tersedia di sini.
 */
class WindowsHelloVerificationOptions extends GenerateVerificationOptions
{
    use HintsClientDevice;

    /**
     * Opsi verifikasi: isi disalin dari action paket, ditambah hints.
     */
    public function __invoke(?PasskeyUser $user = null): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: Passkeys::relyingPartyId(),
            allowCredentials: $this->allowCredentials($user),
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: Passkeys::timeout(),
            hints: $this->hints(),
        );
    }
}
