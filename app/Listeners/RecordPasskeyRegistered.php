<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\AuthAttempt;
use App\Services\TestLogger;
use Laravel\Passkeys\Events\PasskeyRegistered;

class RecordPasskeyRegistered
{
    public function __construct(
        private readonly TestLogger $logger,
        private readonly AuthAttempt $attempt,
    ) {}

    public function handle(PasskeyRegistered $event): void
    {
        $this->attempt->markPasskey($event->passkey->credential_id);

        $this->logger->log('passkey_registered', [
            'user' => $event->user,
            'auth_method' => 'passkey',
            'credential_id' => $event->passkey->credential_id,
            'meta' => [
                'passkey_name' => $event->passkey->name,
                // Non-sensitive hint at the authenticator class (e.g. Windows Hello),
                // derived from the AAGUID — never the biometric data itself.
                'authenticator' => $event->passkey->authenticator,
            ],
        ]);
    }
}
