<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\AuthAttempt;
use App\Services\TestLogger;
use Illuminate\Support\Facades\Auth;
use Laravel\Passkeys\Events\PasskeyVerified;

/**
 * Fires when a passkey assertion verifies. This happens in two different
 * ceremonies:
 *   - passwordless login  : dispatched BEFORE the guard logs the user in
 *                           (Auth::check() is still false)
 *   - confirmation/re-auth: dispatched while the user is already authenticated
 *
 * We only label the former as a login. Either way the request-scoped AuthAttempt
 * is flagged so RecordSuccessfulLogin attributes the login to the passkey
 * ceremony rather than to a password.
 */
class RecordPasskeyVerified
{
    public function __construct(
        private readonly TestLogger $logger,
        private readonly AuthAttempt $attempt,
    ) {}

    public function handle(PasskeyVerified $event): void
    {
        $alreadyAuthenticated = Auth::check();

        $this->attempt->markPasskey($event->passkey->credential_id);

        if ($alreadyAuthenticated) {
            return;
        }

        $this->logger->log('passkey_login_success', [
            'user' => $event->user,
            'auth_method' => 'passkey',
            'credential_id' => $event->passkey->credential_id,
            'meta' => ['authenticator' => $event->passkey->authenticator],
        ]);
    }
}
