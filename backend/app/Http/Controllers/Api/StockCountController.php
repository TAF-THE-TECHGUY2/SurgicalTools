<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\StockCountResource;
use App\Models\StockCount;
use App\Services\StockCountService;
use App\Support\SignatureStorage;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StockCountController extends Controller
{
    public function __construct(protected StockCountService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', StockCount::class);

        $user = $request->user();

        $counts = StockCount::query()
            ->with(['hospital', 'requester', 'assignee', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when(! $user->isAdmin(), fn ($q) => $q->where(function ($sub) use ($user) {
                $sub->where('assigned_to', $user->id)->orWhere('requested_by', $user->id);
            }))
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return StockCountResource::collection($counts);
    }

    public function show(StockCount $stockCount)
    {
        $this->authorize('view', $stockCount);

        return new StockCountResource(
            $stockCount->load(['hospital', 'requester', 'assignee', 'items.inventoryItem'])
        );
    }

    /** Admin creates a count request (snapshots expected quantities). */
    public function store(Request $request)
    {
        $this->authorize('create', StockCount::class);

        $data = $request->validate([
            'location_id' => ['required', 'exists:locations,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'notes'       => ['nullable', 'string', 'max:1000'],
        ]);

        $count = $this->service->create($data, $request->user());

        return (new StockCountResource($count))->response()->setStatusCode(201);
    }

    /** Rep submits counted quantities. */
    public function submit(Request $request, StockCount $stockCount)
    {
        $this->authorize('capture', $stockCount);

        $data = $request->validate([
            'lines'                    => ['required', 'array', 'min:1'],
            'lines.*.id'               => ['required', 'integer', 'exists:stock_count_items,id'],
            'lines.*.counted_quantity' => ['required', 'integer', 'min:0'],
            'lines.*.notes'            => ['nullable', 'string'],
        ]);

        $count = $this->service->submit($stockCount, $data['lines']);

        return new StockCountResource($count);
    }

    /** Attach an evidence photo to a count line. */
    public function uploadPhoto(Request $request, StockCount $stockCount)
    {
        $this->authorize('capture', $stockCount);

        $data = $request->validate([
            'stock_count_item_id' => ['required', 'exists:stock_count_items,id'],
            'photo'               => ['required', 'image', 'max:8192'],
        ]);

        $path = SignatureStorage::storeUpload($data['photo'], "documents/counts/{$stockCount->id}");

        $stockCount->items()->where('id', $data['stock_count_item_id'])
            ->update(['photo_path' => $path]);

        return response()->json(['path' => $path]);
    }

    /** Admin review: approve (apply variances) or investigate. */
    public function review(Request $request, StockCount $stockCount)
    {
        $this->authorize('review', $stockCount);

        $data = $request->validate([
            'action' => ['required', Rule::in(['approve', 'investigate'])],
        ]);

        $count = $this->service->review($stockCount, $request->user(), $data['action']);

        return new StockCountResource($count);
    }
}
