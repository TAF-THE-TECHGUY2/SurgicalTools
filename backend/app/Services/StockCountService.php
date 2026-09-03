<?php

namespace App\Services;

use App\Enums\DeviceUnitStatus;
use App\Enums\StockCountStatus;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\User;
use App\Support\ReferenceGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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
                'ref_code'          => $item?->catalogue_number
                    ?? $item?->item_code
                    ?? (string) $row->stock_item_id,
                'description'       => $item?->name,
                'lot_number'        => $row->lot_number,
                'expiry_date'       => $row->earliest_expiry,
                'expected_quantity' => (int) $row->qty,
            ]);
        }
    }

    /**
     * Rep submits the count (variance auto-computed in the model).
     *
     * $lines carries whatever was keyed by hand. Anything that was scanned but
     * never typed into is folded in from its running scan tally, so a count
     * completed entirely by scanning submits correctly with an empty $lines.
     *
     * On submission the Final Summary Report is generated and emailed.
     */
    public function submit(StockCount $count, array $lines = []): StockCount
    {
        $count = DB::transaction(function () use ($count, $lines) {
            $keyed = [];

            foreach ($lines as $line) {
                $item = $count->items()->find($line['id'] ?? 0);
                if (! $item) {
                    continue;
                }
                $keyed[] = $item->id;
                $item->update([
                    'counted_quantity' => $line['counted_quantity'],
                    'photo_path'       => $line['photo_path'] ?? $item->photo_path,
                    'notes'            => $line['notes'] ?? $item->notes,
                ]);
            }

            $this->foldScannedQuantities($count, $keyed);

            $count->update([
                'status'       => StockCountStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            return $count->fresh('items');
        });

        $this->notifications->stockCountSubmitted($count);

        // Outside the transaction: a PDF render or a mail failure must not roll
        // back the submission the rep already completed in the field.
        $this->emailSummary($count);

        return $count->fresh('items');
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

    /** Generate and email the Final Summary Report; never fatal to a submit. */
    protected function emailSummary(StockCount $count): void
    {
        try {
            $pdf = $this->pdf->generateStockCountSummary($count);
            $this->notifications->stockCountSummary($count->fresh('items'), $pdf);
        } catch (\Throwable $e) {
            Log::error('Stock count summary report failed', [
                'stock_count_id' => $count->id,
                'reference'      => $count->reference,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Admin review. action = approve|investigate. On approve, negative
     * variances write off the missing units of that line's own lot (oldest
     * expiry first); positive variances are flagged in the line notes for
     * manual receipt (serials of surplus devices must be captured explicitly —
     * the system can't invent them).
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

                    if ($line->variance < 0) {
                        $this->inventory->markUnitsMissing(
                            $item,
                            $location,
                            abs((int) $line->variance),
                            "Stock count {$count->reference}: {$line->variance} variance"
                                .($line->lot_number ? " · lot {$line->lot_number}" : ''),
                            $admin->id,
                            $count,
                            $line->lot_number,
                        );
                    } else {
                        $line->update([
                            'notes' => trim(($line->notes ?? '')
                                ." | Surplus of {$line->variance}: receive the extra devices via the stock catalog."),
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
