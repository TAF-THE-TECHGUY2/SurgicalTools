<?php

namespace App\Services;

use App\Enums\StockCountAdjustmentType;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockCountScan;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The database cross-reference. Takes an extracted label triple, looks it up
 * against the expected inventory for the count's location and either counts it
 * or raises an adjustment.
 *
 * Per spec §6 a discrepancy INSERTs a new secondary line pointing at the
 * primary product via `parent_item_id` — it never overwrites the expected lot
 * row, so the original snapshot stays intact for the variance report.
 */
class StockCountScanService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Record one scan against a count.
     *
     * $extracted: {ref, gtin, lot_number, expiry_date, serial_number, confidence, raw_text}
     * $context:   {source, image_path?, client_id?, raw_payload?}
     */
    public function record(StockCount $count, array $extracted, array $context, User $user): StockCountScan
    {
        // Offline replay: the same capture may arrive twice.
        if (! empty($context['client_id'])) {
            $existing = StockCountScan::where('client_id', $context['client_id'])->first();
            if ($existing) {
                return $existing;
            }
        }

        return DB::transaction(function () use ($count, $extracted, $context, $user) {
            // Serialise concurrent scanners on this count so two runners
            // working the same shelf cannot both increment the same line, or
            // both insert the same adjustment.
            $count->newQuery()->whereKey($count->getKey())->lockForUpdate()->first();

            $item = $this->resolveItem($extracted);

            $scan = new StockCountScan([
                'stock_count_id' => $count->id,
                'stock_item_id'  => $item?->id,
                'image_path'     => $context['image_path'] ?? null,
                'source'         => $context['source'] ?? StockCountScan::SOURCE_MANUAL,
                'raw_payload'    => $context['raw_payload'] ?? ($extracted['raw_text'] ?? null),
                'extracted'      => $this->evidence($extracted),
                'confidence'     => $extracted['confidence'] ?? null,
                'match_result'   => StockCountScan::UNRESOLVED,
                'scanned_by'     => $user->id,
                'client_id'      => $context['client_id'] ?? null,
            ]);

            // Nothing in the catalogue matches the code that was read. Keep the
            // scan as evidence and hand it back for manual entry — inventing a
            // line for an unknown product would corrupt the count.
            if (! $item) {
                $scan->save();

                return $scan;
            }

            [$line, $result] = $this->applyToLines($count, $item, $extracted, $user);

            $scan->stock_count_item_id = $line->id;
            $scan->match_result = $result;
            $scan->save();

            return $scan;
        });
    }

    /**
     * Count the scan against an expected line, or raise an adjustment.
     *
     * @return array{0: StockCountItem, 1: string}
     */
    protected function applyToLines(StockCount $count, StockItem $item, array $extracted, User $user): array
    {
        $scannedLot = StockCountItem::normalizeLot($extracted['lot_number'] ?? null);

        $expectedForItem = $count->items()->expected()
            ->where('stock_item_id', $item->id)
            ->get();

        // The product isn't on this location's expected count at all.
        if ($expectedForItem->isEmpty()) {
            return [
                $this->raiseAdjustment(
                    $count, $item, $extracted, StockCountAdjustmentType::UnlistedItem, null, $user
                ),
                StockCountAdjustmentType::UnlistedItem->value,
            ];
        }

        $match = $expectedForItem->first(
            fn (StockCountItem $line) => StockCountItem::normalizeLot($line->lot_number) === $scannedLot
        );

        // Known product, unexpected lot — the spec's headline exception.
        if (! $match) {
            return [
                $this->raiseAdjustment(
                    $count, $item, $extracted, StockCountAdjustmentType::LotMismatch,
                    $expectedForItem->first(), $user
                ),
                StockCountAdjustmentType::LotMismatch->value,
            ];
        }

        // Item and lot agree, but the physical expiry doesn't match the record.
        if ($this->expiryDiffers($match, $extracted['expiry_date'] ?? null)) {
            return [
                $this->raiseAdjustment(
                    $count, $item, $extracted, StockCountAdjustmentType::ExpiryMismatch, $match, $user
                ),
                StockCountAdjustmentType::ExpiryMismatch->value,
            ];
        }

        $this->tally($match);

        return [$match, StockCountScan::MATCH];
    }

    /**
     * INSERT a discrepancy line. Repeat scans of the same discrepancy tally
     * onto the existing adjustment rather than stacking duplicate rows — the
     * spec requires a new line per discrepancy, not per scan of it.
     */
    protected function raiseAdjustment(
        StockCount $count,
        StockItem $item,
        array $extracted,
        StockCountAdjustmentType $type,
        ?StockCountItem $parent,
        User $user,
    ): StockCountItem {
        $scannedLot = $extracted['lot_number'] ?? null;

        $existing = $count->items()->adjustments()
            ->where('stock_item_id', $item->id)
            ->where('adjustment_type', $type->value)
            ->get()
            ->first(fn (StockCountItem $line) => StockCountItem::normalizeLot($line->lot_number)
                === StockCountItem::normalizeLot($scannedLot));

        if ($existing) {
            $this->tally($existing);

            return $existing;
        }

        $line = $count->items()->create([
            'stock_item_id'       => $item->id,
            'ref_code'            => $item->catalogue_number ?? $item->item_code ?? (string) $item->id,
            'description'         => $item->name,
            'lot_number'          => $scannedLot,
            'expiry_date'         => $this->toDate($extracted['expiry_date'] ?? null),
            'expected_quantity'   => 0,
            'scanned_quantity'    => 1,
            'is_adjustment'       => true,
            'adjustment_type'     => $type->value,
            'parent_item_id'      => $parent?->id,
            'expected_lot_number' => $parent?->lot_number,
            'first_scanned_at'    => now(),
            'last_scanned_at'     => now(),
            'notes'               => $this->adjustmentNote($type, $parent, $extracted),
        ]);

        // Spec §4: the moment a line is flagged, admin staff are alerted.
        $this->notifications->stockCountDiscrepancy($line->fresh(), $user);

        return $line;
    }

    /** Increment a line's running scan tally. */
    protected function tally(StockCountItem $line): void
    {
        $line->forceFill([
            'scanned_quantity' => (int) $line->scanned_quantity + 1,
            'first_scanned_at' => $line->first_scanned_at ?? now(),
            'last_scanned_at'  => now(),
        ])->save();
    }

    /** Resolve the catalogue entry; the order lives on the model. */
    protected function resolveItem(array $extracted): ?StockItem
    {
        return StockItem::resolveFromScan($extracted);
    }

    /**
     * True only when both sides carry a date and they differ. A label whose
     * expiry could not be read is not evidence of a mismatch.
     */
    protected function expiryDiffers(StockCountItem $line, ?string $scannedExpiry): bool
    {
        $scanned = $this->toDate($scannedExpiry);

        if ($scanned === null || $line->expiry_date === null) {
            return false;
        }

        return ! $line->expiry_date->isSameDay($scanned);
    }

    protected function toDate(?string $value): ?Carbon
    {
        if (blank($value)) {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->startOfDay(), null, false);
    }

    protected function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /** What the extraction saw, kept verbatim for the audit trail. */
    protected function evidence(array $extracted): array
    {
        return [
            'ref'           => $extracted['ref'] ?? null,
            'gtin'          => $extracted['gtin'] ?? null,
            'lot_number'    => $extracted['lot_number'] ?? null,
            'expiry_date'   => $extracted['expiry_date'] ?? null,
            'serial_number' => $extracted['serial_number'] ?? null,
        ];
    }

    protected function adjustmentNote(
        StockCountAdjustmentType $type,
        ?StockCountItem $parent,
        array $extracted,
    ): string {
        return match ($type) {
            StockCountAdjustmentType::LotMismatch => sprintf(
                'Lot/Stock Adjustment: expected lot %s, scanned lot %s.',
                $parent?->lot_number ?? '—',
                $extracted['lot_number'] ?? '—',
            ),
            StockCountAdjustmentType::UnlistedItem => 'Lot/Stock Adjustment: item is not on this location\'s expected count.',
            StockCountAdjustmentType::ExpiryMismatch => sprintf(
                'Lot/Stock Adjustment: expected expiry %s, scanned %s.',
                $parent?->expiry_date?->toDateString() ?? '—',
                $extracted['expiry_date'] ?? '—',
            ),
        };
    }

    /**
     * The runner confirms or corrects an extraction. Re-runs the match with
     * whatever they settled on, and teaches the catalogue the GTIN so the same
     * barcode resolves without help next time.
     */
    public function confirm(StockCountScan $scan, array $corrected, User $user): StockCountScan
    {
        return DB::transaction(function () use ($scan, $corrected, $user) {
            $extracted = array_merge($scan->extracted ?? [], array_filter(
                [
                    'ref'         => $corrected['ref'] ?? null,
                    'gtin'        => $corrected['gtin'] ?? null,
                    'lot_number'  => $corrected['lot_number'] ?? null,
                    'expiry_date' => $corrected['expiry_date'] ?? null,
                ],
                fn ($v) => $v !== null,
            ));

            $item = $this->resolveItem($extracted);

            if (! $item) {
                $scan->update([
                    'extracted'    => $this->evidence($extracted),
                    'match_result' => StockCountScan::UNRESOLVED,
                ]);

                return $scan->fresh();
            }

            // Learn the GTIN→item mapping once, so the next scan of this
            // product resolves straight off the barcode.
            if (blank($item->gtin) && filled($extracted['gtin'] ?? null)) {
                $item->update(['gtin' => $this->clean($extracted['gtin'])]);
            }

            [$line, $result] = $this->applyToLines($scan->stockCount, $item, $extracted, $user);

            $scan->update([
                'stock_item_id'       => $item->id,
                'stock_count_item_id' => $line->id,
                'extracted'           => $this->evidence($extracted),
                'match_result'        => $result,
                'confirmed'           => true,
            ]);

            return $scan->fresh();
        });
    }
}
