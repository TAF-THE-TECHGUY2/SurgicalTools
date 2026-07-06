<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceUnitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'stock_item_id'  => $this->stock_item_id,
            'serial_number'  => $this->serial_number,
            'lot_number'     => $this->lot_number,
            'expiry_date'    => optional($this->expiry_date)->toDateString(),
            'days_to_expiry' => $this->days_to_expiry,
            'status'         => $this->status?->value,
            'location_id'    => $this->location_id,
            'location'       => new LocationResource($this->whenLoaded('location')),
            'stock_item'     => new StockItemResource($this->whenLoaded('stockItem')),
        ];
    }
}
