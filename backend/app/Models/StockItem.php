<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Catalog entry: a product line (Trochar, Guide Wire…). Physical stock = device_units. */
class StockItem extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'catalogue_number', 'item_code', 'description', 'uom',
        'unit_price', 'min_threshold', 'is_active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeviceUnit::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }
        $like = '%'.$term.'%';

        return $q->where(function (Builder $sub) use ($like) {
            $sub->where('name', 'like', $like)
                ->orWhere('catalogue_number', 'like', $like)
                ->orWhere('item_code', 'like', $like);
        });
    }

    /** Available (on-hand, not pending/missing) unit count across all locations. */
    public function availableCount(): int
    {
        return $this->units()->whereIn('status', ['available', 'pending_transfer'])->count();
    }
}
