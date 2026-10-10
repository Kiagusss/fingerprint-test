<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Application role. Authorisation is ALWAYS enforced from the server-side
 * `users.role` column — never from anything the client sends.
 */
enum Role: string
{
    case Admin = 'admin';
    case Supervisor = 'supervisor';
    case Employee = 'employee';
    case User = 'user';

    public function label(): string
    {
        return match ($this) {
            self::Admin => 'Admin',
            self::Supervisor => 'Supervisor',
            self::Employee => 'Karyawan',
            self::User => 'User',
        };
    }
}
