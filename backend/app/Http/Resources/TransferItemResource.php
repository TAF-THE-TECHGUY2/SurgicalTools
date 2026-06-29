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
            'ref_code'          => $this->ref_code,
            'description'       => $this->description,
            'lot_number'        => $this->lot_number,
            'quantity'          => $this->quantity,
            'expiry_date'       => optional($this->expiry_date)->toDateString(),
            'unit_price'        => $this->unit_price,
        ];
    }
}
