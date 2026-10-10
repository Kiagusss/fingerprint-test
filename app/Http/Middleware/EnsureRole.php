<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\TestLogger;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side role gate.
 *
 * The role is read from the authenticated user model loaded from the database
 * (`users.role`). A role supplied by the client — query string, form field or
 * header — is never consulted.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(401);
        }

        if (! in_array($user->role?->value, $roles, true)) {
            app(TestLogger::class)->log('access_denied', [
                'request' => $request,
                'user' => $user,
                'meta' => [
                    'path' => $request->path(),
                    'required' => implode(',', $roles),
                    'actual' => (string) $user->role?->value,
                ],
            ]);

            abort(403, 'Your account does not have access to this page.');
        }

        return $next($request);
    }
}
