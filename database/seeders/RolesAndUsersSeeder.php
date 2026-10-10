<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Idempotent seeder for the two test accounts.
 *
 * Passwords are NEVER hardcoded. They are read from:
 *   TEST_ADMIN_PASSWORD, TEST_SUPERVISOR_PASSWORD
 *
 * If a password is absent:
 *   - APP_ENV=local/testing : a strong random password is generated and printed
 *                             to the console once, so the tester can copy it.
 *   - any other environment : the seeder refuses to run (no weak default that
 *                             could end up on a public server).
 *
 * Re-running only updates username/email/role; an existing password is left
 * untouched unless a new one is explicitly supplied via the environment.
 */
class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAccount(
            username: 'test-admin',
            name: 'Test Admin',
            email: 'test-admin@hello-test.local',
            role: Role::Admin,
            envKey: 'TEST_ADMIN_PASSWORD',
        );

        $this->seedAccount(
            username: 'test-supervisor',
            name: 'Test Supervisor',
            email: 'test-supervisor@hello-test.local',
            role: Role::Supervisor,
            envKey: 'TEST_SUPERVISOR_PASSWORD',
        );
    }

    private function seedAccount(string $username, string $name, string $email, Role $role, string $envKey): void
    {
        $user = User::query()->where('username', $username)->first();

        $provided = trim((string) env($envKey, ''));
        $generated = null;

        if ($provided !== '') {
            $this->assertStrongPassword($provided, $envKey);
        } elseif (! $user) {
            if (! app()->environment('local', 'testing')) {
                throw new RuntimeException(
                    "Refusing to create [{$username}] with a generated password outside local/testing. ".
                    "Set {$envKey} explicitly."
                );
            }

            $generated = Str::password(24);
            $provided = $generated;
        }

        if (! $user) {
            $user = new User;
            $user->username = $username;
            $user->name = $name;
            $user->email = $email;
            $user->email_verified_at = now();
            $user->password = $provided ?? Str::password(24);
        }

        // Only overwrite the password when one was explicitly supplied for a
        // pre-existing account; otherwise leave it so re-seeding is non-destructive.
        if (! $generated && $provided !== '') {
            $user->password = $provided;
        }

        $user->name = $name;
        $user->email = $email;
        $user->role = $role;
        $user->save();

        if ($generated !== null) {
            $this->command?->warn("  [{$username}] generated password: {$generated}  (store it securely; shown once)");
        } else {
            $this->command?->info("  [{$username}] ready (role: {$role->value})");
        }
    }

    private function assertStrongPassword(string $password, string $envKey): void
    {
        if (strlen($password) < 12) {
            throw new RuntimeException("{$envKey} must be at least 12 characters.");
        }

        if (in_array(strtolower($password), ['password', 'password123', '12345678', 'admin', 'secret'], true)) {
            throw new RuntimeException("{$envKey} is a well-known weak password and is rejected.");
        }
    }
}
