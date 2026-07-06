<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'inventory_item_id', 'device_unit_id', 'ref_code', 'lot_number', 'quantity',
        'movement_type', 'from_location', 'to_location', 'from_location_id',
        'to_location_id', 'from_holder_user_id', 'to_holder_user_id',
        'from_hospital_id', 'to_hospital_id', 'reference_type', 'reference_id',
        'performed_by', 'notes', 'moved_at',
    ];

    protected $casts = [
        'moved_at' => 'datetime',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function performedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
