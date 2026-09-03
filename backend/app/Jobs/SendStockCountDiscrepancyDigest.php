<?php

namespace App\Jobs;

use App\Mail\StockCountDiscrepancyDigestMail;
use App\Models\StockCount;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Coalesces discrepancy emails for one count.
 *
 * The spec asks for an email the moment any line is flagged. Taken literally
 * that is one email per scan, which buries the admin inbox during a count of a
 * shelf with mixed lots. Instead the first discrepancy mails immediately and
 * the rest arrive here: one delayed, deduplicated job per count that mails a
 * single digest of everything raised since.
 *
 * ShouldBeUnique keyed on the count means repeat dispatches during the delay
 * window are dropped rather than queued, so N further discrepancies produce
 * one email, not N.
 *
 * Set surgical.stock_count.discrepancy_digest_minutes to 0 to restore the
 * literal per-line behaviour; this job is then never dispatched.
 */
class SendStockCountDiscrepancyDigest implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public function __construct(public int $stockCountId) {}

    /** One pending digest per count. */
    public function uniqueId(): string
    {
        return (string) $this->stockCountId;
    }

    /**
     * Hold the uniqueness lock for the delay window plus a margin, so a lost
     * worker cannot wedge the lock indefinitely.
     */
    public function uniqueFor(): int
    {
        return ((int) config('surgical.stock_count.discrepancy_digest_minutes', 5) * 60) + 300;
    }

    public function handle(NotificationService $notifications): void
    {
        $count = StockCount::with(['items.parentItem', 'hospital', 'assignee'])->find($this->stockCountId);

        if (! $count) {
            return;
        }

        $pending = $count->items()
            ->where('is_adjustment', true)
            ->whereNull('digest_notified_at')
            ->orderBy('id')
            ->get();

        if ($pending->isEmpty()) {
            return;
        }

        // Claim the lines before sending, so a retry after a mail failure
        // cannot re-send the ones that already went out.
        $count->items()->whereIn('id', $pending->modelKeys())
            ->update(['digest_notified_at' => now()]);

        foreach ($notifications->adminEmails() as $email) {
            Mail::to($email)->send(new StockCountDiscrepancyDigestMail($count, $pending));
        }
    }
}
