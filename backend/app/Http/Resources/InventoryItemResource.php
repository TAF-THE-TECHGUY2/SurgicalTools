<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'ref_code'       => $this->ref_code,
            'description'    => $this->description,
            'lot_number'     => $this->lot_number,
            'quantity'       => $this->quantity,
            'expiry_date'    => optional($this->expiry_date)->toDateString(),
            'days_to_expiry' => $this->days_to_expiry,
            'stock_type'     => $this->stock_type?->value,
            'location'       => $this->location?->value,
            'status'         => $this->status?->value,
            'is_low_stock'   => $this->is_low_stock,
            'min_threshold'  => $this->min_threshold,
            'unit_price'     => $this->unit_price,
            'barcode'        => $this->barcode,
            'uom'            => $this->uom,
            'hospital'       => new HospitalResource($this->whenLoaded('hospital')),
            'holder'         => new UserResource($this->whenLoaded('holder')),
            'movements'      => StockMovementResource::collection($this->whenLoaded('movements')),
            'created_at'     => $this->created_at,
            'updated_at'     => $this->updated_at,
        ];
    }
}
