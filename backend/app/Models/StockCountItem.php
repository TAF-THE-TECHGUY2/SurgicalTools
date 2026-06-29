<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class StockCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_count_id', 'inventory_item_id', 'ref_code', 'description',
        'lot_number', 'expected_quantity', 'counted_quantity', 'variance',
        'photo_path', 'notes',
    ];

    protected $appends = ['photo_url'];

    protected static function booted(): void
    {
        // Variance is always counted - expected, kept in sync automatically.
        static::saving(function (StockCountItem $item) {
            if ($item->counted_quantity !== null) {
                $item->variance = (int) $item->counted_quantity - (int) $item->expected_quantity;
            }
        });
    }

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? rescue(fn () => Storage::disk(config('filesystems.default'))->url($this->photo_path), null, false)
            : null;
    }
}
