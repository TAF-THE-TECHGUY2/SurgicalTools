<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class HospitalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'code'            => $this->code,
            'category'        => $this->category,
            'region'          => $this->region,
            'address'         => $this->address,
            'city'            => $this->city,
            'province'        => $this->province,
            'phone'           => $this->phone,
            'email'           => $this->email,
            'is_active'       => $this->is_active,
            'assigned_rep'    => new UserResource($this->whenLoaded('assignedRep')),
            'assigned_runner' => new UserResource($this->whenLoaded('assignedRunner')),
            'contacts'        => $this->whenLoaded('contacts'),
            'doctors'         => DoctorResource::collection($this->whenLoaded('doctors')),
            'users'           => UserResource::collection($this->whenLoaded('users')),
            'inventory_count' => $this->whenCounted('inventoryItems'),
            'created_at'      => $this->created_at,
        ];
    }
}
