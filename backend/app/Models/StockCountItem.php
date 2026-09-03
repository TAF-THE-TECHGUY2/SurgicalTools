<?php

namespace App\Models;

use App\Enums\StockCountAdjustmentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class StockCountItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'stock_count_id', 'inventory_item_id', 'stock_item_id', 'ref_code',
        'description', 'lot_number', 'expiry_date', 'expected_quantity',
        'scanned_quantity', 'counted_quantity', 'variance', 'is_adjustment',
        'adjustment_type', 'parent_item_id', 'expected_lot_number',
        'first_scanned_at', 'last_scanned_at', 'photo_path', 'notes',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'is_adjustment'    => 'boolean',
        'adjustment_type'  => StockCountAdjustmentType::class,
        'first_scanned_at' => 'datetime',
        'last_scanned_at'  => 'datetime',
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

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    /** The expected line this adjustment was raised against. */
    public function parentItem(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_item_id');
    }

    /** Adjustments raised against this expected line. */
    public function adjustments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_item_id');
    }

    /** Lines snapshotted from the expected inventory (not raised by a scan). */
    public function scopeExpected(Builder $q): Builder
    {
        return $q->where('is_adjustment', false);
    }

    /** Orange lines: lot mismatches, unlisted items, expiry mismatches. */
    public function scopeAdjustments(Builder $q): Builder
    {
        return $q->where('is_adjustment', true);
    }

    /**
     * Canonical form of a lot number for comparison: uppercased with
     * whitespace and hyphens stripped. Scanned and stored lots must both pass
     * through here before they are compared — an OCR pass that drops a hyphen
     * or a leading space must not fabricate a lot mismatch.
     */
    public static function normalizeLot(?string $lot): ?string
    {
        if ($lot === null) {
            return null;
        }

        $normalized = preg_replace('/[\s\-]+/', '', mb_strtoupper(trim($lot)));

        return $normalized === '' ? null : $normalized;
    }

    public function getPhotoUrlAttribute(): ?string
    {
        return $this->photo_path
            ? rescue(fn () => Storage::disk(config('filesystems.default'))->url($this->photo_path), null, false)
            : null;
    }
}
