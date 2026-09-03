<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** One label capture, with what was extracted and where it landed. */
class StockCountScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                  => $this->id,
            'stock_count_id'      => $this->stock_count_id,
            'stock_count_item_id' => $this->stock_count_item_id,
            'stock_item_id'       => $this->stock_item_id,
            'source'              => $this->source,
            'extracted'           => $this->extracted,
            'confidence'          => $this->confidence,
            'match_result'        => $this->match_result,
            'confirmed'           => (bool) $this->confirmed,
            'needs_review'        => $this->needsReview(),
            'image_url'           => $this->image_url,
            'client_id'           => $this->client_id,
            'created_at'          => $this->created_at,
            'line'                => new StockCountItemResource($this->whenLoaded('line')),
        ];
    }
}
