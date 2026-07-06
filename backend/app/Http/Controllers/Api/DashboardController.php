<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceUnitStatus;
use App\Enums\StockCountStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockItem;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $criticalDays = (int) config('surgical.expiry.critical');
        $warningDays = (int) config('surgical.expiry.warning');

        $onHand = [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value];

        $expiringQuery = fn (int $days) => DeviceUnit::whereIn('status', $onHand)
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->whereDate('expiry_date', '>=', now());

        // Low stock: items whose on-hand unit count <= min_threshold.
        $lowStock = StockItem::whereNotNull('min_threshold')
            ->whereRaw(
                '(select count(*) from device_units du where du.stock_item_id = stock_items.id and du.status in (?, ?) and du.deleted_at is null) <= min_threshold',
                $onHand,
            )->count();

        return response()->json([
            'inventory' => [
                'total_items'       => StockItem::where('is_active', true)->count(),
                'total_units'       => DeviceUnit::whereIn('status', $onHand)->count(),
                'low_stock'         => $lowStock,
                'expiring_soon'     => $expiringQuery($warningDays)->count(),
                'expiring_critical' => $expiringQuery($criticalDays)->count(),
            ],
            'transfers' => [
                'open'                  => Transfer::where('status', TransferStatus::PendingApproval->value)->count(),
                'pending_approval'      => Transfer::where('status', TransferStatus::PendingApproval->value)->count(),
                'awaiting_admin_review' => 0,
                'completed_this_month'  => Transfer::where('status', TransferStatus::Completed->value)
                    ->whereMonth('completed_at', now()->month)
                    ->whereYear('completed_at', now()->year)->count(),
            ],
            'stock_counts' => [
                'open'      => StockCount::whereNotIn('status', [StockCountStatus::Approved->value])->count(),
                'submitted' => StockCount::where('status', StockCountStatus::Submitted->value)->count(),
            ],
            'hospitals' => [
                'total'    => Location::where('is_active', true)->count(),
                'assigned' => $user->location_id
                    ? (int) DeviceUnit::where('location_id', $user->location_id)->whereIn('status', $onHand)->count()
                    : Location::where('is_active', true)->count(),
            ],
            'my_inventory_units' => $user->location_id
                ? DeviceUnit::where('location_id', $user->location_id)->whereIn('status', $onHand)->count()
                : null,
            'recent_transfers' => \App\Http\Resources\TransferResource::collection(
                Transfer::with(['fromLocation', 'toLocation', 'requester'])->latest()->limit(5)->get()
            ),
            'recent_movements' => \App\Http\Resources\StockMovementResource::collection(
                \App\Models\StockMovement::with('performedBy')->latest('moved_at')->limit(8)->get()
            ),
            'stock_by_location' => DB::table('device_units')
                ->join('locations', 'locations.id', '=', 'device_units.location_id')
                ->whereIn('device_units.status', $onHand)
                ->whereNull('device_units.deleted_at')
                ->select('locations.name', DB::raw('COUNT(*) as units'))
                ->groupBy('locations.name')
                ->orderByDesc('units')
                ->get(),
        ]);
    }
}
