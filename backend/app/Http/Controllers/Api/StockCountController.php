<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockCountItemResource;
use App\Http\Resources\StockCountResource;
use App\Http\Resources\StockCountScanResource;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockCountScan;
use App\Models\SupplierLabelTemplate;
use App\Services\NotificationService;
use App\Services\PdfService;
use App\Services\ScanExtractionService;
use App\Services\StockCountScanService;
use App\Services\StockCountService;
use App\Support\SignatureStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class StockCountController extends Controller
{
    public function __construct(
        protected StockCountService $service,
        protected StockCountScanService $scans,
        protected ScanExtractionService $extraction,
        protected PdfService $pdf,
        protected NotificationService $notifications,
    ) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockCount::class);

        $user = $request->user();

        $counts = StockCount::query()
            ->with(['hospital', 'requester', 'assignee', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($sub) use ($user) {
                $sub->where('assigned_to', $user->id)->orWhere('requested_by', $user->id);
            }))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return StockCountResource::collection($counts);
    }

    public function show(StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);

        return new StockCountResource(
            $stockCount->load(['hospital', 'requester', 'assignee', 'signer', 'items.inventoryItem', 'items.parentItem', 'documents'])
        );
    }

    /** Admin creates a count request (snapshots expected quantities). */
    public function store(Request $request)
    {
        $this->authorize('create', StockCount::class);

        $data = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $count = $this->service->create($data, $request->user());

        return (new StockCountResource($count))->response()->setStatusCode(201);
    }

    /**
     * Finish and sign the count. Refused while any line is unresolved; on
     * success the count locks and the signed outputs are sent.
     */
    public function submit(Request $request, StockCount $stockCount)
    {
        $this->authorize('signOff', $stockCount);

        // Lines are optional: a count completed entirely by scanning has
        // nothing keyed, and the scan tallies are folded in on submit.
        $data = $request->validate([
            'lines'                    => ['nullable', 'array'],
            'lines.*.id'               => ['required', 'integer', 'exists:stock_count_items,id'],
            'lines.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'lines.*.notes'            => ['nullable', 'string'],
            'signature'                => ['required', 'string', 'starts_with:data:image/png;base64,', 'max:2000000'],
            'signed_by_name'           => ['required', 'string', 'max:255'],
            'device'                   => ['nullable', 'string', 'max:255'],
        ]);

        $count = $this->service->submit($stockCount, $data['lines'] ?? [], [
            'signature' => $data['signature'],
            'name'      => $data['signed_by_name'],
            'device'    => $data['device'] ?? $request->userAgent(),
        ], $request->user());

        return new StockCountResource($count->load(['hospital', 'requester', 'assignee']));
    }

    /** Rule 6: MINUS — none of this line were found. */
    public function markNotFound(Request $request, StockCount $stockCount, StockCountItem $item)
    {
        $this->authorize('capture', $stockCount);
        abort_unless($item->stock_count_id === $stockCount->id, 404);

        $this->service->markNotFound($stockCount, $item, $request->user());

        return new StockCountResource($stockCount->fresh(['items']));
    }

    /** Undo a MINUS tapped by mistake. */
    public function clearNotFound(StockCount $stockCount, StockCountItem $item)
    {
        $this->authorize('capture', $stockCount);
        abort_unless($item->stock_count_id === $stockCount->id, 404);

        $this->service->clearNotFound($stockCount, $item);

        return new StockCountResource($stockCount->fresh(['items']));
    }

    /**
     * One of the §3.5 outputs as a PDF, shown inline so the browser can print
     * it. A signed count serves the copy stored at sign-off — the record — and
     * a count still open renders a draft on the spot.
     */
    public function document(Request $request, StockCount $stockCount, string $kind)
    {
        $this->authorize('view', $stockCount);
        abort_unless(array_key_exists($kind, PdfService::STOCK_COUNT_DOCUMENTS), 404);

        $filename = $this->pdf->stockCountFilename($stockCount, $kind);
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        $stored = $stockCount->isLocked()
            ? $stockCount->document(PdfService::STOCK_COUNT_DOCUMENTS[$kind]['type'])
            : null;

        $bytes = $stored && Storage::disk($stored->disk)->exists($stored->path)
            ? Storage::disk($stored->disk)->get($stored->path)
            : $this->pdf->stockCountDocumentBytes($stockCount, $kind);

        return response($bytes, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
        ]);
    }

    /** Email the signed sheet to further addresses, e.g. the hospital contact. */
    public function emailSheet(Request $request, StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);

        $data = $request->validate([
            'emails'   => ['required', 'array', 'min:1', 'max:10'],
            'emails.*' => ['required', 'email'],
        ]);

        if (! $stockCount->isLocked()) {
            throw ValidationException::withMessages(['emails' => 'The sheet can be emailed once the count is signed.']);
        }

        $sheet = $stockCount->document('stock_count_sheet') ?? $this->pdf->generateStockCountSheet($stockCount);

        $this->notifications->stockCountSheetTo($stockCount, $sheet, array_values(array_unique($data['emails'])));

        return response()->json(['sent_to' => array_values(array_unique($data['emails']))]);
    }

    /** Lot adjustment report: move stock from the old lot onto the lot found. */
    public function adjustLot(Request $request, StockCount $stockCount, StockCountItem $item)
    {
        $this->authorize('review', $stockCount);
        abort_unless($item->stock_count_id === $stockCount->id, 404);

        $line = $this->service->adjustLot($stockCount, $item, $request->user());

        return response()->json([
            'line'        => new StockCountItemResource($line),
            'stock_count' => new StockCountResource($stockCount->fresh(['items'])),
        ]);
    }

    /** Attach an evidence photo to a count line. */
    public function uploadPhoto(Request $request, StockCount $stockCount)
    {
        $this->authorize('capture', $stockCount);

        $data = $request->validate([
            'stock_count_item_id' => ['required', 'exists:stock_count_items,id'],
            'photo'               => ['required', 'image', 'max:8192'],
        ]);

        $path = SignatureStorage::storeUpload($data['photo'], "documents/counts/{$stockCount->id}");

        $stockCount->items()->where('id', $data['stock_count_item_id'])
            ->update(['photo_path' => $path]);

        return response()->json(['path' => $path]);
    }

    /**
     * Record one label capture against the count.
     *
     * Three inputs, in order of preference: a decoded `barcode` string (exact,
     * parsed as GS1), a `photo` for the vision fallback, or the fields typed in
     * by hand. Returns the scan plus the line it landed on — which for a
     * discrepancy is a newly inserted orange line, not the expected row.
     */
    public function scan(Request $request, StockCount $stockCount)
    {
        $this->authorize('scan', $stockCount);

        $data = $request->validate([
            'barcode'     => ['nullable', 'string', 'max:512'],
            'photo'       => ['nullable', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'],
            'ref'         => ['nullable', 'string', 'max:120'],
            'gtin'        => ['nullable', 'string', 'max:20'],
            'lot_number'  => ['nullable', 'string', 'max:120'],
            'expiry_date' => ['nullable', 'date'],
            'client_id'   => ['nullable', 'string', 'max:64'],
            'template_id' => ['nullable', 'integer', 'exists:supplier_label_templates,id'],
        ]);

        if (blank($data['barcode'] ?? null) && ! $request->hasFile('photo') && blank($data['ref'] ?? null) && blank($data['gtin'] ?? null)) {
            throw ValidationException::withMessages([
                'barcode' => 'Provide a barcode, a label photo, or the reference number.',
            ]);
        }

        [$extracted, $context] = $this->extract($request, $stockCount, $data);

        $scan = $this->scans->record($stockCount, $extracted, $context, $request->user());

        return response()->json([
            'scan'         => new StockCountScanResource($scan->load('line')),
            'line'         => $scan->line ? new StockCountItemResource($scan->line) : null,
            'needs_review' => $scan->needsReview(),
            'stock_count'  => new StockCountResource($stockCount->fresh(['items'])),
        ], 201);
    }

    /**
     * Turn the request into an extracted triple. Barcode first — it is exact —
     * then the photo, then whatever was typed.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    protected function extract(Request $request, StockCount $stockCount, array $data): array
    {
        $template = filled($data['template_id'] ?? null)
            ? SupplierLabelTemplate::find($data['template_id'])
            : null;

        if (filled($data['barcode'] ?? null)) {
            try {
                $extracted = $this->extraction->readBarcode($data['barcode'], $template);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
            }

            return [$extracted, [
                'source'      => StockCountScan::SOURCE_BARCODE,
                'raw_payload' => $data['barcode'],
                'client_id'   => $data['client_id'] ?? null,
            ]];
        }

        if ($request->hasFile('photo')) {
            $file = $request->file('photo');

            try {
                $extracted = $this->extraction->extractFromImage(
                    (string) file_get_contents($file->getRealPath()),
                    (string) $file->getMimeType(),
                    $template,
                );
            } catch (\RuntimeException $e) {
                throw ValidationException::withMessages(['photo' => $e->getMessage()]);
            }

            return [$extracted, [
                'source'     => StockCountScan::SOURCE_VISION,
                'image_path' => SignatureStorage::storeUpload($file, "documents/counts/{$stockCount->id}/scans"),
                'client_id'  => $data['client_id'] ?? null,
            ]];
        }

        return [[
            'ref'           => $data['ref'] ?? null,
            'gtin'          => $data['gtin'] ?? null,
            'lot_number'    => $data['lot_number'] ?? null,
            'expiry_date'   => $data['expiry_date'] ?? null,
            'serial_number' => null,
            'confidence'    => 1.0,
            'raw_text'      => '',
        ], [
            'source'    => StockCountScan::SOURCE_MANUAL,
            'client_id' => $data['client_id'] ?? null,
        ]];
    }

    /**
     * The runner confirms or corrects an extraction. A confirmed GTIN that the
     * catalogue didn't have is learned, so the next scan of the same product
     * resolves straight off the barcode.
     */
    public function confirmScan(Request $request, StockCount $stockCount, StockCountScan $scan)
    {
        $this->authorize('scan', $stockCount);

        abort_unless($scan->stock_count_id === $stockCount->id, 404);

        $data = $request->validate([
            'ref'         => ['nullable', 'string', 'max:120'],
            'gtin'        => ['nullable', 'string', 'max:20'],
            'lot_number'  => ['nullable', 'string', 'max:120'],
            'expiry_date' => ['nullable', 'date'],
        ]);

        $scan = $this->scans->confirm($scan, $data, $request->user());

        return response()->json([
            'scan'        => new StockCountScanResource($scan->load('line')),
            'line'        => $scan->line ? new StockCountItemResource($scan->line) : null,
            'stock_count' => new StockCountResource($stockCount->fresh(['items'])),
        ]);
    }

    /**
     * Attach a label photo to a scan that already exists.
     *
     * Offline scans sync as JSON, which cannot carry a Blob, so the image
     * follows once the scan row has landed. Best-effort by design: the scan and
     * its match are already correct without the photo.
     */
    public function attachScanImage(Request $request, StockCountScan $scan)
    {
        $this->authorize('scan', $scan->stockCount);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'],
        ]);

        $scan->update([
            'image_path' => SignatureStorage::storeUpload(
                $request->file('photo'),
                "documents/counts/{$scan->stock_count_id}/scans",
            ),
        ]);

        return new StockCountScanResource($scan->fresh());
    }

    /**
     * Remove a mis-scan. Only adjustment lines can be deleted — an expected
     * line is part of the snapshot and deleting it would silently shrink what
     * the count is measured against.
     */
    public function destroyLine(Request $request, StockCount $stockCount, StockCountItem $item)
    {
        $this->authorize('scan', $stockCount);

        abort_unless($item->stock_count_id === $stockCount->id, 404);

        if (! $item->is_adjustment) {
            throw ValidationException::withMessages([
                'item' => 'Expected lines belong to the snapshot and cannot be removed. Set the counted quantity to 0 instead.',
            ]);
        }

        $item->delete();

        return response()->json([
            'stock_count' => new StockCountResource($stockCount->fresh(['items'])),
        ]);
    }

    /** Admin review: approve (apply variances) or investigate. */
    public function review(Request $request, StockCount $stockCount)
    {
        $this->authorize('review', $stockCount);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'investigate'])],
        ]);

        $count = $this->service->review($stockCount, $request->user(), $data['action']);

        return new StockCountResource($count);
    }
}
