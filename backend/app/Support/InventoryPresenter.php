<?php

namespace App\Support;

use App\Enums\DeviceUnitStatus;
use App\Http\Resources\DeviceUnitResource;
use App\Models\Location;
use App\Models\StockItem;

/**
 * Shapes the grouped inventory payload used by "My Inventory", the transfer
 * wizard's stock step, and rep search: stock items at a location with their
 * individual units (serial / lot / expiry) nested for expansion.
 */
class InventoryPresenter
{
    protected const ON_HAND = [
        DeviceUnitStatus::Available->value,
        DeviceUnitStatus::PendingTransfer->value,
    ];

    public static function groupedAt(Location $location, ?string $q = null): array
    {
        $scope = fn ($units) => $units
            ->where('location_id', $location->id)
            ->whereIn('status', self::ON_HAND);

        $items = StockItem::query()
            ->whereHas('units', $scope)
            // Search matches the item (name / catalogue no / REF) or any of its
            // units at this location by lot or serial number.
            ->when($q, function ($query) use ($q, $scope) {
                $like = '%'.$q.'%';
                $query->where(function ($w) use ($like, $scope) {
                    $w->where('name', 'like', $like)
                        ->orWhere('catalogue_number', 'like', $like)
                        ->orWhere('item_code', 'like', $like)
                        ->orWhereHas('units', fn ($u) => $scope($u)
                            ->where(fn ($x) => $x->where('lot_number', 'like', $like)
                                ->orWhere('serial_number', 'like', $like)));
                });
            })
            ->with(['units' => fn ($units) => $scope($units)->orderByRaw('expiry_date IS NULL, expiry_date ASC')])
            ->orderBy('name')
            ->get();

        return $items->map(function (StockItem $item) {
            $available = $item->units->where('status', DeviceUnitStatus::Available)->count();
            $pending = $item->units->where('status', DeviceUnitStatus::PendingTransfer)->count();

            return [
                'stock_item_id'    => $item->id,
                'name'             => $item->name,
                'catalogue_number' => $item->catalogue_number,
                'item_code'        => $item->item_code,
                'quantity'         => $item->units->count(), // physically on hand
                'available'        => $available,            // selectable for transfer
                'pending_out'      => $pending,              // reserved in a pending transfer
                'units'            => DeviceUnitResource::collection($item->units),
            ];
        })->values()->all();
    }
}
