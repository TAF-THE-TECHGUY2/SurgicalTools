<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockItemResource;
use App\Models\StockItem;
use App\Services\ScanExtractionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

/**
 * Stateless label extraction.
 *
 * A stock-count scan posts to the count it belongs to, but a transfer is only
 * created on submit — so the voucher grid has nothing to scan *into* while the
 * rep is building it. This endpoint reads one label and hands back what it
 * says, leaving the caller to decide what it means. The GS1 parser therefore
 * stays in one place instead of being reimplemented in TypeScript.
 */
class ScanController extends Controller
{
    public function __construct(protected ScanExtractionService $extraction) {}

    public function extract(Request $request)
    {
        abort_unless(
            $request->user()->can('stock_count.scan') || $request->user()->can('transfer.create'),
            403,
        );

        $data = $request->validate([
            'barcode' => ['nullable', 'string', 'max:512'],
            'photo'   => ['nullable', 'image', 'mimes:jpeg,png,gif,webp', 'max:10240'],
        ]);

        if (blank($data['barcode'] ?? null) && ! $request->hasFile('photo')) {
            throw ValidationException::withMessages([
                'barcode' => 'Provide a barcode or a label photo.',
            ]);
        }

        $extracted = filled($data['barcode'] ?? null)
            ? $this->fromBarcode($data['barcode'])
            : $this->fromPhoto($request);

        $item = StockItem::resolveFromScan($extracted);

        return response()->json([
            'extracted'  => $extracted,
            'stock_item' => $item ? new StockItemResource($item) : null,
            // Below the threshold the caller must have a human confirm it.
            'needs_review' => ! $item
                || (float) ($extracted['confidence'] ?? 0) < (float) config('surgical.ocr.min_confidence', 0.8),
        ]);
    }

    /** @return array<string, mixed> */
    protected function fromBarcode(string $barcode): array
    {
        try {
            return $this->extraction->parseGs1($barcode);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['barcode' => $e->getMessage()]);
        }
    }

    /** @return array<string, mixed> */
    protected function fromPhoto(Request $request): array
    {
        $file = $request->file('photo');

        try {
            return $this->extraction->extractFromImage(
                (string) file_get_contents($file->getRealPath()),
                (string) $file->getMimeType(),
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['photo' => $e->getMessage()]);
        }
    }
}
