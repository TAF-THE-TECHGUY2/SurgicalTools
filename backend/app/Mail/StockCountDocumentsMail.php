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
 * The signed stock-count outputs of §3.5, sent to one audience:
 *
 * - controller: the signed count sheet, to the stock controller who signed it
 * - accounts:   the variance report with the signed sheet (and the lot
 *               adjustment report when there is one)
 * - copy:       the signed sheet, sent on request from the app — e.g. to the
 *               hospital contact
 */
class StockCountDocumentsMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param  array<int, Document>  $documents */
    public function __construct(
        public StockCount $count,
        public array $documents,
        public string $audience,
    ) {}

    public function envelope(): Envelope
    {
        $place = $this->count->location ?? $this->count->hospital?->name;

        return new Envelope(subject: match ($this->audience) {
            'accounts' => "Stock count variance report {$this->count->reference} — {$place}",
            default    => "Signed stock count sheet {$this->count->reference} — {$place}",
        });
    }

    public function content(): Content
    {
        $lines = $this->count->items;
        $varied = $lines->filter(fn ($l) => $l->variance !== null && (int) $l->variance !== 0);

        return new Content(
            markdown: 'mail.stock-count-documents',
            with: [
                'count'     => $this->count,
                'audience'  => $this->audience,
                'varied'    => $varied->count(),
                'netValue'  => $varied->sum(fn ($l) => $l->varianceValue() ?? 0),
                'newLots'   => $lines->filter(fn ($l) => $l->adjustment_type?->value === 'lot_mismatch')->count(),
                'unlisted'  => $lines->filter(fn ($l) => $l->adjustment_type?->value === 'unlisted_item')->count(),
            ],
        );
    }

    public function attachments(): array
    {
        return array_map(
            fn (Document $doc) => Attachment::fromStorageDisk($doc->disk, $doc->path)
                ->as($doc->original_name ?? 'stock-count.pdf')
                ->withMime('application/pdf'),
            $this->documents,
        );
    }
}
