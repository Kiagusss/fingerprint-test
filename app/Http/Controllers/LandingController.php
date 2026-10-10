<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Diagnostics;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Diagnostic landing page. Deliberately public so the tester can inspect the
 * environment (HTTPS, WebAuthn support, session state) before logging in.
 *
 * Client-side capabilities (WebAuthn availability, platform authenticator
 * presence) are filled in by resources/js/passkey.js.
 */
class LandingController extends Controller
{
    public function __construct(private readonly Diagnostics $diagnostics) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('landing', [
            'server' => $user ? $this->diagnostics->snapshot() : $this->diagnostics->publicSnapshot(),
            'user' => $user,
            // The landing page shows the passkey count for the signed-in account.
            'passkeys' => $user?->passkeys()->latest()->get() ?? collect(),
            'osAccountLabel' => $request->session()->get('os_account_label'),
        ]);
    }
}
