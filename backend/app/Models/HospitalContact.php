<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HospitalContact extends Model
{
    use HasFactory;

    protected $fillable = [
        'hospital_id', 'name', 'role', 'email', 'phone', 'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
    ];

    public function hospital(): BelongsTo
    {
        return $this->belongsTo(Hospital::class);
    }
}
