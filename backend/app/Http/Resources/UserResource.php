<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'email'       => $this->email,
            'phone'       => $this->phone,
            'region'      => $this->region,
            'staff_type'  => $this->staff_type,
            'location_id' => $this->location_id,
            'location'    => new LocationResource($this->whenLoaded('location')),
            'is_active'   => $this->is_active,
            'roles'       => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'permissions' => $this->when(
                $request->routeIs('auth.me') || $request->routeIs('users.*'),
                fn () => $this->getAllPermissions()->pluck('name'),
            ),
            'hospitals'   => HospitalResource::collection($this->whenLoaded('hospitals')),
            'created_at'  => $this->created_at,
        ];
    }
}
