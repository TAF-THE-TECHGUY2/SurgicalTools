<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockMovementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'ref_code'      => $this->ref_code,
            'lot_number'    => $this->lot_number,
            'quantity'      => $this->quantity,
            'movement_type' => $this->movement_type,
            'from_location' => $this->from_location,
            'to_location'   => $this->to_location,
            'notes'         => $this->notes,
            'performed_by'  => new UserResource($this->whenLoaded('performedBy')),
            'moved_at'      => $this->moved_at,
        ];
    }
}
