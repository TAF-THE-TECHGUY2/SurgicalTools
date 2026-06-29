<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreferenceCardItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'preference_card_id', 'ref_code', 'description', 'preferred_size',
        'quantity', 'notes',
    ];

    public function preferenceCard(): BelongsTo
    {
        return $this->belongsTo(PreferenceCard::class);
    }
}
