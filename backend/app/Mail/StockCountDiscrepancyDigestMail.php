<?php

namespace App\Mail;

use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

/**
 * The batched discrepancy alert: everything flagged on one count since the
 * last email went out. The immediate single-line alert is a notification
 * (StockCountDiscrepancyNotification); this is its digest counterpart.
 */
class StockCountDiscrepancyDigestMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param Collection<int, \App\Models\StockCountItem> $lines */
    public function __construct(
        public StockCount $count,
        public Collection $lines,
    ) {}

    public function envelope(): Envelope
    {
        $n = $this->lines->count();

        return new Envelope(
            subject: "{$n} further stock ".($n === 1 ? 'discrepancy' : 'discrepancies')
                ." on {$this->count->reference} at {$this->count->location}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.stock-count-discrepancy-digest',
            with: [
                'count' => $this->count,
                'lines' => $this->lines,
            ],
        );
    }
}
