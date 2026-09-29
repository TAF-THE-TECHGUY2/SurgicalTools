<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * How to read one supplier's product label, managed by an admin so a new
 * supplier needs no code release.
 *
 * `field_mappings` overrides the standard GS1 reading field by field:
 *
 *     {
 *       "expiry_date": {"ai": "11", "date_format": "YYMMDD"},
 *       "lot_number":  {"ai": "21", "pattern": "^(.+?)\\d{4}$"},
 *       "ref":         {"source": "raw", "pattern": "REF[:\\s]*([A-Z0-9-]+)"}
 *     }
 *
 * - `ai` takes the value of that GS1 Application Identifier; `source: raw`
 *   takes the whole decoded text instead (for non-GS1 Code 128 labels).
 * - `pattern` is a regular expression; its first capture group is the value.
 * - `date_format` is YYMMDD (GS1, the default for an AI), YYYYMMDD, YYYY-MM-DD,
 *   DD/MM/YYYY or MM/YYYY.
 *
 * Fields the template does not mention keep the standard GS1 reading.
 */
class SupplierLabelTemplate extends Model
{
    /** Fields a mapping may fill — the four captured fields plus the barcode identifiers. */
    public const FIELDS = ['ref', 'gtin', 'lot_number', 'expiry_date', 'serial_number'];

    public const DATE_FORMATS = ['YYMMDD', 'YYYYMMDD', 'YYYY-MM-DD', 'DD/MM/YYYY', 'MM/YYYY'];

    /** Keep the combined photo-reader hints within a sensible prompt budget. */
    protected const MAX_HINTS_CHARS = 6000;

    protected $fillable = [
        'supplier', 'name', 'barcode_type', 'match_pattern', 'field_mappings',
        'ocr_hints', 'is_active',
    ];

    protected $casts = [
        'field_mappings' => 'array',
        'is_active'      => 'boolean',
    ];

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    /** The first active template whose pattern recognises this barcode text. */
    public static function forBarcode(string $raw): ?self
    {
        return static::active()
            ->whereNotNull('match_pattern')
            ->orderBy('id')
            ->get()
            ->first(fn (self $t) => $t->matches($raw));
    }

    public function matches(string $raw): bool
    {
        if (blank($this->match_pattern)) {
            return false;
        }

        return (bool) @preg_match(self::delimit($this->match_pattern), $raw);
    }

    /** Whether any field is read from the raw text rather than from GS1 elements. */
    public function readsRawText(): bool
    {
        return collect($this->field_mappings ?? [])->contains(fn ($m) => ($m['source'] ?? null) === 'raw');
    }

    /**
     * Photo-reader hints for every active template, labelled by supplier, so
     * the model can apply the right one once it recognises the label.
     */
    public static function combinedHints(): ?string
    {
        $hints = static::active()
            ->whereNotNull('ocr_hints')
            ->orderBy('supplier')
            ->get()
            ->filter(fn (self $t) => filled($t->ocr_hints))
            ->map(fn (self $t) => "For {$t->supplier} labels ({$t->name}): ".trim($t->ocr_hints))
            ->implode("\n");

        return $hints === '' ? null : mb_substr($hints, 0, self::MAX_HINTS_CHARS);
    }

    /** Admin input is a bare pattern; wrap it in delimiters unless it has them. */
    public static function delimit(string $pattern): string
    {
        return '~'.str_replace('~', '\~', $pattern).'~u';
    }

    /** Null when the pattern compiles, otherwise the reason it does not. */
    public static function patternError(string $pattern): ?string
    {
        set_error_handler(fn () => true);
        try {
            $ok = preg_match(self::delimit($pattern), '') !== false;
        } finally {
            restore_error_handler();
        }

        return $ok ? null : (preg_last_error_msg() ?: 'Invalid regular expression.');
    }
}
