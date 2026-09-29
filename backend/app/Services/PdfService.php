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

    /**
     * The stock-count outputs of §3.5, by kind. The signed versions are
     * stored at sign-off; the same views render on demand for printing,
     * which for a count still in progress is a draft.
     */
    public const STOCK_COUNT_DOCUMENTS = [
        'sheet' => [
            'view' => 'pdf.stock-count-sheet', 'type' => 'stock_count_sheet',
            'file' => 'count-sheet', 'orientation' => 'landscape',
        ],
        'variance' => [
            'view' => 'pdf.stock-count-variance', 'type' => 'stock_count_variance',
            'file' => 'variance-report', 'orientation' => 'portrait',
        ],
        'lot-adjustments' => [
            'view' => 'pdf.stock-count-lot-adjustments', 'type' => 'stock_count_lot_adjustments',
            'file' => 'lot-adjustments', 'orientation' => 'landscape',
        ],
    ];

    /** Signed count sheet in the layout of the paper Inventory Count Listing. */
    public function generateStockCountSheet(StockCount $count): Document
    {
        return $this->renderStockCountDocument($count, 'sheet');
    }

    /** Lines with a variance, valued at list/unit price, for the accounts department. */
    public function generateStockCountVariance(StockCount $count): Document
    {
        return $this->renderStockCountDocument($count, 'variance');
    }

    /** New-lot and not-on-sheet lines beside the lot they displaced; null when there are none. */
    public function generateStockCountLotAdjustments(StockCount $count): ?Document
    {
        $count->loadMissing('items');

        if ($count->items->where('is_adjustment', true)->isEmpty()) {
            return null;
        }

        return $this->renderStockCountDocument($count, 'lot-adjustments');
    }

    /** Render one stock-count output to bytes without storing it (print / draft). */
    public function stockCountDocumentBytes(StockCount $count, string $kind): string
    {
        $spec = self::STOCK_COUNT_DOCUMENTS[$kind];

        return Pdf::loadView($spec['view'], $this->stockCountViewData($count))
            ->setPaper('a4', $spec['orientation'])
            ->output();
    }

    public function stockCountFilename(StockCount $count, string $kind): string
    {
        return self::STOCK_COUNT_DOCUMENTS[$kind]['file']."-{$count->reference}.pdf";
    }

    protected function renderStockCountDocument(StockCount $count, string $kind): Document
    {
        $spec = self::STOCK_COUNT_DOCUMENTS[$kind];

        return $this->render(
            $count,
            view: $spec['view'],
            type: $spec['type'],
            filename: $this->stockCountFilename($count, $kind),
            viewData: $this->stockCountViewData($count),
            orientation: $spec['orientation'],
        );
    }

    /** @return array<string, mixed> */
    protected function stockCountViewData(StockCount $count): array
    {
        $count->loadMissing([
            'items.parentItem', 'items.stockItem', 'hospital', 'requester', 'assignee',
            'locationEntity', 'signer',
        ]);

        $disk = Storage::disk(config('filesystems.default'));
        $signature = $count->signature_path && $disk->exists($count->signature_path)
            ? 'data:image/png;base64,'.base64_encode($disk->get($count->signature_path))
            : null;

        return ['count' => $count, 'signature' => $signature];
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
    protected function render(
        Model $owner,
        string $view,
        string $type,
        string $filename,
        array $viewData,
        string $orientation = 'portrait',
    ): Document {
        $pdf = Pdf::loadView($view, $viewData)->setPaper('a4', $orientation);

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
