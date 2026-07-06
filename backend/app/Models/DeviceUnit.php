<?php

namespace App\Models;

use App\Enums\DeviceUnitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One physical device: a specific serial/lot/expiry sitting at a location.
 * Transfers move units; the ledger (stock_movements) records each move.
 */
class DeviceUnit extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'stock_item_id', 'serial_number', 'lot_number', 'expiry_date',
        'location_id', 'status', 'meta',
    ];

    protected $casts = [
        'expiry_date' => 'date',
        'status'      => DeviceUnitStatus::class,
        'meta'        => 'array',
    ];

    protected $appends = ['days_to_expiry'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['location_id', 'status', 'serial_number', 'lot_number', 'expiry_date'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(StockItem::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('moved_at');
    }

    public function getDaysToExpiryAttribute(): ?int
    {
        return $this->expiry_date
            ? (int) now()->startOfDay()->diffInDays($this->expiry_date->startOfDay(), false)
            : null;
    }
}
