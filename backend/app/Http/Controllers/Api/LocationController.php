<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\LocationResource;
use App\Models\Location;
use App\Support\InventoryPresenter;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocationController extends Controller
{
    /** All active locations — the "From / To" entity pickers. */
    public function index(Request $request)
    {
        $locations = Location::query()
            ->with(['owner:id,name', 'hospital:id,name'])
            ->withCount('units')
            ->when(! $request->boolean('include_inactive'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->orderBy('name')
            ->get();

        return LocationResource::collection($locations);
    }

    public function show(Location $location)
    {
        return new LocationResource($location->load(['owner:id,name', 'hospital'])->loadCount('units'));
    }

    /** Grouped stock at a location: items → expandable serial/lot/expiry units. */
    public function inventory(Request $request, Location $location)
    {
        abort_unless($request->user()->can('inventory.view'), 403);

        return response()->json([
            'location' => new LocationResource($location->load('owner:id,name')),
            'items'    => InventoryPresenter::groupedAt($location, $request->query('q')),
        ]);
    }

    public function store(Request $request)
    {
        $this->authorize('create', Location::class);

        $location = Location::create($this->validateData($request));

        return (new LocationResource($location))->response()->setStatusCode(201);
    }

    public function update(Request $request, Location $location)
    {
        $this->authorize('update', $location);

        $location->update($this->validateData($request, $location));

        return new LocationResource($location->fresh(['owner:id,name', 'hospital']));
    }

    public function destroy(Location $location)
    {
        $this->authorize('delete', $location);

        if ($location->units()->whereIn('status', ['available', 'pending_transfer'])->exists()) {
            return response()->json([
                'message' => 'This location still holds stock. Transfer the devices out before archiving it.',
            ], 422);
        }

        $location->delete();

        return response()->json(['message' => 'Location archived.']);
    }

    protected function validateData(Request $request, ?Location $location = null): array
    {
        return $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'code'          => ['nullable', 'string', 'max:50', Rule::unique('locations', 'code')->ignore($location?->id)],
            'type'          => ['required', Rule::in(['hospital', 'boot', 'office', 'warehouse', 'other'])],
            'hospital_id'   => ['nullable', 'exists:hospitals,id'],
            'owner_user_id' => ['nullable', 'exists:users,id'],
            'is_active'     => ['boolean'],
        ]);
    }
}
