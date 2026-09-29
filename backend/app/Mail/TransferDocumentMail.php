<?php

namespace App\Mail;

use App\Models\Document;
use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class TransferDocumentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Transfer $transfer,
        public Document $document,
        public string $heading,
    ) {}

    /**
     * dev-spec §5 subject format: "Transfer Voucher #130234 - Arwyp Medical
     * Centre". Falls back to the internal reference for records raised before
     * voucher numbers existed.
     */
    public function envelope(): Envelope
    {
        $number = $this->transfer->voucher_number ?? $this->transfer->reference;
        $deliverTo = $this->transfer->toLocation?->name
            ?? $this->transfer->hospital?->name
            ?? 'Unspecified destination';

        return new Envelope(
            subject: "Transfer Voucher #{$number} - {$deliverTo}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.transfer-document',
            with: [
                'transfer' => $this->transfer,
                'heading'  => $this->heading,
            ],
        );
    }

    public function attachments(): array
    {
        return [
            Attachment::fromStorageDisk($this->document->disk, $this->document->path)
                ->as($this->document->original_name ?? 'document.pdf')
                ->withMime('application/pdf'),
        ];
    }
}
