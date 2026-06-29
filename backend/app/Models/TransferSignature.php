<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class TransferSignature extends Model
{
    use HasFactory;

    protected $fillable = [
        'transfer_id', 'signer_name', 'signer_role', 'signed_by_user_id',
        'signature_path', 'ip_address', 'signed_at',
    ];

    protected $casts = [
        'signed_at' => 'datetime',
    ];

    protected $appends = ['url'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function getUrlAttribute(): ?string
    {
        return $this->signature_path
            ? rescue(fn () => Storage::disk(config('filesystems.default'))->url($this->signature_path), null, false)
            : null;
    }
}
