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

class StockCountService
{
    public function __construct(
        protected InventoryService $inventory,
        protected NotificationService $notifications,
    ) {}

    /**
     * Admin creates a count request for a location. Expected quantities are
     * snapshotted from the device units currently at that location, grouped by
     * stock item.
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

            $expected = DeviceUnit::where('location_id', $location->id)
                ->whereIn('status', [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value])
                ->select('stock_item_id', DB::raw('COUNT(*) as qty'))
                ->groupBy('stock_item_id')
                ->pluck('qty', 'stock_item_id');

            foreach ($expected as $stockItemId => $qty) {
                $item = StockItem::find($stockItemId);
                $count->items()->create([
                    'stock_item_id'     => $stockItemId,
                    'ref_code'          => $item?->item_code ?? $item?->catalogue_number ?? (string) $stockItemId,
                    'description'       => $item?->name,
                    'expected_quantity' => (int) $qty,
                ]);
            }

            $this->notifications->stockCountRequested($count);

            return $count->fresh('items');
        });
    }

    /** Rep submits actual counted quantities (variance auto-computed in model). */
    public function submit(StockCount $count, array $lines, ?array $meta = null): StockCount
    {
        return DB::transaction(function () use ($count, $lines) {
            foreach ($lines as $line) {
                $item = $count->items()->find($line['id'] ?? 0);
                if (! $item) {
                    continue;
                }
                $item->update([
                    'counted_quantity' => $line['counted_quantity'],
                    'photo_path'       => $line['photo_path'] ?? $item->photo_path,
                    'notes'            => $line['notes'] ?? $item->notes,
                ]);
            }

            $count->update([
                'status'       => StockCountStatus::Submitted->value,
                'submitted_at' => now(),
            ]);

            $this->notifications->stockCountSubmitted($count->fresh('items'));

            return $count->fresh('items');
        });
    }

    /**
     * Admin review. action = approve|investigate. On approve, negative
     * variances write off the missing units (oldest expiry first); positive
     * variances are flagged in the line notes for manual receipt (serials of
     * surplus devices must be captured explicitly — the system can't invent them).
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

                    $item = StockItem::find($line->stock_item_id);
                    if (! $item) {
                        continue;
                    }

                    if ($line->variance < 0) {
                        $this->inventory->markUnitsMissing(
                            $item,
                            $location,
                            abs((int) $line->variance),
                            "Stock count {$count->reference}: {$line->variance} variance",
                            $admin->id,
                            $count,
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
