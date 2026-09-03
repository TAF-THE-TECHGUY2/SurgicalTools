<?php

namespace App\Services;

use App\Models\Document;
use App\Models\StockCount;
use App\Models\Transfer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Renders Blade templates to PDF (DomPDF), stores the bytes on the configured
 * file disk (local / S3) and registers a Document row so the file is queryable
 * and emailable.
 *
 * `render()` takes any model that morphs `documents`, so transfer notes,
 * delivery notes and stock-count summaries all go through one path.
 */
class PdfService
{
    public function generateTransferNote(Transfer $transfer): Document
    {
        return $this->render(
            $this->loadTransfer($transfer),
            view: 'pdf.transfer-note',
            type: 'transfer_pdf',
            filename: "transfer-note-{$transfer->reference}.pdf",
            viewData: ['transfer' => $transfer],
        );
    }

    public function generateDeliveryNote(Transfer $transfer): Document
    {
        return $this->render(
            $this->loadTransfer($transfer),
            view: 'pdf.delivery-note',
            type: 'delivery_note',
            filename: "delivery-note-{$transfer->reference}.pdf",
            viewData: ['transfer' => $transfer],
        );
    }

    /**
     * Spec §4: the Final Summary Report emailed to management once the agent
     * submits the count. Groups the lines into counted, variance and
     * adjustments so the exceptions are not buried in a flat table.
     */
    public function generateStockCountSummary(StockCount $count): Document
    {
        $count->loadMissing([
            'items.stockItem', 'items.parentItem', 'hospital',
            'requester', 'assignee', 'locationEntity',
        ]);

        return $this->render(
            $count,
            view: 'pdf.stock-count-summary',
            type: 'stock_count_summary',
            filename: "stock-count-{$count->reference}.pdf",
            viewData: ['count' => $count],
        );
    }

    protected function loadTransfer(Transfer $transfer): Transfer
    {
        return $transfer->loadMissing([
            'items', 'fromLocation', 'toLocation.hospital', 'requester', 'approver', 'signatures',
        ]);
    }

    /**
     * Render, store and register. $owner must expose a `documents()` morph
     * relation; the file lands under documents/{owners}/{id}/.
     *
     * @param  array<string, mixed>  $viewData
     */
    protected function render(Model $owner, string $view, string $type, string $filename, array $viewData): Document
    {
        $pdf = Pdf::loadView($view, $viewData)->setPaper('a4');

        $disk = config('filesystems.default');
        $folder = Str::plural(Str::snake(class_basename($owner)));
        $path = "documents/{$folder}/{$owner->getKey()}/{$filename}";

        Storage::disk($disk)->put($path, $pdf->output());

        return $owner->documents()->create([
            'type'          => $type,
            'disk'          => $disk,
            'path'          => $path,
            'original_name' => $filename,
            'mime_type'     => 'application/pdf',
            'size'          => Storage::disk($disk)->size($path),
        ]);
    }
}
