<?php

namespace App\Policies;

use App\Models\StockCount;
use App\Models\User;

class StockCountPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('stock_count.capture') || $user->can('stock_count.review');
    }

    public function view(User $user, StockCount $count): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // Reps see counts assigned to them.
        return $count->assigned_to === $user->id || $count->requested_by === $user->id;
    }

    /** Only admins create count requests. */
    public function create(User $user): bool
    {
        return $user->can('stock_count.review');
    }

    /** The assigned rep captures the count; admins may capture too. */
    public function capture(User $user, StockCount $count): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->can('stock_count.capture') && $count->assigned_to === $user->id;
    }

    /** Admin review / approve / investigate. */
    public function review(User $user, StockCount $count): bool
    {
        return $user->can('stock_count.review');
    }
}
