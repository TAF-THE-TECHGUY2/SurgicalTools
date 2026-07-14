<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, LogsActivity, Notifiable, SoftDeletes;

    public const SYSTEM_PERMISSIONS = [
        'inventory.view', 'inventory.manage',
        'transfer.view', 'transfer.create', 'transfer.approve', 'transfer.override', 'transfer.review',
        'stock_count.capture', 'stock_count.review',
        'hospital.view', 'hospital.manage',
        'doctor.view', 'doctor.manage',
        'location.manage',
        'report.view', 'pastel.export',
        'user.manage', 'role.manage', 'config.manage', 'audit.view',
    ];

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'region',
        'staff_type',
        'location_id',
        'is_active',
        'permission_overrides',
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
            'permission_overrides' => 'array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['name', 'email', 'phone', 'region', 'staff_type', 'is_active', 'permission_overrides'])
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

    /** Permissions exposed to the UI and enforced by Gate::before. */
    public function effectivePermissionNames(): array
    {
        if ($this->permission_overrides !== null) {
            return array_values(array_unique($this->permission_overrides));
        }

        return $this->getAllPermissions()->pluck('name')->values()->all();
    }

    /**
     * Enforce a user's checked permission list before falling back to their
     * role permissions. This makes unticking an inherited permission a real
     * denial, not merely the absence of an additional direct permission.
     */
    public function can($abilities, $arguments = []): bool
    {
        if ($this->isSuperAdmin()) {
            return true;
        }

        if (is_string($abilities)
            && $this->permission_overrides !== null
            && in_array($abilities, self::SYSTEM_PERMISSIONS, true)) {
            return in_array($abilities, $this->permission_overrides, true);
        }

        return app(GateContract::class)->forUser($this)->check($abilities, $arguments);
    }

    /** IDs of hospitals this user may approve requests for. */
    public function assignedHospitalIds(): array
    {
        return $this->hospitals()->pluck('hospitals.id')->all();
    }
}
