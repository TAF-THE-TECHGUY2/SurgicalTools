<?php

namespace App\Policies;

use App\Models\Hospital;
use App\Models\User;

class HospitalPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('hospital.view');
    }

    public function view(User $user, Hospital $hospital): bool
    {
        return $user->can('hospital.view');
    }

    public function create(User $user): bool
    {
        return $user->can('hospital.manage');
    }

    public function update(User $user, Hospital $hospital): bool
    {
        return $user->can('hospital.manage');
    }

    public function delete(User $user, Hospital $hospital): bool
    {
        return $user->can('hospital.manage');
    }
}
