<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Diagnostics;
use App\Services\TestLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly Diagnostics $diagnostics,
        private readonly TestLogger $logger,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        // A "session probe" row lets the tester prove from the log that a return
        // visit reused the existing Laravel session instead of creating a new one.
        $this->logger->log('session_probe', [
            'request' => $request,
            'user' => $user,
            'meta' => [
                'session_fingerprint' => $this->logger->sessionFingerprint($request->session()->getId()),
                'route' => 'dashboard',
            ],
        ]);

        return view('dashboard', [
            'server' => $this->diagnostics->snapshot(),
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'osAccountLabel' => $request->session()->get('os_account_label'),
        ]);
    }
}
