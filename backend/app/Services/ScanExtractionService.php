<?php

namespace App\Services;

use App\Models\SupplierLabelTemplate;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns a label capture into the three attributes the count needs: reference,
 * lot number and expiry date.
 *
 * Two paths, in order of preference:
 *
 *  1. readBarcode()     — GS1 Application Identifiers off a DataMatrix or
 *                         Code 128 barcode, read through the supplier's label
 *                         template when one matches (parseGs1() is the plain
 *                         GS1 reading). Deterministic: no confidence score.
 *  2. extractFromImage() — vision extraction, for labels whose barcode is
 *                         damaged, obscured or absent.
 *
 * Both return the same shape so the matching rule downstream doesn't care
 * which produced it.
 */
class ScanExtractionService
{
    public function __construct(protected ClaudeVisionClient $vision) {}

    /** The GS1 separator that terminates a variable-length element string. */
    protected const FNC1 = "\x1D";

    /**
     * Application Identifiers we care about, with their fixed data length.
     * A null length means variable — the value runs to the next FNC1 (or to
     * the end of the barcode).
     */
    protected const AI_LENGTHS = [
        '00' => 18,   // SSCC
        '01' => 14,   // GTIN
        '10' => null, // Batch / lot
        '11' => 6,    // Production date
        '15' => 6,    // Best before
        '17' => 6,    // Expiration date
        '21' => null, // Serial number
        '240' => null, // Additional product identification (often the maker's REF)
        '30' => null, // Variable count
    ];

    /**
     * Parse a GS1 element string.
     *
     * Accepts the human-readable bracketed form — (01)03456789012345(10)ABC —
     * and the raw scanner form, where variable-length fields are terminated by
     * FNC1 (0x1D) and fixed-length ones simply run on.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    public function parseGs1(string $raw): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new InvalidArgumentException('Empty barcode payload.');
        }

        $elements = $this->elements($raw);

        if ($elements === []) {
            throw new InvalidArgumentException('No GS1 application identifiers found in the barcode.');
        }

        return $this->standardFields($elements, $raw);
    }

    /**
     * Read a decoded barcode, applying the supplier's label template.
     *
     * The template is the one asked for, or else the first active template
     * whose pattern recognises the text. Without one this is plain GS1. A
     * template that reads from the raw text also accepts a non-GS1 barcode.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string, template_id: ?int}
     */
    public function readBarcode(string $raw, ?SupplierLabelTemplate $template = null): array
    {
        $raw = trim($raw);

        if ($raw === '') {
            throw new InvalidArgumentException('Empty barcode payload.');
        }

        $template ??= SupplierLabelTemplate::forBarcode($raw);
        $elements = $this->elements($raw);

        if ($elements === [] && ! $template?->readsRawText()) {
            throw new InvalidArgumentException('No GS1 application identifiers found in the barcode.');
        }

        $fields = $this->standardFields($elements, $raw);

        foreach ($template?->field_mappings ?? [] as $field => $mapping) {
            if (! in_array($field, SupplierLabelTemplate::FIELDS, true) || ! is_array($mapping)) {
                continue;
            }

            $fields[$field] = $this->mapField($field, $mapping, $elements, $raw);
        }

        return [...$fields, 'template_id' => $template?->id];
    }

    /** @return array<string, string> */
    protected function elements(string $raw): array
    {
        return str_contains($raw, '(')
            ? $this->parseBracketed($raw)
            : $this->parseRaw($raw);
    }

    /** The standard GS1 reading of a set of elements. */
    protected function standardFields(array $elements, string $raw): array
    {
        return [
            'ref'           => $elements['240'] ?? null,
            'gtin'          => $elements['01'] ?? null,
            'lot_number'    => $elements['10'] ?? null,
            'expiry_date'   => $this->gs1DateToIso($elements['17'] ?? $elements['15'] ?? null),
            'serial_number' => $elements['21'] ?? null,
            // A decoded barcode is exact — it either parsed or it threw.
            'confidence'    => 1.0,
            'raw_text'      => $raw,
        ];
    }

    /**
     * One template mapping: pick the source (a GS1 element or the raw text),
     * narrow it with the pattern's first capture group, and normalise dates.
     * A mapping that finds nothing yields null — never the standard reading,
     * which the template exists to override (Waston's (11) is not a
     * production date).
     */
    protected function mapField(string $field, array $mapping, array $elements, string $raw): ?string
    {
        $value = ($mapping['source'] ?? null) === 'raw'
            ? $raw
            : ($elements[(string) ($mapping['ai'] ?? '')] ?? null);

        if ($value !== null && filled($mapping['pattern'] ?? null)) {
            $value = @preg_match(SupplierLabelTemplate::delimit($mapping['pattern']), $value, $m)
                ? ($m[1] ?? $m[0])
                : null;
        }

        $value = $value === null ? null : (trim($value) === '' ? null : trim($value));

        if ($field === 'expiry_date' && $value !== null) {
            $format = $mapping['date_format']
                ?? (($mapping['source'] ?? null) === 'raw' ? 'YYYY-MM-DD' : 'YYMMDD');

            return $this->dateToIso($value, $format);
        }

        return $value;
    }

    /** Normalise a printed/encoded date to YYYY-MM-DD (the spec's storage format). */
    protected function dateToIso(string $value, string $format): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return match ($format) {
            'YYMMDD'     => $this->gs1DateToIso(strlen($digits) === 6 ? $digits : null),
            'YYYYMMDD', 'YYYY-MM-DD' => strlen($digits) === 8
                ? $this->validDate((int) substr($digits, 0, 4), (int) substr($digits, 4, 2), (int) substr($digits, 6, 2))
                : null,
            'DD/MM/YYYY' => strlen($digits) === 8
                ? $this->validDate((int) substr($digits, 4, 4), (int) substr($digits, 2, 2), (int) substr($digits, 0, 2))
                : null,
            // Month only: the last day of that month, as on the label reader.
            'MM/YYYY'    => strlen($digits) === 6 && checkdate((int) substr($digits, 0, 2), 1, (int) substr($digits, 2, 4))
                ? Carbon::create((int) substr($digits, 2, 4), (int) substr($digits, 0, 2), 1)->endOfMonth()->toDateString()
                : null,
            default      => null,
        };
    }

    protected function validDate(int $y, int $m, int $d): ?string
    {
        return checkdate($m, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /**
     * Vision fallback for a label with no readable barcode.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    public function extractFromImage(string $binary, string $mime, ?SupplierLabelTemplate $template = null): array
    {
        // A chosen template's hints alone; otherwise every supplier's, labelled,
        // so the reader applies the right one once it recognises the label.
        $hints = $template
            ? (filled($template->ocr_hints) ? "This is a {$template->supplier} label. ".trim($template->ocr_hints) : null)
            : SupplierLabelTemplate::combinedHints();

        return [
            ...$this->vision->extractLabel($binary, $mime, $hints),
            'template_id' => $template?->id,
        ];
    }

    /** Is the vision path usable, or is only barcode scanning available? */
    public function visionAvailable(): bool
    {
        return $this->vision->isConfigured();
    }

    /**
     * (01)0345...(10)LOT1 — brackets delimit the AI, so no length table is
     * needed and no FNC1 is expected.
     *
     * @return array<string, string>
     */
    protected function parseBracketed(string $raw): array
    {
        preg_match_all('/\((\d{2,4})\)([^(]*)/', $raw, $matches, PREG_SET_ORDER);

        $elements = [];
        foreach ($matches as $match) {
            $value = trim(str_replace(self::FNC1, '', $match[2]));
            if ($value !== '') {
                $elements[$match[1]] ??= $value;
            }
        }

        return $elements;
    }

    /**
     * Raw scanner output. Walks the string, reading an AI then consuming
     * either its fixed length or up to the next FNC1.
     *
     * @return array<string, string>
     */
    protected function parseRaw(string $raw): array
    {
        // A leading "]d2"/"]C1" symbology identifier is not part of the data.
        $raw = preg_replace('/^\](?:d2|C1|e0|Q3)/', '', $raw) ?? $raw;

        $elements = [];
        $position = 0;
        $length = strlen($raw);

        while ($position < $length) {
            // Skip stray separators between elements.
            if ($raw[$position] === self::FNC1) {
                $position++;
                continue;
            }

            [$ai, $dataLength] = $this->readAi($raw, $position, $length);

            if ($ai === null) {
                // Unrecognised AI — the rest cannot be walked safely.
                break;
            }

            $position += strlen($ai);

            if ($dataLength !== null) {
                $value = substr($raw, $position, $dataLength);
                $position += $dataLength;
            } else {
                $separator = strpos($raw, self::FNC1, $position);
                $value = $separator === false
                    ? substr($raw, $position)
                    : substr($raw, $position, $separator - $position);
                $position += strlen($value);
            }

            $value = trim($value);
            if ($value !== '') {
                $elements[$ai] ??= $value;
            }
        }

        return $elements;
    }

    /**
     * Identify the AI at $position. GS1 AIs are 2–4 digits and the set is not
     * prefix-free, so the two-digit forms we support are tried first, then the
     * longer ones.
     *
     * @return array{0: ?string, 1: ?int}
     */
    protected function readAi(string $raw, int $position, int $length): array
    {
        foreach ([2, 3, 4] as $width) {
            if ($position + $width > $length) {
                continue;
            }

            $candidate = substr($raw, $position, $width);

            if (ctype_digit($candidate) && array_key_exists($candidate, self::AI_LENGTHS)) {
                return [$candidate, self::AI_LENGTHS[$candidate]];
            }
        }

        return [null, null];
    }

    /**
     * GS1 dates are YYMMDD. A DD of "00" means "end of that month", which the
     * standard leaves to the reader — we take the last day.
     *
     * The century is inferred on the GS1 rule: a year more than 50 ahead is
     * past, otherwise future. Medical device expiries are always forward-dated
     * in practice.
     */
    protected function gs1DateToIso(?string $yymmdd): ?string
    {
        if ($yymmdd === null || ! preg_match('/^(\d{2})(\d{2})(\d{2})$/', $yymmdd, $m)) {
            return null;
        }

        [, $yy, $mm, $dd] = $m;

        $month = (int) $mm;
        if ($month < 1 || $month > 12) {
            return null;
        }

        $currentCentury = (int) floor(now()->year / 100) * 100;
        $year = $currentCentury + (int) $yy;
        if ($year - now()->year > 50) {
            $year -= 100;
        } elseif (now()->year - $year > 50) {
            $year += 100;
        }

        $day = (int) $dd;
        $date = Carbon::create($year, $month, 1);

        if ($date === null) {
            return null;
        }

        return $day === 0
            ? $date->endOfMonth()->toDateString()
            : ($day <= $date->daysInMonth ? $date->setDay($day)->toDateString() : null);
    }
}
