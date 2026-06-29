<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PreferenceCardResource;
use App\Models\Doctor;
use App\Models\PreferenceCard;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class PreferenceCardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', Doctor::class);

        $cards = PreferenceCard::query()
            ->with(['doctor', 'items'])
            ->when($request->filled('doctor_id'), fn ($q) => $q->where('doctor_id', $request->doctor_id))
            ->when($request->filled('q'), fn ($q) => $q->where('procedure_name', 'like', '%'.$request->q.'%'))
            ->latest()
            ->paginate($request->integer('per_page', 25));

        return PreferenceCardResource::collection($cards);
    }

    public function show(PreferenceCard $preferenceCard)
    {
        $this->authorize('view', $preferenceCard->doctor);

        return new PreferenceCardResource($preferenceCard->load(['doctor.hospitals', 'items']));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Doctor::class);

        $data = $this->validateData($request);
        $card = PreferenceCard::create($data);
        $this->syncItems($card, $request->input('items', []));

        return (new PreferenceCardResource($card->load('items')))->response()->setStatusCode(201);
    }

    public function update(Request $request, PreferenceCard $preferenceCard)
    {
        $this->authorize('update', $preferenceCard->doctor);

        $preferenceCard->update($this->validateData($request));
        if ($request->has('items')) {
            $preferenceCard->items()->delete();
            $this->syncItems($preferenceCard, $request->input('items', []));
        }

        return new PreferenceCardResource($preferenceCard->fresh('items'));
    }

    public function destroy(PreferenceCard $preferenceCard)
    {
        $this->authorize('delete', $preferenceCard->doctor);

        $preferenceCard->delete();

        return response()->json(['message' => 'Preference card deleted.']);
    }

    /** Render a printable PDF of the preference card. */
    public function print(PreferenceCard $preferenceCard)
    {
        $this->authorize('view', $preferenceCard->doctor);

        $preferenceCard->load(['doctor', 'items']);

        return Pdf::loadView('pdf.preference-card', ['card' => $preferenceCard])
            ->setPaper('a4')
            ->download("preference-card-{$preferenceCard->id}.pdf");
    }

    protected function validateData(Request $request): array
    {
        return $request->validate([
            'doctor_id'       => ['required', 'exists:doctors,id'],
            'procedure_name'  => ['required', 'string', 'max:255'],
            'notes'           => ['nullable', 'string'],
            'preferred_sizes' => ['nullable', 'array'],
            'is_active'       => ['boolean'],
        ]);
    }

    protected function syncItems(PreferenceCard $card, array $items): void
    {
        foreach ($items as $item) {
            $card->items()->create([
                'ref_code'       => $item['ref_code'] ?? null,
                'description'    => $item['description'],
                'preferred_size' => $item['preferred_size'] ?? null,
                'quantity'       => $item['quantity'] ?? 1,
                'notes'          => $item['notes'] ?? null,
            ]);
        }
    }
}
