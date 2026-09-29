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
        'item_code', 'supplier', 'product_group', 'unit_price',
        'description', 'lot_number', 'expiry_date', 'expected_quantity',
        'scanned_quantity', 'counted_quantity', 'variance', 'is_adjustment',
        'adjustment_type', 'parent_item_id', 'expected_lot_number',
        'first_scanned_at', 'last_scanned_at', 'photo_path', 'notes',
        'not_found_at', 'not_found_by', 'lot_adjusted_quantity', 'lot_adjusted_at',
        'lot_adjusted_by',
    ];

    protected $casts = [
        'expiry_date'      => 'date',
        'is_adjustment'    => 'boolean',
        'adjustment_type'  => StockCountAdjustmentType::class,
        'first_scanned_at' => 'datetime',
        'last_scanned_at'  => 'datetime',
        'unit_price'       => 'decimal:2',
        'not_found_at'     => 'datetime',
        'lot_adjusted_at'  => 'datetime',
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

    /**
     * The catalogue columns a line snapshots for the paper-layout sheet, so a
     * later catalogue edit cannot change what a signed sheet said.
     *
     * @return array{ref_code: string, item_code: ?string, supplier: ?string, product_group: ?string, unit_price: mixed, description: ?string}
     */
    public static function catalogueColumns(?StockItem $item, int|string|null $fallbackId = null): array
    {
        return [
            'ref_code'      => $item?->catalogue_number ?? $item?->item_code ?? (string) ($item?->id ?? $fallbackId),
            'item_code'     => $item?->item_code,
            'supplier'      => $item?->supplier,
            'product_group' => $item?->product_group,
            'unit_price'    => $item?->unit_price,
            'description'   => $item?->name,
        ];
    }

    /** The sheet's tick box: at least one unit of this line was found. */
    public function isTicked(): bool
    {
        return (int) $this->scanned_quantity > 0 || (int) $this->counted_quantity > 0;
    }

    /**
     * Whether the line stops a count from finishing (§3.4): an expected line
     * is resolved once it was scanned, keyed, or minus-confirmed as none found.
     * Adjustment lines exist only because something was scanned.
     */
    public function isResolved(): bool
    {
        return $this->is_adjustment
            || (int) $this->scanned_quantity > 0
            || $this->counted_quantity !== null
            || $this->not_found_at !== null;
    }

    /** Rand value of the variance at the snapshotted list/unit price. */
    public function varianceValue(): ?float
    {
        if ($this->variance === null || $this->unit_price === null) {
            return null;
        }

        return round((int) $this->variance * (float) $this->unit_price, 2);
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
