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
 * Admin Approval Centre — surfaces the work queues:
 *   - Pending transfers (incl. pending signatures + awaiting admin review)
 *   - Pending counts (incl. submitted variances awaiting review)
 *
 * Results are scoped: admins see everything; general users see only items
 * they are authorised to act on (their assigned hospitals / their stock).
 */
class ApprovalCentreController extends Controller
{
    public function transfers(Request $request)
    {
        $this->authorize('viewAny', Transfer::class);

        $user = $request->user();

        $query = Transfer::query()
            ->with(['hospital', 'requester', 'items', 'signatures'])
            ->whereIn('status', [
                TransferStatus::PendingApproval->value,
                TransferStatus::AwaitingSignature->value,
                TransferStatus::AwaitingAdminReview->value,
            ]);

        if (! $user->isAdmin()) {
            $hospitalIds = $user->assignedHospitalIds();
            $query->where(function ($q) use ($user, $hospitalIds) {
                $q->whereIn('hospital_id', $hospitalIds)
                    ->orWhere('from_holder_user_id', $user->id);
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
        $hospitalIds = $user->assignedHospitalIds();

        $transferQuery = Transfer::whereIn('status', [
            TransferStatus::PendingApproval->value,
            TransferStatus::AwaitingSignature->value,
            TransferStatus::AwaitingAdminReview->value,
        ]);

        if (! $user->isAdmin()) {
            $transferQuery->where(fn ($q) => $q->whereIn('hospital_id', $hospitalIds)
                ->orWhere('from_holder_user_id', $user->id));
        }

        $countQuery = StockCount::whereIn('status', [
            StockCountStatus::Submitted->value,
            StockCountStatus::Investigating->value,
        ]);
        if (! $user->isAdmin()) {
            $countQuery->where('assigned_to', $user->id);
        }

        return response()->json([
            'pending_transfers' => $transferQuery->count(),
            'pending_counts'    => $countQuery->count(),
            'unread_notifications' => $user->unreadNotifications()->count(),
        ]);
    }
}
