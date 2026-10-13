<?php

namespace App\Services\Vision;

/**
 * The instructions and output shape shared by every vision provider.
 *
 * Kept in one place deliberately: if the wording drifts between providers then
 * so does the extraction, and a label that counts correctly on one account
 * would raise an orange discrepancy on another. The schema is the same for the
 * same reason — the matching rule downstream must not be able to tell which
 * provider answered.
 */
final class LabelPrompt
{
    public static function system(): string
    {
        return <<<'PROMPT'
        You read sticker labels on sterile medical device packaging and extract
        the identifying fields. You are the fallback when the label's barcode
        could not be decoded, so the text is often small, curved, or partly
        obscured by foil glare.

        Extract exactly these fields:

        - ref: the manufacturer's catalogue or reference number, usually printed
          beside "REF" and sometimes marked with the ISO 15223 "REF" symbol.
          Report it exactly as printed, including any letters or punctuation.
        - gtin: the 14-digit GTIN, if printed as digits near the barcode.
        - lot_number: the batch or lot code, beside "LOT" or the "LOT" symbol.
          Transcribe character by character. Do not tidy it, expand it, or drop
          leading digits — a single wrong character creates a false stock
          discrepancy downstream.
        - expiry_date: the use-by date, beside an hourglass symbol or "EXP", as
          an ISO 8601 date. Labels vary between YYYY-MM-DD, YYYY-MM and
          MM/YYYY; when only a month is given, use the last day of that month.
        - serial_number: the serial, beside "SN", if present.

        Rules:

        - Return null for any field that is not legible or not present. Never
          guess a value and never fill a field from another field.
        - confidence is your own assessment, 0 to 1, of the least legible field
          you did return. Be honest: a low score sends the scan to a human,
          which is the correct outcome for a bad photo.
        - raw_text is every piece of text you can read on the label, so a
          reviewer can check your reading against it.
        PROMPT;
    }

    /**
     * JSON Schema for the answer.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $nullableString = ['type' => ['string', 'null']];

        return [
            'type'       => 'object',
            'properties' => [
                'ref'           => $nullableString + ['description' => 'Manufacturer catalogue/reference number, exactly as printed'],
                'gtin'          => $nullableString + ['description' => '14-digit GTIN if printed'],
                'lot_number'    => $nullableString + ['description' => 'Batch/lot code, transcribed character for character'],
                'expiry_date'   => $nullableString + ['description' => 'Expiry as an ISO 8601 date (YYYY-MM-DD)'],
                'serial_number' => $nullableString + ['description' => 'Serial number if present'],
                'confidence'    => ['type' => 'number', 'description' => 'Confidence 0-1 in the least legible field returned'],
                'raw_text'      => ['type' => 'string', 'description' => 'All text legible on the label'],
            ],
            'required' => [
                'ref', 'gtin', 'lot_number', 'expiry_date',
                'serial_number', 'confidence', 'raw_text',
            ],
            'additionalProperties' => false,
        ];
    }

    /** The question asked alongside the image. */
    public static function instruction(): string
    {
        return 'Extract the label fields.';
    }

    /** Formats the vision APIs accept. */
    public const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Hard limit on a base64-encoded image. */
    public const MAX_BYTES = 10 * 1024 * 1024;

    /**
     * Shared pre-flight. Refusing locally beats paying for a round trip that
     * was always going to be rejected.
     */
    public static function assertUsableImage(string $binary, string $mime): void
    {
        if (! in_array($mime, self::SUPPORTED_MIMES, true)) {
            throw new \RuntimeException("Unsupported image type '{$mime}'. Use JPEG, PNG, GIF or WebP.");
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw new \RuntimeException('Label photo is too large — resize it to roughly 1600px on the long edge.');
        }
    }

    /**
     * Normalise a decoded answer into the shape the matching rule expects.
     *
     * @param  array<string, mixed>  $data
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    public static function normalise(array $data): array
    {
        $str = static function (mixed $value): ?string {
            if (! is_string($value)) {
                return null;
            }
            $trimmed = trim($value);

            // A blank string must become null: an empty lot number that stayed
            // a string would be compared as a real value.
            return $trimmed === '' ? null : $trimmed;
        };

        return [
            'ref'           => $str($data['ref'] ?? null),
            'gtin'          => $str($data['gtin'] ?? null),
            'lot_number'    => $str($data['lot_number'] ?? null),
            'expiry_date'   => $str($data['expiry_date'] ?? null),
            'serial_number' => $str($data['serial_number'] ?? null),
            // Missing confidence reads as zero, which routes the scan to a human.
            'confidence'    => (float) ($data['confidence'] ?? 0.0),
            'raw_text'      => (string) ($data['raw_text'] ?? ''),
        ];
    }
}
