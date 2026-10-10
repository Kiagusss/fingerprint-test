<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\TestLogger;
use Illuminate\Auth\Events\Failed;

class RecordFailedLogin
{
    public function __construct(private readonly TestLogger $logger) {}

    public function handle(Failed $event): void
    {
        // Never log the submitted credentials — only that an attempt failed.
        $this->logger->log('login_failed', [
            'user' => $event->user,
            'meta' => [
                'guard' => $event->guard,
                'known_user' => $event->user !== null,
            ],
        ]);
    }
}
