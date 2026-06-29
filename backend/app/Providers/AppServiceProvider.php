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
            return $user->hasRole(UserRole::SuperAdmin->value) ? true : null;
        });
    }
}
