<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\ClaudeVisionClient;
use App\Services\Vision\LabelVisionClient;
use App\Services\Vision\OpenAiVisionClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Label reading follows whichever account the operator has. Resolved
        // per request rather than memoised, so flipping OCR_PROVIDER takes
        // effect on the next request instead of the next deploy.
        $this->app->bind(LabelVisionClient::class, fn () => match (config('surgical.ocr.provider')) {
            'openai' => new OpenAiVisionClient,
            default  => new ClaudeVisionClient,
        });
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
