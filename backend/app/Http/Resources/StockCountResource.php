<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockCountResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'reference'      => $this->reference,
            'status'         => $this->status?->value,
            'location'       => $this->location,
            'notes'          => $this->notes,
            'total_variance' => $this->when($this->relationLoaded('items'), fn () => $this->total_variance),
            'hospital'       => new HospitalResource($this->whenLoaded('hospital')),
            'requester'      => new UserResource($this->whenLoaded('requester')),
            'assignee'       => new UserResource($this->whenLoaded('assignee')),
            'items'          => $this->whenLoaded('items'),
            'submitted_at'   => $this->submitted_at,
            'reviewed_at'    => $this->reviewed_at,
            'created_at'     => $this->created_at,
        ];
    }
}
