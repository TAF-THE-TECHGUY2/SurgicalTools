<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;

class PastelExport extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference', 'type', 'period_from', 'period_to', 'row_count',
        'file_path', 'status', 'exported_by', 'meta',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
        'meta'        => 'array',
    ];

    protected $appends = ['url'];

    public function exporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'exported_by');
    }

    public function transfers(): BelongsToMany
    {
        return $this->belongsToMany(Transfer::class, 'pastel_export_lines')->withTimestamps();
    }

    public function getUrlAttribute(): ?string
    {
        return $this->file_path
            ? rescue(fn () => Storage::disk(config('filesystems.default'))->url($this->file_path), null, false)
            : null;
    }
}
