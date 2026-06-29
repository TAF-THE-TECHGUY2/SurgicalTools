<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreferenceCard extends Model
{
    use HasFactory;

    protected $fillable = [
        'doctor_id', 'procedure_name', 'notes', 'preferred_sizes', 'is_active',
    ];

    protected $casts = [
        'preferred_sizes' => 'array',
        'is_active'       => 'boolean',
    ];

    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PreferenceCardItem::class);
    }
}
