<?php

namespace App\Http\Controllers\Api;

use App\Enums\DoctorSpecialty;
use App\Http\Controllers\Controller;
use App\Http\Resources\DoctorResource;
use App\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Doctor::class);

        $doctors = Doctor::query()
            ->with('hospitals')
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->q.'%'))
            ->when($request->filled('specialty'), fn ($q) => $q->where('specialty', $request->specialty))
            ->when($request->filled('hospital_id'), fn ($q) => $q->whereHas('hospitals',
                fn ($h) => $h->where('hospitals.id', $request->hospital_id)))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 25));

        return DoctorResource::collection($doctors);
    }

    public function show(Doctor $doctor)
    {
        $this->authorize('view', $doctor);

        return new DoctorResource($doctor->load(['hospitals', 'preferenceCards.items']));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Doctor::class);

        $doctor = Doctor::create($this->validateData($request));

        if ($request->has('hospital_ids')) {
            $doctor->hospitals()->sync($request->input('hospital_ids', []));
        }

        return (new DoctorResource($doctor->load('hospitals')))->response()->setStatusCode(201);
    }

    public function update(Request $request, Doctor $doctor)
    {
        $this->authorize('update', $doctor);

        $doctor->update($this->validateData($request));

        if ($request->has('hospital_ids')) {
            $doctor->hospitals()->sync($request->input('hospital_ids', []));
        }

        return new DoctorResource($doctor->fresh('hospitals'));
    }

    public function destroy(Doctor $doctor)
    {
        $this->authorize('delete', $doctor);

        $doctor->delete();

        return response()->json(['message' => 'Doctor archived.']);
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'name'                  => ['required', 'string', 'max:255'],
            'age'                   => ['nullable', 'integer', 'min:18', 'max:120'],
            'specialty'             => ['nullable', Rule::in(DoctorSpecialty::values())],
            'operating_days'        => ['nullable', 'array'],
            'operating_days.*'      => ['string'],
            'equipment_used'        => ['nullable', 'array'],
            'procedure_preferences' => ['nullable', 'string'],
            'notes'                 => ['nullable', 'string'],
            'phone'                 => ['nullable', 'string', 'max:50'],
            'email'                 => ['nullable', 'email'],
            'is_active'             => ['boolean'],
        ]);
    }
}
