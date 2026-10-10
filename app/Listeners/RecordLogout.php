<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Services\TestLogger;
use Illuminate\Auth\Events\Logout;

class RecordLogout
{
    public function __construct(private readonly TestLogger $logger) {}

    public function handle(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $this->logger->log('logout', [
            'user' => $event->user,
            'meta' => ['guard' => $event->guard],
        ]);
    }
}
