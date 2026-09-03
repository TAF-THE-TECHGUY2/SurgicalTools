<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One stock-count line. `is_adjustment` drives the orange row treatment in the
 * UI, and `expected_lot_number` is what the site was expecting, shown beside
 * the lot actually found.
 */
class StockCountItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'stock_item_id'       => $this->stock_item_id,
            'ref_code'            => $this->ref_code,
            'description'         => $this->description,
            'lot_number'          => $this->lot_number,
            'expiry_date'         => $this->expiry_date?->toDateString(),
            'expected_quantity'   => (int) $this->expected_quantity,
            'scanned_quantity'    => (int) $this->scanned_quantity,
            'counted_quantity'    => $this->counted_quantity,
            'variance'            => $this->variance,

            // Discrepancy lines: rendered with the orange highlight.
            'is_adjustment'       => (bool) $this->is_adjustment,
            'adjustment_type'     => $this->adjustment_type?->value,
            'adjustment_label'    => $this->adjustment_type?->label(),
            'parent_item_id'      => $this->parent_item_id,
            'expected_lot_number' => $this->expected_lot_number,

            'first_scanned_at'    => $this->first_scanned_at,
            'last_scanned_at'     => $this->last_scanned_at,
            'photo_url'           => $this->photo_url,
            'notes'               => $this->notes,
        ];
    }
}
