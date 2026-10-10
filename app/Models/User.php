<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

/**
 * Application user.
 *
 * Implements Laravel\Passkeys\Contracts\PasskeyUser so it can own passkeys.
 * The `passkeys()` relation, `hasPasskeysEnabled()` and the WebAuthn
 * user-handle helpers are provided by the PasskeyAuthenticatable trait.
 */
#[Fillable(['name', 'username', 'email', 'password', 'role'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_authenticated_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
        ];
    }

    public function isAdmin(): bool
    {
        return $this->role === Role::Admin;
    }

    public function isSupervisor(): bool
    {
        return $this->role === Role::Supervisor;
    }

    public function hasRole(Role ...$roles): bool
    {
        return in_array($this->role, $roles, true);
    }

    /**
     * Record the method used for the *current* successful authentication.
     * Called from the Login listener and the passkey-verified listener.
     */
    public function recordAuthentication(string $method): void
    {
        $this->forceFill([
            'last_authenticated_at' => now(),
            'last_auth_method' => $method,
        ])->saveQuietly();
    }
}
