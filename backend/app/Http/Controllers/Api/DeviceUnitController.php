<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceUnitResource;
use App\Models\DeviceUnit;
use App\Services\InventoryService;
use Illuminate\Http\Request;

class DeviceUnitController extends Controller
{
    public function __construct(protected InventoryService $inventory) {}

    /** Correct a unit's serial / lot / expiry (data fixes, not movements). */
    public function update(Request $request, DeviceUnit $deviceUnit)
    {
        abort_unless($request->user()->can('inventory.manage'), 403);

        $data = $request->validate([
            'serial_number' => ['nullable', 'string', 'max:100'],
            'lot_number'    => ['nullable', 'string', 'max:100'],
            'expiry_date'   => ['nullable', 'date'],
        ]);

        $deviceUnit->update($data);

        return new DeviceUnitResource($deviceUnit->fresh(['stockItem', 'location']));
    }

    /** Write a unit off (damaged / used / removed) with a ledger entry. */
    public function destroy(Request $request, DeviceUnit $deviceUnit)
    {
        abort_unless($request->user()->can('inventory.manage'), 403);

        if ($deviceUnit->status->value === 'pending_transfer') {
            return response()->json([
                'message' => 'This device is reserved in a pending transfer. Approve or reject the transfer first.',
            ], 422);
        }

        $reason = (string) $request->input('reason', 'Removed from stock');
        $this->inventory->archiveUnit($deviceUnit, $reason, $request->user()->id);

        return response()->json(['message' => 'Device archived.']);
    }
}
