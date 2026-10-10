<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\TestLog;
use App\Models\User;
use App\Services\TestLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The logger is the single writer of `test_logs`; these tests lock down its
 * non-disclosure guarantees so a future edit cannot start storing secrets.
 */
class TestLoggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_an_allow_listed_event(): void
    {
        $user = User::factory()->admin()->create();

        (new TestLogger)->log('login_success', [
            'user' => $user,
            'auth_method' => 'password',
        ]);

        $this->assertDatabaseHas('test_logs', [
            'event' => 'login_success',
            'user_id' => $user->id,
            'auth_method' => 'password',
        ]);
    }

    public function test_it_rejects_an_unknown_event(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TestLogger)->log('definitely_not_a_event');
    }

    public function test_it_drops_secret_shaped_meta_keys(): void
    {
        $user = User::factory()->admin()->create();

        (new TestLogger)->log('login_success', [
            'user' => $user,
            'meta' => [
                'safe_field' => 'visible',
                'password' => 'super-secret',
                'api_token' => 'tok_123',
                'session_key' => 'abc',
                'raw_challenge' => 'deadbeef',
                'cookie' => 'eyJ...',
                'nested' => ['deep' => 'value'],
                'guard' => 'web',
            ],
        ]);

        $log = TestLog::query()->latest('id')->firstOrFail();

        $this->assertSame('visible', $log->meta['safe_field']);
        $this->assertSame('web', $log->meta['guard']);

        foreach (['password', 'api_token', 'session_key', 'raw_challenge', 'cookie', 'nested'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $log->meta);
        }

        $this->assertStringNotContainsString('super-secret', json_encode($log->meta, JSON_THROW_ON_ERROR));
    }

    public function test_credential_fingerprint_is_non_reversible_and_stable(): void
    {
        $logger = new TestLogger;

        $fp1 = $logger->credentialFingerprint('some-credential-id');
        $fp2 = $logger->credentialFingerprint('some-credential-id');
        $fp3 = $logger->credentialFingerprint('another-credential-id');

        $this->assertSame($fp1, $fp2);
        $this->assertNotSame($fp1, $fp3);
        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{16}$/', $fp1);
        $this->assertStringNotContainsString('some-credential-id', (string) $fp1);
    }

    public function test_session_fingerprint_is_keyed_and_not_the_raw_id(): void
    {
        $logger = new TestLogger;

        $fingerprint = $logger->sessionFingerprint('raw-session-id-xyz');

        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{16}$/', (string) $fingerprint);
        $this->assertStringNotContainsString('raw-session-id-xyz', (string) $fingerprint);
    }

    public function test_it_handles_a_missing_session_gracefully(): void
    {
        // Console/unit contexts have no bound session: logging must not throw.
        $user = User::factory()->admin()->create();

        (new TestLogger)->log('logout', ['user' => $user]);

        $log = TestLog::query()->latest('id')->firstOrFail();

        $this->assertSame('logout', $log->event);
        $this->assertNull($log->session_fingerprint);
    }
}
