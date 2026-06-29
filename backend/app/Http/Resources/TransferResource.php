<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransferResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'reference'           => $this->reference,
            'type'                => $this->type?->value,
            'type_label'          => $this->type?->label(),
            'status'              => $this->status?->value,
            'from_location'       => $this->from_location,
            'to_location'         => $this->to_location,
            'hospital_stock_type' => $this->hospital_stock_type,
            'admin_override'      => $this->admin_override,
            'notes'               => $this->notes,
            'hospital'            => new HospitalResource($this->whenLoaded('hospital')),
            'doctor'              => new DoctorResource($this->whenLoaded('doctor')),
            'requester'           => new UserResource($this->whenLoaded('requester')),
            'approver'            => new UserResource($this->whenLoaded('approver')),
            'reviewer'            => new UserResource($this->whenLoaded('reviewer')),
            'from_holder'         => new UserResource($this->whenLoaded('fromHolder')),
            'to_holder'           => new UserResource($this->whenLoaded('toHolder')),
            'items'               => TransferItemResource::collection($this->whenLoaded('items')),
            'signatures'          => $this->whenLoaded('signatures'),
            'documents'           => $this->whenLoaded('documents'),
            'approved_at'         => $this->approved_at,
            'reviewed_at'         => $this->reviewed_at,
            'rejected_at'         => $this->rejected_at,
            'rejection_reason'    => $this->rejection_reason,
            'completed_at'        => $this->completed_at,
            'created_at'          => $this->created_at,
        ];
    }
}
