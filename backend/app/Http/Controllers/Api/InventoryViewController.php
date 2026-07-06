<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceUnitStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockItem;
use App\Support\InventoryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read views for the inventory module:
 *  - my:     "My Inventory" — the stock at the logged-in user's linked location.
 *  - itemSearch: find an item across locations ("where are all the Trochars?").
 */
class InventoryViewController extends Controller
{
    /** The default inventory page: stock at MY linked location. */
    public function my(Request $request): JsonResponse
    {
        $user = $request->user();
        $location = $user->location;

        if (! $location) {
            return response()->json([
                'location' => null,
                'items'    => [],
                'message'  => $user->isAdmin()
                    ? 'No personal location linked — use the search below to browse any location.'
                    : 'Your account is not linked to a location yet. Ask an administrator to link you.',
            ]);
        }

        return response()->json([
            'location' => new LocationResource($location->load('owner:id,name')),
            'items'    => InventoryPresenter::groupedAt($location, $request->query('q')),
        ]);
    }

    /**
     * Search a stock item across every location the user may see.
     * Returns each matching item with a per-location breakdown.
     */
    public function itemSearch(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $term = trim((string) $request->query('q', ''));
        if ($term === '') {
            return response()->json(['items' => []]);
        }

        $items = StockItem::query()->search($term)->orderBy('name')->limit(25)->get();

        $onHand = [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value];

        $payload = $items->map(function (StockItem $item) use ($onHand) {
            $byLocation = DeviceUnit::where('stock_item_id', $item->id)
                ->whereIn('status', $onHand)
                ->selectRaw('location_id, COUNT(*) as qty')
                ->groupBy('location_id')
                ->pluck('qty', 'location_id');

            $locations = Location::whereIn('id', $byLocation->keys())->get(['id', 'name', 'type']);

            return [
                'stock_item_id'    => $item->id,
                'name'             => $item->name,
                'catalogue_number' => $item->catalogue_number,
                'item_code'        => $item->item_code,
                'total'            => (int) $byLocation->sum(),
                'locations'        => $locations->map(fn ($l) => [
                    'location_id' => $l->id,
                    'name'        => $l->name,
                    'type'        => $l->type,
                    'quantity'    => (int) $byLocation[$l->id],
                ])->values(),
            ];
        });

        return response()->json(['items' => $payload]);
    }
}
