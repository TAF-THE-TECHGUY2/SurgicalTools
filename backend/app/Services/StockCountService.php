<?php

namespace App\Services;

use App\Enums\StockCountStatus;
use App\Models\InventoryItem;
use App\Models\StockCount;
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
     * Admin creates a count request. We snapshot the *expected* quantities from
     * the matching inventory holdings so variance can be computed later.
     */
    public function create(array $data, User $admin): StockCount
    {
        return DB::transaction(function () use ($data, $admin) {
            $count = StockCount::create([
                'reference'      => ReferenceGenerator::next(StockCount::class, 'reference', 'SC'),
                'status'         => StockCountStatus::Requested->value,
                'location'       => $data['location'] ?? null,
                'hospital_id'    => $data['hospital_id'] ?? null,
                'holder_user_id' => $data['holder_user_id'] ?? null,
                'requested_by'   => $admin->id,
                'assigned_to'    => $data['assigned_to'] ?? null,
                'notes'          => $data['notes'] ?? null,
            ]);

            $holdings = $this->scopeHoldings($data)->get();

            foreach ($holdings as $item) {
                $count->items()->create([
                    'inventory_item_id' => $item->id,
                    'ref_code'          => $item->ref_code,
                    'description'       => $item->description,
                    'lot_number'        => $item->lot_number,
                    'expected_quantity' => $item->quantity,
                ]);
            }

            $this->notifications->stockCountRequested($count);

            return $count->fresh('items');
        });
    }

    /** Rep submits actual counted quantities (variance auto-computed in model). */
    public function submit(StockCount $count, array $lines, ?array $meta = null): StockCount
    {
        return DB::transaction(function () use ($count, $lines, $meta) {
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

            return $count->fresh('items');
        });
    }

    /**
     * Admin review. action = approve|investigate. When approving, any non-zero
     * variances are posted to inventory as count corrections so on-hand matches
     * the physical count.
     */
    public function review(StockCount $count, User $admin, string $action): StockCount
    {
        return DB::transaction(function () use ($count, $admin, $action) {
            if ($action === 'approve') {
                foreach ($count->items as $line) {
                    if ($line->variance && $line->inventory_item_id) {
                        $item = InventoryItem::find($line->inventory_item_id);
                        if ($item) {
                            $this->inventory->adjust(
                                $item,
                                (int) $line->variance,
                                "Stock count {$count->reference} correction",
                                $admin->id,
                                $count,
                            );
                        }
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

    protected function scopeHoldings(array $data)
    {
        return InventoryItem::query()
            ->when($data['location'] ?? null, fn ($q, $v) => $q->where('location', $v))
            ->when($data['hospital_id'] ?? null, fn ($q, $v) => $q->where('hospital_id', $v))
            ->when($data['holder_user_id'] ?? null, fn ($q, $v) => $q->where('holder_user_id', $v));
    }
}
