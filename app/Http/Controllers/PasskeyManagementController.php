<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TestLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Passkey management page: lists the authenticated user's passkeys and renders
 * the "add passkey" control. Creating and deleting credentials happens through
 * laravel/passkeys' own endpoints (POST /user/passkeys, DELETE /user/passkeys/{id}),
 * both of which sit behind Laravel's password.confirm middleware so a Windows
 * Hello / password confirmation is required before the credential set changes.
 */
class PasskeyManagementController extends Controller
{
    public function __construct(private readonly TestLogger $logger) {}

    public function __invoke(Request $request): View
    {
        $user = $request->user();

        return view('passkeys.index', [
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            'sessionFingerprint' => $this->logger->sessionFingerprint($request->session()->getId()),
        ]);
    }
}
