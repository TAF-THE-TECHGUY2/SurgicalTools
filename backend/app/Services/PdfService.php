<?php

namespace App\Services;

use App\Models\Document;
use App\Models\Transfer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/**
 * Renders Blade templates to PDF (DomPDF), stores the bytes on the configured
 * file disk (local / S3) and registers a Document row so the file is queryable
 * and emailable. Used for Transfer 1 transfer notes and Transfer 2 delivery
 * notes.
 */
class PdfService
{
    public function generateTransferNote(Transfer $transfer): Document
    {
        return $this->render(
            $transfer,
            view: 'pdf.transfer-note',
            type: 'transfer_pdf',
            filename: "transfer-note-{$transfer->reference}.pdf",
        );
    }

    public function generateDeliveryNote(Transfer $transfer): Document
    {
        return $this->render(
            $transfer,
            view: 'pdf.delivery-note',
            type: 'delivery_note',
            filename: "delivery-note-{$transfer->reference}.pdf",
        );
    }

    protected function render(Transfer $transfer, string $view, string $type, string $filename): Document
    {
        $transfer->loadMissing(['items', 'fromLocation', 'toLocation.hospital', 'requester', 'approver', 'signatures']);

        $pdf = Pdf::loadView($view, ['transfer' => $transfer])->setPaper('a4');

        $disk = config('filesystems.default');
        $path = "documents/transfers/{$transfer->id}/{$filename}";

        Storage::disk($disk)->put($path, $pdf->output());

        return $transfer->documents()->create([
            'type'          => $type,
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $filename,
            'mime_type'     => 'application/pdf',
            'size'          => Storage::disk($disk)->size($path),
        ]);
    }
}
