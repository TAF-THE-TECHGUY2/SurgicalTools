<?php

namespace App\Services;

use App\Enums\DeviceUnitStatus;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Owns mutations to physical stock in the unit-level model. Every unit that
 * enters, moves, or leaves the system gets a stock_movements ledger entry.
 */
class InventoryService
{
    public function __construct(protected NotificationService $notifications) {}

    /**
     * Receive new devices into a location (goods receipt / initial capture).
     * $units: [{serial_number?, lot_number?, expiry_date?}, ...]
     *
     * @return \Illuminate\Support\Collection<int, DeviceUnit>
     */
    public function receiveUnits(StockItem $item, Location $location, array $units, ?int $userId = null)
    {
        return DB::transaction(function () use ($item, $location, $units, $userId) {
            $created = collect();

            foreach ($units as $data) {
                $unit = $item->units()->create([
                    'serial_number' => $data['serial_number'] ?? null,
                    'lot_number'    => $data['lot_number'] ?? null,
                    'expiry_date'   => $data['expiry_date'] ?? null,
                    'location_id'   => $location->id,
                    'status'        => DeviceUnitStatus::Available->value,
                ]);

                $this->log([
                    'device_unit_id'  => $unit->id,
                    'ref_code'        => $item->catalogue_number ?? (string) $item->id,
                    'lot_number'      => $unit->lot_number,
                    'quantity'        => 1,
                    'movement_type'   => 'receipt',
                    'to_location'     => $location->name,
                    'to_location_id'  => $location->id,
                    'performed_by'    => $userId,
                    'notes'           => 'Stock received'.($unit->serial_number ? " · SN {$unit->serial_number}" : ''),
                ]);

                $created->push($unit);
            }

            return $created;
        });
    }

    /**
     * Write off $count units of an item at a location (e.g. an approved stock
     * count found them missing). Oldest expiry first.
     */
    public function markUnitsMissing(StockItem $item, Location $location, int $count, string $reason, ?int $userId = null, $reference = null): int
    {
        return DB::transaction(function () use ($item, $location, $count, $reason, $userId, $reference) {
            $units = DeviceUnit::where('stock_item_id', $item->id)
                ->where('location_id', $location->id)
                ->where('status', DeviceUnitStatus::Available->value)
                ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
                ->limit($count)
                ->lockForUpdate()
                ->get();

            foreach ($units as $unit) {
                $unit->update(['status' => DeviceUnitStatus::Missing->value]);

                $this->log([
                    'device_unit_id'   => $unit->id,
                    'ref_code'         => $item->catalogue_number ?? (string) $item->id,
                    'lot_number'       => $unit->lot_number,
                    'quantity'         => 1,
                    'movement_type'    => 'count_correction',
                    'from_location'    => $location->name,
                    'from_location_id' => $location->id,
                    'reference_type'   => $reference ? $reference::class : null,
                    'reference_id'     => $reference?->id,
                    'performed_by'     => $userId,
                    'notes'            => $reason,
                ]);
            }

            $this->maybeAlertLowStock($item, $location);

            return $units->count();
        });
    }

    /** Archive a single unit (damaged / used / removed) with a ledger entry. */
    public function archiveUnit(DeviceUnit $unit, string $reason, ?int $userId = null): void
    {
        DB::transaction(function () use ($unit, $reason, $userId) {
            $unit->loadMissing(['stockItem', 'location']);
            $unit->update(['status' => DeviceUnitStatus::Archived->value]);

            $this->log([
                'device_unit_id'   => $unit->id,
                'ref_code'         => $unit->stockItem?->catalogue_number ?? (string) $unit->stock_item_id,
                'lot_number'       => $unit->lot_number,
                'quantity'         => 1,
                'movement_type'    => 'adjustment',
                'from_location'    => $unit->location?->name,
                'from_location_id' => $unit->location_id,
                'performed_by'     => $userId,
                'notes'            => $reason,
            ]);

            if ($unit->stockItem) {
                $this->maybeAlertLowStock($unit->stockItem, $unit->location);
            }
        });
    }

    public function log(array $attributes): StockMovement
    {
        $attributes['moved_at'] ??= now();

        return StockMovement::create($attributes);
    }

    /**
     * Real-time low-stock alert. With a $location, checks the stock remaining
     * at that specific place (e.g. the source a transfer just left); without,
     * checks the item's global on-hand total (daily sweep / procurement view).
     */
    public function maybeAlertLowStock(StockItem $item, ?Location $location = null): void
    {
        if ($item->min_threshold === null) {
            return;
        }

        $query = $item->units()
            ->whereIn('status', [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value]);

        if ($location) {
            $query->where('location_id', $location->id);
        }

        $onHand = $query->count();

        if ($onHand <= $item->min_threshold) {
            $where = $location ? " at {$location->name}" : '';
            $this->notifications->stockAlert(
                $item,
                'low_stock',
                'low_stock',
                "{$item->name} is low{$where}: {$onHand} unit(s) on hand (threshold {$item->min_threshold}).",
            );
        }
    }
}
