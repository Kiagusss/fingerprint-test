<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\AuthAttempt;
use App\Services\TestLogger;
use Illuminate\Auth\Events\Login;

/**
 * Fires for every successful guard login — including the passkey flow, because
 * the passkey controller calls the same session guard. AuthAttempt tells us
 * which ceremony it was.
 */
class RecordSuccessfulLogin
{
    public function __construct(
        private readonly TestLogger $logger,
        private readonly AuthAttempt $attempt,
    ) {}

    public function handle(Login $event): void
    {
        $method = $this->attempt->method ?? 'password';

        $event->user->recordAuthentication($method);

        $this->logger->log('login_success', [
            'user' => $event->user,
            'auth_method' => $method,
            'credential_id' => $this->attempt->credentialId,
            'meta' => [
                'guard' => $event->guard,
                'remember' => $event->remember,
            ],
        ]);

        $this->attempt->reset();
    }
}
