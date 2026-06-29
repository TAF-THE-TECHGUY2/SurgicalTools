<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function view(User $user, User $model): bool
    {
        return $user->can('user.manage') || $user->id === $model->id;
    }

    public function create(User $user): bool
    {
        return $user->can('user.manage');
    }

    public function update(User $user, User $model): bool
    {
        return $user->can('user.manage');
    }

    public function delete(User $user, User $model): bool
    {
        // Cannot delete yourself; needs user.manage.
        return $user->can('user.manage') && $user->id !== $model->id;
    }

    public function manageRoles(User $user): bool
    {
        return $user->can('role.manage');
    }
}
