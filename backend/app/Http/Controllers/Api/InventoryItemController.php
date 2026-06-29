<?php

namespace App\Http\Controllers\Api;

use App\Enums\StockLocation;
use App\Enums\StockStatus;
use App\Enums\StockType;
use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryItemResource;
use App\Models\InventoryItem;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class InventoryItemController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', InventoryItem::class);

        $items = InventoryItem::query()
            ->with(['hospital', 'holder'])
            ->search($request->query('q'))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('location'), fn ($q) => $q->where('location', $request->location))
            ->when($request->filled('stock_type'), fn ($q) => $q->where('stock_type', $request->stock_type))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
            ->when($request->filled('holder_user_id'), fn ($q) => $q->where('holder_user_id', $request->holder_user_id))
            ->when($request->boolean('low_stock'), fn ($q) => $q->lowStock())
            ->when($request->filled('expiring_days'), fn ($q) => $q->expiringWithin($request->integer('expiring_days')))
            ->orderBy($request->query('sort', 'ref_code'), $request->query('direction', 'asc'))
            ->paginate($request->integer('per_page', 25));

        return InventoryItemResource::collection($items);
    }

    public function show(InventoryItem $inventoryItem)
    {
        $this->authorize('view', $inventoryItem);

        return new InventoryItemResource(
            $inventoryItem->load(['hospital', 'holder', 'movements.performedBy'])
        );
    }

    public function store(Request $request)
    {
        $this->authorize('create', InventoryItem::class);

        $data = $this->validateData($request);
        $item = InventoryItem::create($data);

        return (new InventoryItemResource($item))->response()->setStatusCode(201);
    }

    public function update(Request $request, InventoryItem $inventoryItem, \App\Services\InventoryService $inventory)
    {
        $this->authorize('update', $inventoryItem);

        $data = $this->validateData($request, $inventoryItem);

        // Quantity changes must flow through the ledger, not a silent overwrite.
        $newQty = (int) $data['quantity'];
        $oldQty = (int) $inventoryItem->quantity;
        unset($data['quantity']);

        $inventoryItem->update($data);

        if ($newQty !== $oldQty) {
            $inventory->adjust(
                $inventoryItem,
                $newQty - $oldQty,
                'Manual stock adjustment',
                $request->user()->id,
            );
        }

        return new InventoryItemResource($inventoryItem->fresh(['hospital', 'holder']));
    }

    public function destroy(InventoryItem $inventoryItem)
    {
        $this->authorize('delete', $inventoryItem);

        $inventoryItem->delete();

        return response()->json(['message' => 'Inventory item archived.']);
    }

    /** Full movement history for a single holding. */
    public function movements(InventoryItem $inventoryItem)
    {
        $this->authorize('view', $inventoryItem);

        return \App\Http\Resources\StockMovementResource::collection(
            $inventoryItem->movements()->with('performedBy')->paginate(50)
        );
    }

    protected function validateData(Request $request, ?InventoryItem $item = null): array
    {
        return $request->validate([
            'ref_code'       => ['required', 'string', 'max:100'],
            'description'    => ['required', 'string', 'max:255'],
            'lot_number'     => ['nullable', 'string', 'max:100'],
            'quantity'       => ['required', 'integer', 'min:0'],
            'expiry_date'    => ['nullable', 'date'],
            'stock_type'     => ['required', Rule::in(StockType::values())],
            'location'       => ['required', Rule::in(StockLocation::values())],
            'status'         => ['required', Rule::in(StockStatus::values())],
            'hospital_id'    => ['nullable', 'exists:hospitals,id'],
            'holder_user_id' => ['nullable', 'exists:users,id'],
            'min_threshold'  => ['nullable', 'integer', 'min:0'],
            'unit_price'     => ['nullable', 'numeric', 'min:0'],
            'barcode'        => ['nullable', 'string', 'max:100'],
            'uom'            => ['nullable', 'string', 'max:30'],
        ]);
    }
}
