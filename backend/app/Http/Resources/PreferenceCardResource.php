<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PreferenceCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'doctor_id'       => $this->doctor_id,
            'procedure_name'  => $this->procedure_name,
            'notes'           => $this->notes,
            'preferred_sizes' => $this->preferred_sizes,
            'is_active'       => $this->is_active,
            'doctor'          => new DoctorResource($this->whenLoaded('doctor')),
            'items'           => $this->whenLoaded('items'),
            'created_at'      => $this->created_at,
        ];
    }
}
