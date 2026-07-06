<?php

namespace App\Models;

use App\Enums\StockCountStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class StockCount extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'reference', 'status', 'location', 'location_id', 'hospital_id', 'holder_user_id',
        'requested_by', 'assigned_to', 'reviewed_by', 'submitted_at',
        'reviewed_at', 'notes',
    ];

    protected $casts = [
        'status'       => StockCountStatus::class,
        'submitted_at' => 'datetime',
        'reviewed_at'  => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['status', 'assigned_to', 'reviewed_by'])
            ->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockCountItem::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    /** The location entity being counted (named to avoid clashing with the legacy `location` string column). */
    public function locationEntity(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function holder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'holder_user_id');
    }

    /** Total absolute variance across all counted lines. */
    public function getTotalVarianceAttribute(): int
    {
        return (int) $this->items->sum(fn ($i) => abs((int) $i->variance));
    }
}
