<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum UserRole: string
{
    use HasOptions;

    case SuperAdmin   = 'super_admin';
    case Admin        = 'admin';
    case GeneralUser  = 'general_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin  => 'Super Admin',
            self::Admin       => 'Admin User',
            self::GeneralUser => 'General User',
        };
    }
}
