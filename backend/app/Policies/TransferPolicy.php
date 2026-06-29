<?php

namespace App\Policies;

use App\Enums\TransferType;
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

    public function update(User $user, Transfer $transfer): bool
    {
        // Only the requester may edit a draft; admins may edit anything.
        if ($user->isAdmin()) {
            return true;
        }

        return $user->can('transfer.create')
            && $transfer->requested_by === $user->id
            && $transfer->status->value === 'draft';
    }

    /**
     * The core business rule:
     *  - Admins / Super Admins may approve ANY transfer.
     *  - General users may approve Transfer 2 (boot→hospital) only for a
     *    hospital they are assigned to (rep/runner).
     *  - General users may approve Transfer 1 (source→boot) only when they are
     *    the source owner whose stock is being moved.
     */
    public function approve(User $user, Transfer $transfer): bool
    {
        if (! $user->can('transfer.approve')) {
            return false;
        }

        if ($user->isAdmin()) {
            return true; // transfer.approve_any
        }

        return match ($transfer->type) {
            TransferType::BootToHospital =>
                in_array($transfer->hospital_id, $user->assignedHospitalIds(), true),
            TransferType::SourceToBoot =>
                $transfer->from_holder_user_id === $user->id,
        };
    }

    /** Same gate as approve — rejection mirrors approval authority. */
    public function reject(User $user, Transfer $transfer): bool
    {
        return $this->approve($user, $transfer);
    }

    /** Admin override of a hospital approval (Transfer 2). */
    public function override(User $user, Transfer $transfer): bool
    {
        return $user->can('transfer.override') && $user->isAdmin();
    }

    /** Final admin review that posts the movement to inventory. */
    public function review(User $user, Transfer $transfer): bool
    {
        return $user->can('transfer.review') && $user->isAdmin();
    }

    /** Capturing the hospital stock-controller signature. */
    public function sign(User $user, Transfer $transfer): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        // The assigned rep/runner facilitates the on-site signature.
        return $user->can('transfer.create')
            && in_array($transfer->hospital_id, $user->assignedHospitalIds(), true);
    }
}
