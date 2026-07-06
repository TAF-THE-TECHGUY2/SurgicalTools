<?php

namespace App\Policies;

use App\Models\Transfer;
use App\Models\User;

class TransferPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('transfer.view');
    }

    public function view(User $user, Transfer $transfer): bool
    {
        return $user->can('transfer.view');
    }

    public function create(User $user): bool
    {
        return $user->can('transfer.create');
    }

    /**
     * Approval authority:
     *  - Admins / Super Admins approve anything.
     *  - A user with the transfer.approve permission may approve when the
     *    stock is leaving THEIR linked location (their boot/office), or when
     *    the transfer touches a hospital they are assigned to as rep/runner.
     */
    public function approve(User $user, Transfer $transfer): bool
    {
        if (! $user->can('transfer.approve')) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        if ($user->location_id && $transfer->from_location_id === $user->location_id) {
            return true;
        }

        $hospitalIds = $user->assignedHospitalIds();
        $transfer->loadMissing(['fromLocation', 'toLocation']);

        foreach ([$transfer->fromLocation, $transfer->toLocation] as $location) {
            if ($location?->hospital_id && in_array($location->hospital_id, $hospitalIds, true)) {
                return true;
            }
        }

        return false;
    }

    /** Rejection mirrors approval authority. */
    public function reject(User $user, Transfer $transfer): bool
    {
        return $this->approve($user, $transfer);
    }

    /** Admin override of an approval. */
    public function override(User $user, Transfer $transfer): bool
    {
        return $user->can('transfer.override') && $user->isAdmin();
    }
}
