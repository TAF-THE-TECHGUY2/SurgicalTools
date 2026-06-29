<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreBootToHospitalRequest;
use App\Http\Requests\StoreSourceToBootRequest;
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
            ->with(['hospital', 'requester', 'approver', 'items'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->type))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->status))
            ->when($request->filled('hospital_id'), fn ($q) => $q->where('hospital_id', $request->hospital_id))
            ->latest();

        // Non-admins only see transfers relevant to them.
        if (! $user->isAdmin()) {
            $hospitalIds = $user->assignedHospitalIds();
            $query->where(function ($q) use ($user, $hospitalIds) {
                $q->where('requested_by', $user->id)
                    ->orWhere('to_holder_user_id', $user->id)
                    ->orWhere('from_holder_user_id', $user->id)
                    ->orWhereIn('hospital_id', $hospitalIds);
            });
        }

        return TransferResource::collection($query->paginate($request->integer('per_page', 20)));
    }

    public function show(Transfer $transfer)
    {
        $this->authorize('view', $transfer);

        return new TransferResource($transfer->load([
            'hospital', 'doctor', 'requester', 'approver', 'reviewer',
            'fromHolder', 'toHolder', 'items', 'signatures', 'documents',
        ]));
    }

    /** Create Transfer 1 (source → boot). */
    public function storeSourceToBoot(StoreSourceToBootRequest $request)
    {
        $transfer = $this->service->createSourceToBoot($request->validated(), $request->user());

        if ($request->boolean('submit')) {
            $this->service->submit($transfer);
        }

        return (new TransferResource($transfer->fresh(['items'])))
            ->response()->setStatusCode(201);
    }

    /** Create Transfer 2 (boot → hospital). */
    public function storeBootToHospital(StoreBootToHospitalRequest $request)
    {
        $transfer = $this->service->createBootToHospital($request->validated(), $request->user());

        if ($request->boolean('submit')) {
            $this->service->submit($transfer);
        }

        return (new TransferResource($transfer->fresh(['items', 'hospital'])))
            ->response()->setStatusCode(201);
    }

    public function submit(Transfer $transfer)
    {
        $this->authorize('update', $transfer);

        return new TransferResource($this->service->submit($transfer));
    }

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

    /** Capture the digital signature (base64 PNG from a signature pad). */
    public function sign(Request $request, Transfer $transfer)
    {
        $this->authorize('sign', $transfer);

        $data = $request->validate([
            'signer_name'   => ['required', 'string', 'max:255'],
            'signer_role'   => ['nullable', 'string', 'max:100'],
            'signature'     => ['required', 'string'], // data:image/png;base64,....
        ]);

        $path = SignatureStorage::storeBase64($data['signature'], $transfer->id);

        $transfer = $this->service->sign($transfer, [
            'signer_name'    => $data['signer_name'],
            'signer_role'    => $data['signer_role'] ?? 'hospital_controller',
            'signature_path' => $path,
            'ip_address'     => $request->ip(),
        ], $request->user());

        return new TransferResource($transfer->load(['items', 'signatures', 'documents']));
    }

    /** Admin final review (Transfer 2) — posts movement and completes. */
    public function review(Request $request, Transfer $transfer)
    {
        $this->authorize('review', $transfer);

        return new TransferResource($this->service->adminReview($transfer, $request->user()));
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
