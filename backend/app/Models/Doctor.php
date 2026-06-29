<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Doctor extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'age', 'specialty', 'operating_days', 'equipment_used',
        'procedure_preferences', 'notes', 'phone', 'email', 'is_active',
    ];

    protected $casts = [
        'operating_days' => 'array',
        'equipment_used' => 'array',
        'is_active'      => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function hospitals(): BelongsToMany
    {
        return $this->belongsToMany(Hospital::class)->withTimestamps();
    }

    public function preferenceCards(): HasMany
    {
        return $this->hasMany(PreferenceCard::class);
    }
}
