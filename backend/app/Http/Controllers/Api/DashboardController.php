<?php

namespace App\Http\Controllers\Api;

use App\Enums\StockCountStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\StockCount;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $criticalDays = (int) config('surgical.expiry.critical');
        $warningDays = (int) config('surgical.expiry.warning');

        return response()->json([
            'inventory' => [
                'total_items'    => InventoryItem::count(),
                'total_units'    => (int) InventoryItem::sum('quantity'),
                'low_stock'      => InventoryItem::lowStock()->count(),
                'expiring_soon'  => InventoryItem::expiringWithin($warningDays)->count(),
                'expiring_critical' => InventoryItem::expiringWithin($criticalDays)->count(),
            ],
            'transfers' => [
                'open'                  => Transfer::whereIn('status', TransferStatus::open())->count(),
                'pending_approval'      => Transfer::where('status', TransferStatus::PendingApproval->value)->count(),
                'awaiting_admin_review' => Transfer::where('status', TransferStatus::AwaitingAdminReview->value)->count(),
                'completed_this_month'  => Transfer::where('status', TransferStatus::Completed->value)
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)->count(),
            ],
            'stock_counts' => [
                'open'      => StockCount::whereNotIn('status', [
                    StockCountStatus::Approved->value,
                ])->count(),
                'submitted' => StockCount::where('status', StockCountStatus::Submitted->value)->count(),
            ],
            'hospitals' => [
                'total'       => Hospital::count(),
                'assigned'    => $user->isAdmin() ? Hospital::count() : count($user->assignedHospitalIds()),
            ],
            'recent_transfers' => \App\Http\Resources\TransferResource::collection(
                Transfer::with(['hospital', 'requester'])->latest()->limit(5)->get()
            ),
            'recent_movements' => \App\Http\Resources\StockMovementResource::collection(
                \App\Models\StockMovement::with('performedBy')->latest('moved_at')->limit(8)->get()
            ),
        ]);
    }
}
