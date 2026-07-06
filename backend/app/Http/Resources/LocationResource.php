<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'code'        => $this->code,
            'type'        => $this->type,
            'hospital_id' => $this->hospital_id,
            'hospital'    => new HospitalResource($this->whenLoaded('hospital')),
            'owner'       => new UserResource($this->whenLoaded('owner')),
            'is_active'   => $this->is_active,
            'units_count' => $this->whenCounted('units'),
            'created_at'  => $this->created_at,
        ];
    }
}
