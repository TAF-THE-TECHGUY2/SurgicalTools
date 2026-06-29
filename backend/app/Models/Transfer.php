<?php

namespace App\Models;

use App\Enums\TransferStatus;
use App\Enums\TransferType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\Activitylog\Models\Concerns\LogsActivity;

class Transfer extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'reference', 'type', 'status', 'from_location', 'to_location',
        'from_holder_user_id', 'to_holder_user_id', 'hospital_id', 'doctor_id',
        'hospital_stock_type', 'requested_by', 'approved_by', 'approved_at',
        'admin_override', 'reviewed_by', 'reviewed_at', 'rejected_by',
        'rejected_at', 'rejection_reason', 'completed_at', 'notes', 'meta',
    ];

    protected $casts = [
        'type'           => TransferType::class,
        'status'         => TransferStatus::class,
        'admin_override' => 'boolean',
        'approved_at'    => 'datetime',
        'reviewed_at'    => 'datetime',
        'rejected_at'    => 'datetime',
        'completed_at'   => 'datetime',
        'meta'           => 'array',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'approved_by', 'reviewed_by', 'rejected_by', 'admin_override'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /* -------------------------------------------------------------------- */
    /*  Relationships                                                       */
    /* -------------------------------------------------------------------- */

    public function items(): HasMany
    {
        return $this->hasMany(TransferItem::class);
    }

    public function signatures(): HasMany
    {
        return $this->hasMany(TransferSignature::class);
    }

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function fromHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_holder_user_id');
    }

    public function toHolder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_holder_user_id');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function pastelExports(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(PastelExport::class, 'pastel_export_lines')->withTimestamps();
    }

    public function movements(): MorphMany
    {
        return $this->morphMany(StockMovement::class, 'reference');
    }

    /* -------------------------------------------------------------------- */
    /*  Helpers                                                             */
    /* -------------------------------------------------------------------- */

    public function isType(TransferType $type): bool
    {
        return $this->type === $type;
    }

    public function latestDocument(string $type): ?Document
    {
        return $this->documents()->where('type', $type)->latest()->first();
    }
}
