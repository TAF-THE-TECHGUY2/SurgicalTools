<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'region',
        'staff_type',
        'location_id',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'region', 'staff_type', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /* -------------------------------------------------------------------- */
    /*  Relationships                                                       */
    /* -------------------------------------------------------------------- */

    /** The location (boot / office) whose stock is this user's "My Inventory". */
    public function location(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** Hospitals this user is assigned to as a rep / runner. */
    public function hospitals(): BelongsToMany
    {
        return $this->belongsToMany(Hospital::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function inventoryHoldings(): HasMany
    {
        return $this->hasMany(InventoryItem::class, 'holder_user_id');
    }

    public function requestedTransfers(): HasMany
    {
        return $this->hasMany(Transfer::class, 'requested_by');
    }

    public function assignedStockCounts(): HasMany
    {
        return $this->hasMany(StockCount::class, 'assigned_to');
    }

    /* -------------------------------------------------------------------- */
    /*  Role helpers                                                        */
    /* -------------------------------------------------------------------- */

    public function isSuperAdmin(): bool
    {
        return $this->hasRole(\App\Enums\UserRole::SuperAdmin->value);
    }

    public function isAdmin(): bool
    {
        return $this->hasAnyRole([
            \App\Enums\UserRole::Admin->value,
            \App\Enums\UserRole::SuperAdmin->value,
        ]);
    }

    /** IDs of hospitals this user may approve requests for. */
    public function assignedHospitalIds(): array
    {
        return $this->hospitals()->pluck('hospitals.id')->all();
    }
}
