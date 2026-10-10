<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Services\TestLogger;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Demonstrator pages for the two test roles. Authorisation is enforced by the
 * `role:` middleware declared on the routes, backed by users.role in the DB.
 */
class RoleTestController extends Controller
{
    public function __construct(private readonly TestLogger $logger) {}

    public function admin(Request $request): View
    {
        return $this->render($request, Role::Admin, 'Admin Test');
    }

    public function supervisor(Request $request): View
    {
        return $this->render($request, Role::Supervisor, 'Supervisor Test');
    }

    private function render(Request $request, Role $role, string $title): View
    {
        $user = $request->user();

        return view('role-test', [
            'title' => $title,
            'role' => $role,
            'user' => $user,
            'passkeys' => $user->passkeys()->latest()->get(),
            // The role the SERVER resolved for this request — not what the client sent.
            'serverResolvedRole' => $user->role,
            'serverTime' => now(),
            'sessionFingerprint' => $this->logger->sessionFingerprint($request->session()->getId()),
        ]);
    }
}
