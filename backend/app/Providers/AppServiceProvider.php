<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Super Admins implicitly pass every authorization check.
        Gate::before(function (User $user, string $ability) {
            if ($user->hasRole(UserRole::SuperAdmin->value)) {
                return true;
            }

            if ($user->permission_overrides !== null && in_array($ability, User::SYSTEM_PERMISSIONS, true)) {
                return in_array($ability, $user->permission_overrides, true);
            }

            return null;
        });
    }
}
