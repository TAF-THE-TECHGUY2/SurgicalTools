<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Turns a label capture into the three attributes the count needs: reference,
 * lot number and expiry date.
 *
 * Two paths, in order of preference:
 *
 *  1. parseGs1()        — GS1 Application Identifiers off a DataMatrix or
 *                         Code 128 barcode. Deterministic: no confidence
 *                         score, no misreads, works offline.
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

        $elements = str_contains($raw, '(')
            ? $this->parseBracketed($raw)
            : $this->parseRaw($raw);

        if ($elements === []) {
            throw new InvalidArgumentException('No GS1 application identifiers found in the barcode.');
        }

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
     * Vision fallback for a label with no readable barcode.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    public function extractFromImage(string $binary, string $mime): array
    {
        return $this->vision->extractLabel($binary, $mime);
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
