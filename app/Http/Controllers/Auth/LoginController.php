<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\TestLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Laravel\Passkeys\Passkey;

/**
 * Fallback username/password authentication.
 *
 * Used for first-time setup and account recovery only; passkeys are the primary
 * mechanism. Input is validated server-side, the session id is regenerated on
 * success, and the response never reveals whether a username exists.
 */
class LoginController extends Controller
{
    public function __construct(private readonly TestLogger $logger) {}

    public function create(Request $request): View
    {
        return view('auth.login', [
            'hasPasskeys' => $this->anyPasskeysRegistered(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        // Diagnostics only: which Windows account the tester says they are on.
        // This is NOT treated as an identity signal anywhere.
        $osLabel = $request->string('os_account_label')->toString();
        if ($osLabel !== '') {
            $request->session()->put('os_account_label', mb_substr($osLabel, 0, 64));
        }

        $ok = Auth::attempt(
            ['username' => $credentials['username'], 'password' => $credentials['password']],
            (bool) ($credentials['remember'] ?? false),
        );

        if (! $ok) {
            throw ValidationException::withMessages([
                'username' => __('These credentials do not match our records.'),
            ]);
        }

        // Prevent session fixation.
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function anyPasskeysRegistered(): bool
    {
        return Passkey::query()->exists();
    }
}
