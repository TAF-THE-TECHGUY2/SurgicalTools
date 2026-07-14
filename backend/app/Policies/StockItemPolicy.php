<?php

namespace App\Policies;

use App\Models\StockItem;
use App\Models\User;

class StockItemPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('inventory.view');
    }

    public function view(User $user, StockItem $item): bool
    {
        return $user->can('inventory.view');
    }

    public function create(User $user): bool
    {
        return $user->can('inventory.manage');
    }

    public function update(User $user, StockItem $item): bool
    {
        return $user->can('inventory.manage');
    }

    public function delete(User $user, StockItem $item): bool
    {
        return $user->can('inventory.manage');
    }

}
