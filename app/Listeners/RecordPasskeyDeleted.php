<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\TestLogger;
use Laravel\Passkeys\Events\PasskeyDeleted;

class RecordPasskeyDeleted
{
    public function __construct(private readonly TestLogger $logger) {}

    public function handle(PasskeyDeleted $event): void
    {
        $this->logger->log('passkey_deleted', [
            'user' => $event->user,
            'credential_id' => $event->passkey->credential_id,
            'meta' => ['passkey_name' => $event->passkey->name],
        ]);
    }
}
