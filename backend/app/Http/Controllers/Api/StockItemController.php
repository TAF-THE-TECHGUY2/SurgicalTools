<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceUnitResource;
use App\Http\Resources\StockItemResource;
use App\Models\Location;
use App\Models\StockItem;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class StockItemController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    public function index(Request $request)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $items = StockItem::query()
            ->search($request->query('q'))
            ->withCount('units')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return StockItemResource::collection($items);
    }

    public function show(Request $request, StockItem $stockItem)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        $stockItem->load(['units' => fn ($q) => $q
            ->with('location:id,name,type')
            ->when($request->filled('location_id'), fn ($u) => $u->where('location_id', $request->location_id))
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')]);

        return new StockItemResource($stockItem);
    }

    public function store(Request $request)
    {
        $this->authorize('create', StockItem::class);

        $item = StockItem::create($this->validateData($request));

        return (new StockItemResource($item))->response()->setStatusCode(201);
    }

    public function update(Request $request, StockItem $stockItem)
    {
        $this->authorize('update', $stockItem);

        $stockItem->update($this->validateData($request));

        return new StockItemResource($stockItem->fresh()->loadCount('units'));
    }

    public function destroy(StockItem $stockItem)
    {
        $this->authorize('delete', $stockItem);

        if ($stockItem->units()->whereIn('status', ['available', 'pending_transfer'])->exists()) {
            return response()->json([
                'message' => 'This item still has devices on hand. Archive or transfer them first.',
            ], 422);
        }

        $stockItem->delete();

        return response()->json(['message' => 'Stock item archived.']);
    }

    /** Receive new devices (serial/lot/expiry each) into a location. */
    public function receiveUnits(Request $request, StockItem $stockItem)
    {
        $this->authorize('update', $stockItem);

        $data = $request->validate([
            'location_id'            => ['required', 'exists:locations,id'],
            'units'                  => ['required', 'array', 'min:1', 'max:500'],
            'units.*.serial_number'  => ['nullable', 'string', 'max:100'],
            'units.*.lot_number'     => ['nullable', 'string', 'max:100'],
            'units.*.expiry_date'    => ['nullable', 'date'],
        ]);

        $created = $this->inventory->receiveUnits(
            $stockItem,
            Location::findOrFail($data['location_id']),
            $data['units'],
            $request->user()->id,
        );

        return DeviceUnitResource::collection($created)->response()->setStatusCode(201);
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'catalogue_number' => ['nullable', 'string', 'max:100'],
            'item_code'        => ['nullable', 'string', 'max:100'],
            'description'      => ['nullable', 'string'],
            'uom'              => ['nullable', 'string', 'max:30'],
            'unit_price'       => ['nullable', 'numeric', 'min:0'],
            'min_threshold'    => ['nullable', 'integer', 'min:0'],
            'is_active'        => ['boolean'],
        ]);
    }
}
