<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TestLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Writes diagnostic rows to `test_logs`.
 *
 * SAFETY CONTRACT — this class is the ONLY writer of test_logs and it accepts a
 * fixed allow-list of fields. It never persists:
 *   - private keys, public keys or raw credential payloads
 *   - raw challenges
 *   - raw session ids / cookies (only a truncated SHA-256 fingerprint)
 *   - biometric data (which the server never receives in the first place)
 */
class TestLogger
{
    /** @var list<string> */
    private const ALLOWED_EVENTS = [
        'login_success',
        'login_failed',
        'logout',
        'passkey_registered',
        'passkey_deleted',
        'passkey_login_success',
        'passkey_login_failed',
        'access_denied',
        'session_probe',
        'test_result_updated',
        'employee_created',
        'employee_passkey_enrolled',
        'employee_passkey_revoked',
        'bootstrap_passkey_enrolled',
    ];

    public function log(string $event, array $context = []): TestLog
    {
        if (! in_array($event, self::ALLOWED_EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown test log event [{$event}].");
        }

        $request = $context['request'] ?? request();

        $user = $context['user'] ?? Auth::user();

        $sessionId = $this->sessionId($request);

        return TestLog::create([
            'event' => $event,
            'user_id' => $user?->getKey(),
            'auth_method' => $this->str($context['auth_method'] ?? null, 32),
            'role' => $this->str($context['role'] ?? $user?->role?->value, 32),
            'os_account_label' => $this->str($context['os_account_label'] ?? null, 64),
            'credential_fingerprint' => $this->credentialFingerprint($context['credential_id'] ?? null),
            'session_fingerprint' => $this->sessionFingerprint($sessionId),
            'ip_address' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? Str::limit((string) $request->userAgent(), 255, '') : null,
            'meta' => $this->safeMeta($context['meta'] ?? []),
        ]);
    }

    /**
     * Non-reversible fingerprint of a credential id. Enough to tell two
     * credentials apart in the log, useless as a credential.
     */
    public function credentialFingerprint(?string $credentialId): ?string
    {
        if (! is_string($credentialId) || $credentialId === '') {
            return null;
        }

        return 'sha256:'.substr(hash('sha256', $credentialId), 0, 16);
    }

    public function sessionFingerprint(?string $sessionId): ?string
    {
        if (! is_string($sessionId) || $sessionId === '') {
            return null;
        }

        // Hashed with the app key so a DB leak does not expose live session ids.
        return 'sha256:'.substr(hash_hmac('sha256', $sessionId, (string) config('app.key')), 0, 16);
    }

    /**
     * Keep only scalar, non-secret metadata. Drop anything nested or long.
     */
    private function safeMeta(mixed $meta): ?array
    {
        if (! is_array($meta)) {
            return null;
        }

        $safe = [];

        foreach ($meta as $key => $value) {
            if (! is_string($key) || ! is_scalar($value)) {
                continue;
            }

            // Never let a caller smuggle secrets through meta.
            if (preg_match('/secret|token|password|key|challenge|cookie/i', $key)) {
                continue;
            }

            $safe[$key] = is_string($value) ? Str::limit($value, 120, '') : $value;
        }

        return $safe === [] ? null : $safe;
    }

    private function str(mixed $value, int $max): ?string
    {
        return is_string($value) && $value !== '' ? Str::limit($value, $max, '') : null;
    }

    /**
     * Resolve the current session id without assuming a session is bound — this
     * logger is also used from console commands and unit tests.
     */
    private function sessionId(mixed $request): ?string
    {
        if (! $request instanceof Request) {
            return null;
        }

        try {
            return $request->hasSession() ? $request->session()->getId() : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
