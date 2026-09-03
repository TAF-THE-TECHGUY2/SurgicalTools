<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'inventory_item_id' => $this->inventory_item_id,
            'device_unit_id'    => $this->device_unit_id,
            'serial_number'     => $this->serial_number,
            'ref_code'          => $this->ref_code,
            'description'       => $this->description,
            'lot_number'        => $this->lot_number,
            'quantity'          => $this->quantity,
            'expiry_date'       => optional($this->expiry_date)->toDateString(),
            'unit_price'        => $this->unit_price,
            // Orange row: scanned stock the dispatch list did not authorise.
            'is_transfer_adjustment' => (bool) $this->is_transfer_adjustment,
            'adjustment_type'        => $this->adjustment_type,
            'expected_lot_number'    => $this->expected_lot_number,
        ];
    }
}
