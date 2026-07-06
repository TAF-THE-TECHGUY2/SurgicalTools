<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TransferResource;
use App\Models\Transfer;
use App\Services\TransferService;
use App\Support\SignatureStorage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class TransferController extends Controller
{
    public function __construct(protected TransferService $service) {}

    public function index(Request $request)
    {
        $this->authorize('viewAny', Transfer::class);

        $user = $request->user();

        $query = Transfer::query()
            ->with(['fromLocation', 'toLocation', 'requester', 'items'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('location_id'), fn ($q) => $q->where(fn ($s) => $s
                ->where('from_location_id', $request->location_id)
                ->orWhere('to_location_id', $request->location_id)))
            ->latest();

        // Non-admins see transfers relevant to them: their own requests, or
        // moves touching their linked location / assigned hospitals.
        if (! $user->isAdmin()) {
            $hospitalIds = $user->assignedHospitalIds();
            $query->where(function ($q) use ($user, $hospitalIds) {
                $q->where('requested_by', $user->id)
                    ->orWhere('from_location_id', $user->location_id)
                    ->orWhere('to_location_id', $user->location_id)
                    ->orWhereIn('hospital_id', $hospitalIds);
            });
        }

        return TransferResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function show(Transfer $transfer)
    {
        $this->authorize('view', $transfer);

        return new TransferResource($transfer->load([
            'fromLocation.owner:id,name', 'toLocation.hospital', 'requester', 'approver',
            'items', 'signatures', 'documents',
        ]));
    }

    /**
     * Create a transfer request (spec steps 1–8): from → units → to →
     * signature → pending approval.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'from_location_id' => ['required', 'exists:locations,id'],
            'to_location_id'   => ['required', 'exists:locations,id', 'different:from_location_id'],
            'unit_ids'         => ['required', 'array', 'min:1'],
            'unit_ids.*'       => ['integer', 'exists:device_units,id'],
            'signature'        => ['required', 'string'], // base64 PNG from the pad
            'notes'            => ['nullable', 'string', 'max:2000'],
        ]);

        abort_unless($request->user()->can('transfer.create'), 403);

        $path = SignatureStorage::storeBase64($data['signature'], 'request-'.now()->format('YmdHis'));

        $transfer = $this->service->request([
            'from_location_id' => $data['from_location_id'],
            'to_location_id'   => $data['to_location_id'],
            'unit_ids'         => $data['unit_ids'],
            'signature_path'   => $path,
            'signer_name'      => $request->user()->name,
            'ip_address'       => $request->ip(),
            'notes'            => $data['notes'] ?? null,
        ], $request->user());

        return (new TransferResource($transfer))->response()->setStatusCode(201);
    }

    /** Approve — moves the devices and completes the transfer. */
    public function approve(Request $request, Transfer $transfer)
    {
        $this->authorize('approve', $transfer);

        $override = $request->boolean('override');
        if ($override) {
            $this->authorize('override', $transfer);
        }

        return new TransferResource($this->service->approve($transfer, $request->user(), $override));
    }

    public function reject(Request $request, Transfer $transfer)
    {
        $this->authorize('reject', $transfer);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);

        return new TransferResource(
            $this->service->reject($transfer, $request->user(), $data['reason'] ?? null)
        );
    }

    /** Stream the generated transfer/delivery-note PDF. */
    public function downloadPdf(Transfer $transfer)
    {
        $this->authorize('view', $transfer);

        $doc = $transfer->documents()
            ->whereIn('type', ['transfer_pdf', 'delivery_note'])
            ->latest()->firstOrFail();

        return Storage::disk($doc->disk)->download($doc->path, $doc->original_name);
    }
}
