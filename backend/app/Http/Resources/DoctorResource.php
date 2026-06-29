<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DoctorResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                    => $this->id,
            'name'                  => $this->name,
            'age'                   => $this->age,
            'specialty'             => $this->specialty,
            'operating_days'        => $this->operating_days,
            'equipment_used'        => $this->equipment_used,
            'procedure_preferences' => $this->procedure_preferences,
            'notes'                 => $this->notes,
            'phone'                 => $this->phone,
            'email'                 => $this->email,
            'is_active'             => $this->is_active,
            'hospitals'             => HospitalResource::collection($this->whenLoaded('hospitals')),
            'preference_cards'      => PreferenceCardResource::collection($this->whenLoaded('preferenceCards')),
            'created_at'            => $this->created_at,
        ];
    }
}
