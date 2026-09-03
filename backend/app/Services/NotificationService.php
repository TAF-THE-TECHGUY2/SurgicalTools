<?php

namespace App\Services;

use App\Jobs\SendStockCountDiscrepancyDigest;
use App\Mail\StockCountSummaryMail;
use App\Mail\TransferDocumentMail;
use App\Models\Document;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\TransferItem;
use App\Models\User;
use App\Notifications\InventoryAlertNotification;
use App\Notifications\StockCountDiscrepancyNotification;
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

    /**
     * Voucher spec §3: an orange-flagged transfer line fires an immediate
     * warning to admin operations.
     *
     * Unlike a stock count, a voucher carries a handful of lines rather than
     * hundreds, so there is nothing to batch — every flagged line mails.
     */
    public function transferDiscrepancy(TransferItem $line): void
    {
        $transfer = $line->transfer;

        NotificationFacade::send($this->admins(), new TransferStatusNotification(
            $transfer,
            'discrepancy',
            "Voucher {$transfer->voucher_number} ({$transfer->reference}): {$line->ref_code}"
                .($line->lot_number ? " lot {$line->lot_number}" : '')
                .' is not on the authorised dispatch list for '
                .($transfer->fromLocation?->name ?? 'the source location').'.',
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

    /**
     * Spec §4: a Lot/Stock Adjustment line was raised — alert admin staff.
     *
     * In-app notification is always immediate and per-line. Email is throttled:
     * the first discrepancy on a count mails at once, and the rest are
     * coalesced into a delayed digest so a rep working a shelf of mixed lots
     * cannot bury the admin inbox. Setting
     * surgical.stock_count.discrepancy_digest_minutes to 0 mails every line
     * immediately, which is the literal reading of the spec.
     */
    public function stockCountDiscrepancy(StockCountItem $line, ?User $raisedBy = null): void
    {
        $count = $line->stockCount;

        $isFirst = $count->items()
            ->where('is_adjustment', true)
            ->whereKeyNot($line->getKey())
            ->doesntExist();

        $mailNow = ! $this->shouldDigestDiscrepancies() || $isFirst;

        $recipients = $this->admins()
            // Whoever is scanning already sees the orange row in front of them.
            ->reject(fn (User $u) => $raisedBy && $u->id === $raisedBy->id)
            ->unique('id')
            ->values();

        NotificationFacade::send($recipients, new StockCountDiscrepancyNotification($line, $mailNow));

        if ($mailNow) {
            // Already mailed on its own — keep it out of the next digest.
            $line->forceFill(['digest_notified_at' => now()])->save();

            return;
        }

        SendStockCountDiscrepancyDigest::dispatch($count->id)
            ->delay(now()->addMinutes(
                (int) config('surgical.stock_count.discrepancy_digest_minutes', 5)
            ));
    }

    /**
     * Whether discrepancy emails after the first should be batched.
     *
     * Two things switch batching off. A configured window of 0 is the operator
     * asking for the spec's literal per-line email. A `sync` queue is the more
     * subtle one: a delayed dispatch on sync executes immediately, so the
     * digest would fire once per scan and mail one line at a time — worse than
     * not batching at all. Batching therefore requires a real queue worker,
     * which production runs (QUEUE_CONNECTION=database) and tests do not.
     */
    protected function shouldDigestDiscrepancies(): bool
    {
        return (int) config('surgical.stock_count.discrepancy_digest_minutes', 5) > 0
            && config('queue.default') !== 'sync';
    }

    /**
     * Admin email addresses, plus the configured operational mailboxes. Used
     * for documents and digests, which go out as mail rather than as
     * per-user notifications.
     *
     * @return array<int, string>
     */
    public function adminEmails(): array
    {
        $emails = $this->admins()->pluck('email')->all();

        $emails[] = config('surgical.notifications.office');
        $emails[] = config('surgical.notifications.stock_controller');
        $emails[] = config('surgical.notifications.inventory_controller');

        return array_values(array_unique(array_filter($emails)));
    }

    /**
     * Spec §4 finalisation: the count was submitted — email the Final Summary
     * Report to management with the full dataset attached.
     */
    public function stockCountSummary(StockCount $count, Document $pdf): void
    {
        foreach ($this->adminEmails() as $email) {
            Mail::to($email)->queue(new StockCountSummaryMail($count, $pdf));
        }
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
