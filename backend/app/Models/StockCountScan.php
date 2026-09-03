<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * One label capture during a count: what was read, what was extracted from it
 * and what the database cross-reference produced.
 */
class StockCountScan extends Model
{
    use HasFactory;

    /** Barcode decode in the browser — deterministic, no confidence score. */
    public const SOURCE_BARCODE = 'barcode';

    /** Vision extraction from a photo — carries a confidence score. */
    public const SOURCE_VISION = 'vision';

    /** Typed in by the runner. */
    public const SOURCE_MANUAL = 'manual';

    /** Scanned item and lot were both on the expected list. */
    public const MATCH = 'match';

    /** Item resolved but nothing on the expected list to match it to. */
    public const UNRESOLVED = 'unresolved';

    protected $fillable = [
        'stock_count_id', 'stock_count_item_id', 'stock_item_id', 'image_path',
        'source', 'raw_payload', 'extracted', 'confidence', 'match_result',
        'confirmed', 'scanned_by', 'client_id',
    ];

    protected $casts = [
        'extracted'  => 'array',
        'confidence' => 'float',
        'confirmed'  => 'boolean',
    ];

    protected $appends = ['image_url'];

    public function stockCount(): BelongsTo
    {
        return $this->belongsTo(StockCount::class);
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(StockCountItem::class, 'stock_count_item_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function scanner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scanned_by');
    }

    /**
     * Whether the runner has to look at this before it counts. Barcode reads
     * are exact and pass straight through; vision extractions below the
     * configured threshold, and anything unresolved, are held.
     */
    public function needsReview(): bool
    {
        if ($this->match_result === self::UNRESOLVED) {
            return true;
        }

        if ($this->source !== self::SOURCE_VISION) {
            return false;
        }

        return ($this->confidence ?? 0.0) < (float) config('surgical.ocr.min_confidence', 0.8);
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? rescue(fn () => Storage::disk(config('filesystems.default'))->url($this->image_path), null, false)
            : null;
    }
}
