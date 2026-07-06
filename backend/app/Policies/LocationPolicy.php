<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return true; // every authenticated user needs the entity list for transfers
    }

    public function view(User $user, Location $location): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return $user->can('location.manage');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->can('location.manage');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->can('location.manage');
    }
}
