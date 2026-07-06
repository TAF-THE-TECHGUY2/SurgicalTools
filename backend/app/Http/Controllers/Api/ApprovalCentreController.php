<?php

namespace App\Http\Controllers\Api;

use App\Enums\StockCountStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\StockCountResource;
use App\Http\Resources\TransferResource;
use App\Models\StockCount;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Approval Centre — the work queues:
 *   - Pending transfer requests (with units, signatures, requester)
 *   - Submitted stock counts awaiting review
 * Admins see everything; other users see items they can act on.
 */
class ApprovalCentreController extends Controller
{
    public function transfers(Request $request)
    {
        $this->authorize('viewAny', Transfer::class);

        $user = $request->user();

        $query = Transfer::query()
            ->with(['fromLocation', 'toLocation', 'requester', 'items', 'signatures'])
            ->where('status', TransferStatus::PendingApproval->value);

        if (! $user->isAdmin()) {
            $hospitalIds = $user->assignedHospitalIds();
            $query->where(function ($q) use ($user, $hospitalIds) {
                $q->where('from_location_id', $user->location_id)
                    ->orWhereIn('hospital_id', $hospitalIds);
            });
        }

        return TransferResource::collection($query->latest()->paginate($request->integer('per_page', 20)));
    }

    public function counts(Request $request)
    {
        $this->authorize('viewAny', StockCount::class);

        $query = StockCount::query()
            ->with(['hospital', 'assignee', 'items'])
            ->whereIn('status', [
                StockCountStatus::Submitted->value,
                StockCountStatus::UnderReview->value,
                StockCountStatus::Investigating->value,
            ]);

        if (! $request->user()->isAdmin()) {
            $query->where('assigned_to', $request->user()->id);
        }

        return StockCountResource::collection($query->latest()->paginate($request->integer('per_page', 20)));
    }

    /** Badge counts for the navigation. */
    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $transferQuery = Transfer::where('status', TransferStatus::PendingApproval->value);
        if (! $user->isAdmin()) {
            $hospitalIds = $user->assignedHospitalIds();
            $transferQuery->where(fn ($q) => $q->where('from_location_id', $user->location_id)
                ->orWhereIn('hospital_id', $hospitalIds));
        }

        $countQuery = StockCount::whereIn('status', [
            StockCountStatus::Submitted->value,
            StockCountStatus::Investigating->value,
        ]);
        if (! $user->isAdmin()) {
            $countQuery->where('assigned_to', $user->id);
        }

        return response()->json([
            'pending_transfers'    => $transferQuery->count(),
            'pending_counts'       => $countQuery->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
