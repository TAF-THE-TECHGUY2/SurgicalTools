<?php

namespace App\Http\Controllers\Api;

use App\Enums\TransferStatus;
use App\Http\Controllers\Controller;
use App\Models\InventoryItem;
use App\Models\StockCount;
use App\Models\Transfer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function __construct()
    {
        // All report endpoints require the report.view permission.
    }

    protected function guard(Request $request): void
    {
        abort_unless($request->user()->can('report.view'), 403);
    }

    /** Stock on hand grouped by location / stock type. */
    public function inventory(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'by_location'   => InventoryItem::select('location', DB::raw('SUM(quantity) as units'), DB::raw('COUNT(*) as lines'))
                ->groupBy('location')->get(),
            'by_stock_type' => InventoryItem::select('stock_type', DB::raw('SUM(quantity) as units'))
                ->groupBy('stock_type')->get(),
            'by_status'     => InventoryItem::select('status', DB::raw('COUNT(*) as lines'))
                ->groupBy('status')->get(),
        ]);
    }

    /** Transfer throughput by status / type / month. */
    public function transfers(Request $request): JsonResponse
    {
        $this->guard($request);

        return response()->json([
            'by_status' => Transfer::select('status', DB::raw('COUNT(*) as total'))->groupBy('status')->get(),
            'by_type'   => Transfer::select('type', DB::raw('COUNT(*) as total'))->groupBy('type')->get(),
            'completed_by_hospital' => Transfer::select('hospital_id', DB::raw('COUNT(*) as total'))
                ->where('status', TransferStatus::Completed->value)
                ->whereNotNull('hospital_id')
                ->with('hospital:id,name')
                ->groupBy('hospital_id')->get(),
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

    /** Items expiring within the configured windows. */
    public function expiry(Request $request): JsonResponse
    {
        $this->guard($request);

        $windows = config('surgical.expiry');

        return response()->json([
            'critical' => InventoryItem::expiringWithin($windows['critical'])->get(['id', 'ref_code', 'description', 'lot_number', 'expiry_date', 'quantity']),
            'high'     => InventoryItem::expiringWithin($windows['high'])->get(['id', 'ref_code', 'description', 'lot_number', 'expiry_date', 'quantity']),
            'warning'  => InventoryItem::expiringWithin($windows['warning'])->get(['id', 'ref_code', 'description', 'lot_number', 'expiry_date', 'quantity']),
        ]);
    }

    /** Per-rep activity: transfers requested and stock currently in boot. */
    public function repPerformance(Request $request): JsonResponse
    {
        $this->guard($request);

        $rows = DB::table('users')
            ->leftJoin('transfers', 'transfers.requested_by', '=', 'users.id')
            ->select('users.id', 'users.name',
                DB::raw('COUNT(transfers.id) as transfers_requested'),
                DB::raw("SUM(CASE WHEN transfers.status = 'completed' THEN 1 ELSE 0 END) as transfers_completed"))
            ->groupBy('users.id', 'users.name')
            ->orderByDesc('transfers_requested')
            ->limit(50)->get();

        return response()->json($rows);
    }
}
