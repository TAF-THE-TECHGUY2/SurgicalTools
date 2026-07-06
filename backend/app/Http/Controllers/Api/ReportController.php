<?php

namespace App\Http\Controllers\Api;

use App\Enums\DeviceUnitStatus;
use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\DeviceUnit;
use App\Models\StockCount;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    protected const ON_HAND = [
        'available', 'pending_transfer',
    ];

    protected function guard(Request $request): void
    {
        abort_unless($request->user()->can('report.view'), 403);
    }

    /** Stock on hand grouped by location / item / status. */
    public function inventory(Request $request): JsonResponse
    {
        $this->guard($request);

        $base = DB::table('device_units')
            ->whereIn('device_units.status', self::ON_HAND)
            ->whereNull('device_units.deleted_at');

        return response()->json([
            'by_location' => (clone $base)
                ->join('locations', 'locations.id', '=', 'device_units.location_id')
                ->select('locations.name as location', DB::raw('COUNT(*) as units'))
                ->groupBy('locations.name')->orderByDesc('units')->get(),
            'by_stock_type' => (clone $base)
                ->join('stock_items', 'stock_items.id', '=', 'device_units.stock_item_id')
                ->select('stock_items.name as stock_type', DB::raw('COUNT(*) as units'))
                ->groupBy('stock_items.name')->orderByDesc('units')->get(),
            'by_status' => DB::table('device_units')->whereNull('deleted_at')
                ->select('status', DB::raw('COUNT(*) as lines'))
                ->groupBy('status')->get(),
        ]);
    }

    /** Transfer throughput by status / route. */
    public function transfers(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'by_status' => Transfer::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get(),
            'by_type'   => Transfer::select('type', DB::raw('COUNT(*) as total'))->groupBy('type')->get(),
            'completed_by_hospital' => Transfer::select('to_location_id as hospital_id', DB::raw('COUNT(*) as total'))
                ->where('status', TransferStatus::Completed->value)
                ->whereNotNull('to_location_id')
                ->with(['toLocation:id,name'])
                ->groupBy('to_location_id')->get()
                ->map(fn ($row) => [
                    'hospital_id' => $row->hospital_id,
                    'total'       => $row->total,
                    'hospital'    => ['name' => $row->toLocation?->name],
                ]),
        ]);
    }

    /** Variance summary across submitted/approved counts. */
    public function variances(Request $request): JsonResponse
    {
        $this->guard($request);

        $counts = StockCount::with('items')->whereNotNull('submitted_at')->latest()->limit(50)->get();

        return response()->json($counts->map(fn ($c) => [
            'reference'      => $c->reference,
            'status'         => $c->status->value,
            'total_variance' => $c->total_variance,
            'lines'          => $c->items->count(),
            'submitted_at'   => $c->submitted_at,
        ]));
    }

    /** Device units expiring within the configured windows. */
    public function expiry(Request $request): JsonResponse
    {
        $this->guard($request);

        $windows = config('surgical.expiry');

        $rows = fn (int $days) => DeviceUnit::with(['stockItem:id,name,catalogue_number', 'location:id,name'])
            ->whereIn('status', [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->whereDate('expiry_date', '>=', now())
            ->orderBy('expiry_date')
            ->limit(100)->get()
            ->map(fn ($u) => [
                'id'          => $u->id,
                'ref_code'    => $u->stockItem?->catalogue_number,
                'description' => $u->stockItem?->name,
                'lot_number'  => $u->lot_number,
                'serial'      => $u->serial_number,
                'location'    => $u->location?->name,
                'expiry_date' => optional($u->expiry_date)->toDateString(),
                'quantity'    => 1,
            ]);

        return response()->json([
            'critical' => $rows($windows['critical']),
            'high'     => $rows($windows['high']),
            'warning'  => $rows($windows['warning']),
        ]);
    }

    /** Per-rep activity: transfers requested and approved. */
    public function repPerformance(Request $request): JsonResponse
    {
        $this->guard($request);

        $rows = DB::table('users')
            ->leftJoin('transfers', 'transfers.requested_by', '=', 'users.id')
            ->select('users.id', 'users.name',
                DB::raw('COUNT(transfers.id) as transfers_requested'),
                DB::raw("SUM(CASE WHEN transfers.status = 'completed' THEN 1 ELSE 0 END) as transfers_completed"))
            ->whereNull('users.deleted_at')
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('transfers_requested')
            ->limit(50)->get();

        return response()->json($rows);
    }
}
