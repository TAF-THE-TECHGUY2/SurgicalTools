<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'name'             => $this->name,
            'catalogue_number' => $this->catalogue_number,
            'item_code'        => $this->item_code,
            'description'      => $this->description,
            'uom'              => $this->uom,
            'unit_price'       => $this->unit_price,
            'min_threshold'    => $this->min_threshold,
            'is_active'        => $this->is_active,
            'units_count'      => $this->whenCounted('units'),
            'units'            => DeviceUnitResource::collection($this->whenLoaded('units')),
            'created_at'       => $this->created_at,
        ];
    }
}
