<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Request-scoped carrier that tells the generic Auth "Login" listener which
 * ceremony actually authenticated the user. It is set by the passkey listeners
 * (which run before the guard logs the user in) and consumed once by
 * RecordSuccessfulLogin. Prevents a passkey login from being mislabelled as a
 * password login in the diagnostics log.
 */
class AuthAttempt
{
    public ?string $method = null;

    public ?string $credentialId = null;

    public function markPasskey(?string $credentialId = null): void
    {
        $this->method = 'passkey';
        $this->credentialId = $credentialId;
    }

    public function reset(): void
    {
        $this->method = null;
        $this->credentialId = null;
    }
}
