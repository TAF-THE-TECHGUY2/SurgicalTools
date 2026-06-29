<?php

namespace App\Services;

use App\Mail\TransferDocumentMail;
use App\Models\Document;
use App\Models\StockCount;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\InventoryAlertNotification;
use App\Notifications\StockCountRequestedNotification;
use App\Notifications\StockCountStatusNotification;
use App\Notifications\TransferStatusNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Central fan-out for notifications. Combines in-app (database) + email and
 * routes transfer PDFs to the configured distribution lists.
 */
class NotificationService
{
    public function transferAwaitingApproval(Transfer $transfer): void
    {
        $approvers = $this->approversFor($transfer);

        NotificationFacade::send($approvers, new TransferStatusNotification(
            $transfer,
            'pending_approval',
            "Transfer {$transfer->reference} ({$transfer->type->label()}) is awaiting your approval.",
        ));
    }

    public function transferApproved(Transfer $transfer): void
    {
        if ($requester = $transfer->requester) {
            $requester->notify(new TransferStatusNotification(
                $transfer, 'approved', "Your transfer {$transfer->reference} was approved.",
            ));
        }
    }

    public function transferRejected(Transfer $transfer): void
    {
        if ($requester = $transfer->requester) {
            $requester->notify(new TransferStatusNotification(
                $transfer, 'rejected',
                "Transfer {$transfer->reference} was rejected. Reason: ".($transfer->rejection_reason ?? '—'),
            ));
        }
    }

    /** Transfer 2 signed — nudge admins that a final review is required. */
    public function transferAwaitingAdminReview(Transfer $transfer): void
    {
        NotificationFacade::send($this->admins(), new TransferStatusNotification(
            $transfer,
            'awaiting_admin_review',
            "Transfer {$transfer->reference} is signed and awaiting your final review.",
        ));
    }

    /**
     * Transfer completed. Emails the generated PDF to the distribution list
     * and notifies the relevant staff in-app.
     */
    public function transferCompleted(Transfer $transfer, Document $pdf): void
    {
        $heading = $transfer->type->value === 'boot_to_hospital'
            ? 'Delivery Note'
            : 'Transfer Note';

        // Email the PDF to the configured recipients (office / controllers / rep).
        foreach ($this->pdfRecipients($transfer) as $email) {
            Mail::to($email)->queue(new TransferDocumentMail($transfer, $pdf, $heading));
        }

        // In-app notification to requester + assigned rep.
        $recipients = collect([$transfer->requester, $transfer->toHolder, $transfer->fromHolder])
            ->filter()
            ->unique('id');

        NotificationFacade::send($recipients, new TransferStatusNotification(
            $transfer, 'completed', "Transfer {$transfer->reference} is complete. {$heading} attached.",
        ));
    }

    public function stockCountRequested(StockCount $count): void
    {
        if ($count->assignee) {
            $count->assignee->notify(new StockCountRequestedNotification($count));
        }
    }

    /** A rep submitted a count — notify the requester + admins to review it. */
    public function stockCountSubmitted(StockCount $count): void
    {
        $recipients = $this->admins();
        if ($count->requester) {
            $recipients = $recipients->push($count->requester)->unique('id');
        }

        NotificationFacade::send($recipients, new StockCountStatusNotification(
            $count,
            'submitted',
            "Stock count {$count->reference} was submitted and needs review"
                .($count->total_variance ? " (variance {$count->total_variance})." : '.'),
        ));
    }

    public function inventoryAlert(\App\Models\InventoryItem $item, string $alertType, string $severity, string $message): void
    {
        $recipients = $this->inventoryAlertRecipients($item);

        NotificationFacade::send($recipients, new InventoryAlertNotification(
            $item, $alertType, $severity, $message,
        ));
    }

    /* -------------------------------------------------------------------- */
    /*  Recipient resolution                                                */
    /* -------------------------------------------------------------------- */

    /** Who can approve this transfer (drives the "awaiting approval" notice). */
    protected function approversFor(Transfer $transfer)
    {
        if ($transfer->type->value === 'boot_to_hospital' && $transfer->hospital_id) {
            // Assigned reps/runners for the hospital + all admins.
            $assigned = User::whereHas('hospitals', fn ($q) => $q->where('hospitals.id', $transfer->hospital_id))->get();

            return $assigned->merge($this->admins())->unique('id');
        }

        // Transfer 1: the source owner + admins.
        $owner = $transfer->from_holder_user_id ? User::find($transfer->from_holder_user_id) : null;

        return collect([$owner])->filter()->merge($this->admins())->unique('id');
    }

    protected function admins()
    {
        return User::role([
            \App\Enums\UserRole::Admin->value,
            \App\Enums\UserRole::SuperAdmin->value,
        ])->get();
    }

    /** Email addresses that receive the transfer/delivery-note PDF. */
    protected function pdfRecipients(Transfer $transfer): array
    {
        $emails = [
            config('surgical.notifications.office'),
            config('surgical.notifications.stock_controller'),
            config('surgical.notifications.inventory_controller'),
        ];

        if ($transfer->toHolder?->email) {
            $emails[] = $transfer->toHolder->email; // assigned rep
        }
        if ($transfer->fromHolder?->email) {
            $emails[] = $transfer->fromHolder->email;
        }

        // Transfer 2: also the hospital stock controller contact.
        if ($transfer->type->value === 'boot_to_hospital' && $transfer->hospital) {
            $controller = $transfer->hospital->contacts()
                ->where('role', 'like', '%stock%')->first();
            if ($controller?->email) {
                $emails[] = $controller->email;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }

    protected function inventoryAlertRecipients(\App\Models\InventoryItem $item)
    {
        $recipients = $this->admins();

        if ($item->holder) {
            $recipients = $recipients->push($item->holder);
        }
        if ($item->hospital) {
            $recipients = $recipients->merge($item->hospital->users);
        }

        return $recipients->unique('id');
    }
}
