<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/** Catalog entry: a product line (Trochar, Guide Wire…). Physical stock = device_units. */
class StockItem extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'name', 'catalogue_number', 'item_code', 'supplier', 'product_group', 'gtin', 'description', 'uom',
        'unit_price', 'min_threshold', 'is_active',
    ];

    protected $casts = [
        'unit_price' => 'decimal:2',
        'is_active'  => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges();
    }

    public function units(): HasMany
    {
        return $this->hasMany(DeviceUnit::class);
    }

    public function scopeSearch(Builder $q, ?string $term): Builder
    {
        if (! $term) {
            return $q;
        }
        $like = '%'.$term.'%';

        return $q->where(function (Builder $sub) use ($like) {
            $sub->where('name', 'like', $like)
                ->orWhere('catalogue_number', 'like', $like)
                ->orWhere('item_code', 'like', $like)
                ->orWhere('gtin', 'like', $like);
        });
    }

    /**
     * Resolve a scanned label to a catalogue entry.
     *
     * GTIN first — it comes off a barcode and is exact — then the catalogue
     * number, then the item code. Both code columns are live, so both are
     * tried.
     *
     * @param  array<string, mixed>  $extracted
     */
    public static function resolveFromScan(array $extracted): ?self
    {
        $clean = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        };

        if ($gtin = $clean($extracted['gtin'] ?? null)) {
            if ($byGtin = static::where('gtin', $gtin)->first()) {
                return $byGtin;
            }
        }

        $ref = $clean($extracted['ref'] ?? null);

        if ($ref === null) {
            return null;
        }

        return static::where('catalogue_number', $ref)->first()
            ?? static::where('item_code', $ref)->first()
            ?? static::whereNormalizedCode($ref)->first();
    }

    /**
     * Match a code ignoring case, spaces and hyphens: a label printing
     * "533-005-925" must find a catalogue entry keyed "533005925", and an OCR
     * pass that drops a hyphen must still resolve.
     */
    public function scopeWhereNormalizedCode(Builder $q, string $code): Builder
    {
        $normalized = preg_replace('/[\s\-]+/', '', mb_strtoupper(trim($code)));

        if ($normalized === '') {
            return $q->whereRaw('1 = 0');
        }

        $expr = fn (string $col) => "REPLACE(REPLACE(UPPER({$col}), '-', ''), ' ', '')";

        return $q->where(fn ($w) => $w
            ->whereRaw($expr('catalogue_number').' = ?', [$normalized])
            ->orWhereRaw($expr('item_code').' = ?', [$normalized]));
    }

    /** Available (on-hand, not pending/missing) unit count across all locations. */
    public function availableCount(): int
    {
        return $this->units()->whereIn('status', ['available', 'pending_transfer'])->count();
    }
}
