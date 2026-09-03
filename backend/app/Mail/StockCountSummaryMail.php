<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spec §4 finalisation: the Final Summary Report, emailed to management once
 * the inventory agent submits the count, with the full dataset attached.
 */
class StockCountSummaryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public StockCount $count,
        public Document $document,
    ) {}

    public function envelope(): Envelope
    {
        $adjustments = $this->count->items->where('is_adjustment', true)->count();

        return new Envelope(
            subject: "Stock count {$this->count->reference} — {$this->count->location}"
                .($adjustments ? " ({$adjustments} adjustment".($adjustments === 1 ? '' : 's').')' : ''),
        );
    }

    public function content(): Content
    {
        $lines = $this->count->items;

        return new Content(
            markdown: 'mail.stock-count-summary',
            with: [
                'count'       => $this->count,
                'adjustments' => $lines->where('is_adjustment', true),
                'variances'   => $lines->where('is_adjustment', false)
                    ->filter(fn ($l) => $l->counted_quantity !== null && (int) $l->variance !== 0),
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->document->disk, $this->document->path)
                ->as($this->document->original_name ?? 'stock-count.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
