<?php

namespace App\Models;

use App\Enums\StockLocation;
use App\Enums\StockStatus;
use App\Enums\StockType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class InventoryItem extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'ref_code', 'description', 'lot_number', 'quantity', 'expiry_date',
        'stock_type', 'location', 'status', 'hospital_id', 'holder_user_id',
        'min_threshold', 'unit_price', 'barcode', 'uom', 'meta',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'stock_type'  => StockType::class,
        'location'    => StockLocation::class,
        'status'      => StockStatus::class,
        'unit_price'  => 'decimal:2',
        'meta'        => 'array',
    ];

    protected $appends = ['is_low_stock', 'days_to_expiry'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['quantity', 'status', 'location', 'stock_type', 'holder_user_id', 'hospital_id'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /* -------------------------------------------------------------------- */
    /*  Relationships                                                       */
    /* -------------------------------------------------------------------- */

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('moved_at');
    }

    /* -------------------------------------------------------------------- */
    /*  Computed attributes                                                 */
    /* -------------------------------------------------------------------- */

    public function getIsLowStockAttribute(): bool
    {
        $threshold = $this->min_threshold ?? (int) config('surgical.low_stock_default_threshold');

        return $this->min_threshold !== null
            ? $this->quantity <= $this->min_threshold
            : $this->quantity <= $threshold;
    }

    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expiry_date
            ? (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false)
            : null;
    }

    /* -------------------------------------------------------------------- */
    /*  Scopes                                                              */
    /* -------------------------------------------------------------------- */

    public function scopeAvailable(Builder $q): Builder
    {
        return $q->where('status', StockStatus::Available->value)->where('quantity', '>', 0);
    }

    public function scopeLowStock(Builder $q): Builder
    {
        $default = (int) config('surgical.low_stock_default_threshold');

        return $q->where(function (Builder $sub) use ($default) {
            $sub->whereNotNull('min_threshold')
                ->whereColumn('quantity', '<=', 'min_threshold');
        })->orWhere(function (Builder $sub) use ($default) {
            $sub->whereNull('min_threshold')->where('quantity', '<=', $default);
        });
    }

    public function scopeExpiringWithin(Builder $q, int $days): Builder
    {
        return $q->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($days))
            ->whereDate('expiry_date', '>=', now());
    }

    /** Universal search across the human-meaningful fields. */
    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }

        $like = '%'.$term.'%';

        return $q->where(function (Builder $sub) use ($like) {
            $sub->where('ref_code', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('lot_number', 'like', $like)
                ->orWhere('barcode', 'like', $like);
        });
    }
}
