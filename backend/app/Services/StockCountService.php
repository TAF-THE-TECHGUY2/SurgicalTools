<?php

namespace App\Services;

use App\Enums\DeviceUnitStatus;
use App\Enums\StockCountAdjustmentType;
use App\Enums\StockCountStatus;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockItem;
use App\Models\User;
use App\Support\ReferenceGenerator;
use App\Support\SignatureStorage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class StockCountService
{
    public function __construct(
        protected InventoryService $inventory,
        protected NotificationService $notifications,
        protected PdfService $pdf,
    ) {}

    /**
     * Admin creates a count request for a location. Expected quantities are
     * snapshotted from the device units currently at that location, one line
     * per (stock item, lot number) — see snapshotExpectedLines().
     */
    public function create(array $data, User $admin): StockCount
    {
        return DB::transaction(function () use ($data, $admin) {
            $location = Location::findOrFail($data['location_id']);

            $count = StockCount::create([
                'reference'    => ReferenceGenerator::next(StockCount::class, 'reference', 'SC'),
                'status'       => StockCountStatus::Requested->value,
                'location'     => $location->name,
                'location_id'  => $location->id,
                'hospital_id'  => $location->hospital_id,
                'requested_by' => $admin->id,
                'assigned_to'  => $data['assigned_to'] ?? $location->owner_user_id,
                'notes'        => $data['notes'] ?? null,
            ]);

            $this->snapshotExpectedLines($count, $location);

            $this->notifications->stockCountRequested($count);

            return $count->fresh('items');
        });
    }

    /**
     * Snapshot what the location is expected to hold, one line per
     * (stock item, lot number) pair — an item held under three lots becomes
     * three lines. The lot is what a scan is matched against, so a count that
     * collapsed lots together could not detect the spec's core exception
     * ("expects Lot 254, physical item is Lot 256").
     *
     * Expiry is snapshotted as the earliest in the group: a lot normally
     * carries a single expiry, and where data has drifted the earliest is the
     * conservative reference and matches the order `markUnitsMissing()` writes
     * units off in.
     */
    protected function snapshotExpectedLines(StockCount $count, Location $location): void
    {
        $expected = DeviceUnit::query()
            ->where('location_id', $location->id)
            ->whereIn('status', [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value])
            ->groupBy('stock_item_id', 'lot_number')
            ->orderBy('stock_item_id')
            ->orderBy('lot_number')
            ->get([
                'stock_item_id',
                'lot_number',
                DB::raw('COUNT(*) as qty'),
                DB::raw('MIN(expiry_date) as earliest_expiry'),
            ]);

        // Resolved in one query rather than per line. Trashed catalogue entries
        // are included so a line still shows a readable code and description
        // instead of a bare id.
        $items = StockItem::withTrashed()
            ->whereIn('id', $expected->pluck('stock_item_id')->unique())
            ->get()
            ->keyBy('id');

        foreach ($expected as $row) {
            $item = $items->get($row->stock_item_id);

            $count->items()->create([
                'stock_item_id'     => $row->stock_item_id,
                ...StockCountItem::catalogueColumns($item, $row->stock_item_id),
                'lot_number'        => $row->lot_number,
                'expiry_date'       => $row->earliest_expiry,
                'expected_quantity' => (int) $row->qty,
            ]);
        }
    }


    /**
     * Rule 6: the counter confirms none of an expected line were found. Its
     * counted quantity becomes 0, so the variance is -Syst Qty straight away.
     */
    public function markNotFound(StockCount $count, StockCountItem $line, User $user): StockCountItem
    {
        $this->assertEditable($count);

        if ($line->is_adjustment) {
            throw ValidationException::withMessages([
                'item' => 'Only lines on the count sheet can be marked as not found.',
            ]);
        }

        if ((int) $line->scanned_quantity > 0) {
            throw ValidationException::withMessages([
                'item' => "{$line->ref_code} has already been scanned — it cannot also be marked as not found.",
            ]);
        }

        $line->update([
            'counted_quantity' => 0,
            'not_found_at'     => now(),
            'not_found_by'     => $user->id,
        ]);

        return $line->fresh();
    }

    /** Undo a minus-confirmation tapped by mistake. */
    public function clearNotFound(StockCount $count, StockCountItem $line): StockCountItem
    {
        $this->assertEditable($count);

        if ($line->not_found_at !== null) {
            $line->forceFill([
                'counted_quantity' => null,
                'variance'         => null,
                'not_found_at'     => null,
                'not_found_by'     => null,
            ])->save();
        }

        return $line->fresh();
    }

    /**
     * Finish the count: apply keyed quantities, fold in the scan tallies,
     * refuse while any line is unresolved (§3.4), then take the stock
     * controller's signature and lock the count.
     *
     * $lines carries whatever was keyed by hand. Anything scanned but never
     * typed into is folded in from its running scan tally, so a count
     * completed entirely by scanning submits correctly with an empty $lines.
     *
     * $signOff: {signature: data-URI PNG, name: string, device?: string}
     *
     * On sign-off the signed count sheet, the variance report and the lot
     * adjustment report are generated; the sheet goes to the stock controller
     * and the variance report (with the sheet) to the accounts department.
     */
    public function submit(StockCount $count, array $lines, array $signOff, User $signer): StockCount
    {
        $count = DB::transaction(function () use ($count, $lines, $signOff, $signer) {
            // Serialise against a scan landing while the count is being closed.
            $count = $count->newQuery()->whereKey($count->getKey())->lockForUpdate()->firstOrFail();

            $this->assertEditable($count);

            $keyed = [];

            foreach ($lines as $line) {
                $item = $count->items()->find($line['id'] ?? 0);
                if (! $item) {
                    continue;
                }
                $keyed[] = $item->id;
                $counted = (int) $line['counted_quantity'];
                $item->update([
                    'counted_quantity' => $counted,
                    'photo_path'       => $line['photo_path'] ?? $item->photo_path,
                    'notes'            => $line['notes'] ?? $item->notes,
                    // A keyed unit contradicts an earlier "none found".
                    ...($counted > 0 ? ['not_found_at' => null, 'not_found_by' => null] : []),
                ]);
            }

            $this->foldScannedQuantities($count, $keyed);

            $unresolved = $count->items()->expected()->get()->reject->isResolved();

            if ($unresolved->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'lines' => sprintf(
                        '%d line%s not scanned yet: %s. Scan them, or tap minus to confirm none were found.',
                        $unresolved->count(),
                        $unresolved->count() === 1 ? ' is' : 's are',
                        $unresolved->take(5)->map(fn ($l) => $l->ref_code.($l->lot_number ? " lot {$l->lot_number}" : ''))->implode(', ')
                            .($unresolved->count() > 5 ? '…' : ''),
                    ),
                ]);
            }

            try {
                $signaturePath = SignatureStorage::storeBase64($signOff['signature'], "counts-{$count->id}");
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['signature' => $e->getMessage()]);
            }

            $count->update([
                'status'         => StockCountStatus::Submitted->value,
                'submitted_at'   => now(),
                'signed_by'      => $signer->id,
                'signed_by_name' => $signOff['name'],
                'signature_path' => $signaturePath,
                'signed_at'      => now(),
                'signed_device'  => mb_substr((string) ($signOff['device'] ?? ''), 0, 255) ?: null,
            ]);

            return $count->fresh('items');
        });

        $this->notifications->stockCountSubmitted($count);

        // Outside the transaction: a PDF render or a mail failure must not roll
        // back the sign-off the controller already completed in the field.
        $this->distributeSignedCount($count);

        return $count->fresh('items');
    }

    /** Signed counts are locked: nothing on them may change any more. */
    public function assertEditable(StockCount $count): void
    {
        if ($count->isLocked()) {
            throw ValidationException::withMessages([
                'stock_count' => "Stock count {$count->reference} is signed and locked.",
            ]);
        }
    }

    /**
     * Lines the runner scanned but never keyed take their counted quantity
     * from the scan tally. A line the runner explicitly keyed wins — a typed
     * number is a deliberate correction of what the scanner saw.
     *
     * @param  array<int, int>  $keyedIds
     */
    protected function foldScannedQuantities(StockCount $count, array $keyedIds): void
    {
        $scanned = $count->items()
            ->where('scanned_quantity', '>', 0)
            ->whereNull('counted_quantity')
            ->when($keyedIds !== [], fn ($q) => $q->whereIntegerNotInRaw('id', $keyedIds))
            ->get();

        foreach ($scanned as $line) {
            $line->update(['counted_quantity' => (int) $line->scanned_quantity]);
        }
    }

    /**
     * Generate the signed outputs and send them where §3.5 says. Each step is
     * isolated: a failed mail must not stop the next report being produced.
     */
    protected function distributeSignedCount(StockCount $count): void
    {
        $documents = [];

        foreach ([
            'sheet'           => fn () => $this->pdf->generateStockCountSheet($count),
            'variance'        => fn () => $this->pdf->generateStockCountVariance($count),
            'lot_adjustments' => fn () => $this->pdf->generateStockCountLotAdjustments($count),
            'summary'         => fn () => $this->pdf->generateStockCountSummary($count),
        ] as $key => $generate) {
            $documents[$key] = $this->attempt("generate {$key}", $count, $generate);
        }

        $count = $count->fresh('items');

        if ($documents['sheet']) {
            $this->attempt('mail signed sheet', $count, fn () => $this->notifications->stockCountSheetToController($count, $documents['sheet']));
        }

        if ($documents['variance']) {
            $this->attempt('mail accounts', $count, fn () => $this->notifications->stockCountToAccounts(
                $count,
                array_values(array_filter([$documents['variance'], $documents['sheet'], $documents['lot_adjustments']])),
            ));
        }

        if ($documents['summary']) {
            $this->attempt('mail summary', $count, fn () => $this->notifications->stockCountSummary($count, $documents['summary']));
        }
    }

    protected function attempt(string $step, StockCount $count, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error("Stock count sign-off: {$step} failed", [
                'stock_count_id' => $count->id,
                'reference'      => $count->reference,
                'error'          => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Lot adjustment report action: the reviewer accepts that units recorded
     * under the old lot are the ones physically found under the new lot, and
     * moves them across in the system.
     *
     * Units are taken from lines of the same product that came up short —
     * the line the adjustment was raised against first, then its siblings —
     * never more than the shortfall, so genuinely surplus stock is not
     * conjured out of another lot. Each side records what moved, which the
     * approval write-off later subtracts.
     */
    public function adjustLot(StockCount $count, StockCountItem $line, User $reviewer): StockCountItem
    {
        return DB::transaction(function () use ($count, $line, $reviewer) {
            $line = StockCountItem::whereKey($line->getKey())->lockForUpdate()->firstOrFail();

            if ($line->adjustment_type !== StockCountAdjustmentType::LotMismatch) {
                throw ValidationException::withMessages(['item' => 'Only new-lot lines can be lot-adjusted.']);
            }

            if (! $count->isLocked()) {
                throw ValidationException::withMessages(['item' => 'Lots can be adjusted once the count is signed.']);
            }

            if ($count->status === StockCountStatus::Approved) {
                throw ValidationException::withMessages([
                    'item' => 'This count is already approved and its variances written off. Correct the lot from the stock catalog instead.',
                ]);
            }

            $found = (int) ($line->counted_quantity ?? $line->scanned_quantity);
            $remaining = $found - (int) $line->lot_adjusted_quantity;

            if ($remaining <= 0 || blank($line->lot_number)) {
                throw ValidationException::withMessages(['item' => 'Nothing left to adjust on this line.']);
            }

            $location = $count->location_id ? Location::find($count->location_id) : null;
            $item = StockItem::withTrashed()->find($line->stock_item_id);

            if (! $location || ! $item) {
                throw ValidationException::withMessages(['item' => 'The count location or product no longer exists.']);
            }

            $sources = $count->items()->expected()
                ->where('stock_item_id', $item->id)
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (StockCountItem $s) => $s->id === $line->parent_item_id ? 0 : 1);

            $moved = 0;

            foreach ($sources as $source) {
                $shortfall = max(0, -(int) $source->variance) - (int) $source->lot_adjusted_quantity;
                $take = min($remaining - $moved, $shortfall);

                if ($take <= 0) {
                    continue;
                }

                $n = $this->inventory->moveUnitsToLot(
                    $item,
                    $location,
                    $source->lot_number,
                    $line->lot_number,
                    $line->expiry_date?->toDateString(),
                    $take,
                    "Stock count {$count->reference}: lot adjustment",
                    $reviewer->id,
                    $count,
                );

                if ($n > 0) {
                    $source->update(['lot_adjusted_quantity' => (int) $source->lot_adjusted_quantity + $n]);
                    $moved += $n;
                }

                if ($moved >= $remaining) {
                    break;
                }
            }

            if ($moved === 0) {
                throw ValidationException::withMessages([
                    'item' => 'No other lot of this product came up short, so there is nothing to move. This is surplus stock — receive it via the stock catalog.',
                ]);
            }

            $line->update([
                'lot_adjusted_quantity' => (int) $line->lot_adjusted_quantity + $moved,
                'lot_adjusted_at'       => now(),
                'lot_adjusted_by'       => $reviewer->id,
            ]);

            return $line->fresh();
        });
    }

    /**
     * Admin review. action = approve|investigate. On approve, negative
     * variances write off the missing units of that line's own lot (oldest
     * expiry first), less any units a lot adjustment already moved off it;
     * positive variances not covered by a lot adjustment are flagged in the
     * line notes for manual receipt (serials of surplus devices must be
     * captured explicitly — the system can't invent them).
     */
    public function review(StockCount $count, User $admin, string $action): StockCount
    {
        return DB::transaction(function () use ($count, $admin, $action) {
            if ($action === 'approve') {
                $location = $count->location_id ? Location::find($count->location_id) : null;

                foreach ($count->items as $line) {
                    if (! $line->variance || ! $line->stock_item_id || ! $location) {
                        continue;
                    }

                    $item = StockItem::withTrashed()->find($line->stock_item_id);
                    if (! $item) {
                        continue;
                    }

                    $outstanding = abs((int) $line->variance) - (int) $line->lot_adjusted_quantity;
                    if ($outstanding <= 0) {
                        continue;
                    }

                    if ($line->variance < 0) {
                        $this->inventory->markUnitsMissing(
                            $item,
                            $location,
                            $outstanding,
                            "Stock count {$count->reference}: {$line->variance} variance"
                                .($line->lot_number ? " · lot {$line->lot_number}" : ''),
                            $admin->id,
                            $count,
                            $line->lot_number,
                        );
                    } else {
                        $line->update([
                            'notes' => trim(($line->notes ?? '')
                                ." | Surplus of {$outstanding}: receive the extra devices via the stock catalog."),
                        ]);
                    }
                }

                $status = StockCountStatus::Approved->value;
            } else {
                $status = StockCountStatus::Investigating->value;
            }

            $count->update([
                'status'      => $status,
                'reviewed_by' => $admin->id,
                'reviewed_at' => now(),
            ]);

            return $count->fresh('items');
        });
    }
}
