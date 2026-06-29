<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'documentable_type', 'documentable_id', 'type', 'disk', 'path',
        'original_name', 'mime_type', 'size', 'uploaded_by', 'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];

    protected $appends = ['url'];

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        // The private `local` disk has no public URL; documents are served
        // through authenticated download endpoints instead.
        return $this->path ? rescue(fn () => Storage::disk($this->disk)->url($this->path), null, false) : null;
    }
}
