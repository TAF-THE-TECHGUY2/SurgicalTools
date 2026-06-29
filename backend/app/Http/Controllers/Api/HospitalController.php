<?php

namespace App\Http\Controllers\Api;

use App\Enums\HospitalCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\HospitalResource;
use App\Models\Hospital;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class HospitalController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Hospital::class);

        $hospitals = Hospital::query()
            ->withCount('inventoryItems')
            ->with(['assignedRep', 'assignedRunner'])
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->when($request->filled('category'), fn ($q) => $q->where('category', $request->category))
            ->when($request->filled('region'), fn ($q) => $q->where('region', $request->region))
            // General users can opt to see only hospitals assigned to them.
            ->when($request->boolean('assigned_to_me'), function ($q) use ($request) {
                $ids = $request->user()->assignedHospitalIds();
                $q->whereIn('id', $ids);
            })
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return HospitalResource::collection($hospitals);
    }

    public function show(Hospital $hospital)
    {
        $this->authorize('view', $hospital);

        return new HospitalResource($hospital->load([
            'assignedRep', 'assignedRunner', 'contacts', 'doctors', 'users',
        ])->loadCount('inventoryItems'));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Hospital::class);

        $hospital = Hospital::create($this->validateData($request));

        $this->syncRelations($request, $hospital);

        return (new HospitalResource($hospital->load(['contacts', 'users', 'doctors'])))
            ->response()->setStatusCode(201);
    }

    public function update(Request $request, Hospital $hospital)
    {
        $this->authorize('update', $hospital);

        $hospital->update($this->validateData($request, $hospital));
        $this->syncRelations($request, $hospital);

        return new HospitalResource($hospital->fresh(['contacts', 'users', 'doctors']));
    }

    public function destroy(Hospital $hospital)
    {
        $this->authorize('delete', $hospital);

        $hospital->delete();

        return response()->json(['message' => 'Hospital archived.']);
    }

    protected function validateData(Request $request, ?Hospital $hospital = null): array
    {
        $id = $hospital?->id;

        return $request->validate([
            'name'               => ['required', 'string', 'max:255'],
            'code'               => ['nullable', 'string', 'max:50', Rule::unique('hospitals', 'code')->ignore($id)],
            'category'           => ['required', Rule::in(HospitalCategory::values())],
            'region'             => ['nullable', 'string', 'max:100'],
            'address'            => ['nullable', 'string'],
            'city'               => ['nullable', 'string', 'max:100'],
            'province'           => ['nullable', 'string', 'max:100'],
            'phone'              => ['nullable', 'string', 'max:50'],
            'email'              => ['nullable', 'email'],
            'assigned_rep_id'    => ['nullable', 'exists:users,id'],
            'assigned_runner_id' => ['nullable', 'exists:users,id'],
            'is_active'          => ['boolean'],
        ]);
    }

    /** Sync contacts, linked doctors and rep/runner assignments if provided. */
    protected function syncRelations(Request $request, Hospital $hospital): void
    {
        if ($request->has('contacts')) {
            $hospital->contacts()->delete();
            foreach ($request->input('contacts', []) as $c) {
                $hospital->contacts()->create([
                    'name'       => $c['name'] ?? 'Contact',
                    'role'       => $c['role'] ?? null,
                    'email'      => $c['email'] ?? null,
                    'phone'      => $c['phone'] ?? null,
                    'is_primary' => $c['is_primary'] ?? false,
                ]);
            }
        }

        if ($request->has('doctor_ids')) {
            $hospital->doctors()->sync($request->input('doctor_ids', []));
        }

        // Link reps/runners to login accounts: [{user_id, role}]
        if ($request->has('assignments')) {
            $sync = [];
            foreach ($request->input('assignments', []) as $a) {
                $sync[$a['user_id']] = ['role' => $a['role'] ?? 'rep'];
            }
            $hospital->users()->sync($sync);
        }
    }
}
