<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A stock "entity" — anywhere devices can sit: a hospital, a rep's boot, or an
 * office/warehouse. Transfers move device units between locations.
 */
class Location extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'code', 'type', 'hospital_id', 'owner_user_id', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeviceUnit::class);
    }

    /** Users whose "My Inventory" is this location. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
