<?php

namespace App\Services;

use App\Mail\TransferDocumentMail;
use App\Models\Document;
use App\Models\StockCount;
use App\Models\StockItem;
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
        NotificationFacade::send($this->approversFor($transfer), new TransferStatusNotification(
            $transfer,
            'pending_approval',
            "Transfer {$transfer->reference} ({$transfer->fromLocation?->name} → {$transfer->toLocation?->name}) is awaiting your approval.",
        ));
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

    /**
     * Transfer approved & completed. Emails the generated PDF to the
     * distribution list and notifies the involved parties in-app.
     */
    public function transferCompleted(Transfer $transfer, Document $pdf): void
    {
        $heading = $transfer->toLocation?->type === 'hospital' ? 'Delivery Note' : 'Transfer Note';

        foreach ($this->pdfRecipients($transfer) as $email) {
            Mail::to($email)->queue(new TransferDocumentMail($transfer, $pdf, $heading));
        }

        // In-app: requester + owners of the source and destination locations.
        $recipients = collect([$transfer->requester])
            ->merge($this->locationUsers($transfer->from_location_id))
            ->merge($this->locationUsers($transfer->to_location_id))
            ->filter()
            ->unique('id');

        NotificationFacade::send($recipients, new TransferStatusNotification(
            $transfer, 'completed',
            "Transfer {$transfer->reference} approved — stock moved to {$transfer->toLocation?->name}. {$heading} attached.",
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

    /** Low-stock / expiry alert for a stock item. */
    public function stockAlert(StockItem $item, string $alertType, string $severity, string $message): void
    {
        NotificationFacade::send($this->admins(), new InventoryAlertNotification(
            $item, $alertType, $severity, $message,
        ));
    }

    /* -------------------------------------------------------------------- */
    /*  Recipient resolution                                                */
    /* -------------------------------------------------------------------- */

    /**
     * Who can approve this transfer:
     *  - users linked to the source location (the stock's owner approves it out)
     *  - the assigned reps of a hospital destination/source
     *  - all admins.
     */
    protected function approversFor(Transfer $transfer)
    {
        $transfer->loadMissing(['fromLocation', 'toLocation']);

        $recipients = $this->locationUsers($transfer->from_location_id);

        foreach ([$transfer->fromLocation, $transfer->toLocation] as $location) {
            if ($location?->hospital_id) {
                $recipients = $recipients->merge(
                    User::whereHas('hospitals', fn ($q) => $q->where('hospitals.id', $location->hospital_id))->get()
                );
            }
        }

        return $recipients->merge($this->admins())
            // The requester shouldn't be nudged to approve their own request.
            ->reject(fn (User $u) => $u->id === $transfer->requested_by)
            ->unique('id')
            ->values();
    }

    /** Users whose "My Inventory" is the given location. */
    protected function locationUsers(?int $locationId)
    {
        return $locationId
            ? User::where('location_id', $locationId)->where('is_active', true)->get()
            : collect();
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
            $transfer->requester?->email,
        ];

        foreach ($this->locationUsers($transfer->to_location_id) as $user) {
            $emails[] = $user->email;
        }

        // Hospital destination: include the hospital's stock controller contact.
        if ($transfer->toLocation?->hospital_id) {
            $controller = \App\Models\HospitalContact::where('hospital_id', $transfer->toLocation->hospital_id)
                ->where('role', 'like', '%stock%')->first();
            if ($controller?->email) {
                $emails[] = $controller->email;
            }
        }

        return array_values(array_unique(array_filter($emails)));
    }
}
