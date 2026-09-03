<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use RuntimeException;

/**
 * Vision extraction of a medical-device label, used only when no barcode
 * could be decoded. Returns the same shape as the GS1 parser.
 *
 * The response is constrained by a JSON schema, so the model cannot answer in
 * prose and the result never needs to be scraped out of free text.
 */
class ClaudeVisionClient
{
    /** Formats the Messages API accepts for image blocks. */
    protected const SUPPORTED_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** Hard API limit on a base64-encoded image. */
    protected const MAX_BYTES = 10 * 1024 * 1024;

    protected ?Client $client = null;

    public function isConfigured(): bool
    {
        return filled(config('surgical.ocr.api_key'));
    }

    /**
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    public function extractLabel(string $binary, string $mime): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Vision OCR is not configured. Set ANTHROPIC_API_KEY to read labels from photos; '
                .'barcode scanning does not require it.'
            );
        }

        if (! in_array($mime, self::SUPPORTED_MIMES, true)) {
            throw new RuntimeException("Unsupported image type '{$mime}'. Use JPEG, PNG, GIF or WebP.");
        }

        if (strlen($binary) > self::MAX_BYTES) {
            throw new RuntimeException('Label photo is too large — resize it to roughly 1600px on the long edge.');
        }

        $message = $this->client()->messages->create(
            model: (string) config('surgical.ocr.model', 'claude-opus-5'),
            maxTokens: 2048,
            // The instructions are identical on every scan, so caching the
            // prefix keeps repeat calls during a count cheap.
            system: [TextBlockParam::with(
                text: $this->systemPrompt(),
                cacheControl: ['type' => 'ephemeral'],
            )],
            messages: [MessageParam::with(
                role: 'user',
                content: [
                    // Image before text: the model reads image-then-question best.
                    ImageBlockParam::with(
                        source: Base64ImageSource::with(
                            data: base64_encode($binary),
                            mediaType: $mime,
                        ),
                    ),
                    TextBlockParam::with(text: 'Extract the label fields.'),
                ],
            )],
            outputConfig: OutputConfig::with(
                format: JSONOutputFormat::with(schema: $this->schema()),
            ),
            requestOptions: ['timeout' => (int) config('surgical.ocr.timeout', 45)],
        );

        return $this->parse($message);
    }

    protected function client(): Client
    {
        return $this->client ??= new Client(apiKey: (string) config('surgical.ocr.api_key'));
    }

    /**
     * Read the first text block and decode it. The schema guarantees valid
     * JSON, but a refusal or a truncated response would not carry one — both
     * are surfaced rather than silently returning empty fields.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     */
    protected function parse(object $message): array
    {
        if (($message->stopReason ?? null) === 'refusal') {
            throw new RuntimeException('The label image was declined by the extraction model.');
        }

        $json = null;
        foreach ($message->content as $block) {
            if (($block->type ?? null) === 'text') {
                $json = $block->text;
                break;
            }
        }

        if ($json === null) {
            throw new RuntimeException('Label extraction returned no text content.');
        }

        $data = json_decode($json, true);

        if (! is_array($data)) {
            throw new RuntimeException('Label extraction returned malformed JSON.');
        }

        return [
            'ref'           => $this->str($data['ref'] ?? null),
            'gtin'          => $this->str($data['gtin'] ?? null),
            'lot_number'    => $this->str($data['lot_number'] ?? null),
            'expiry_date'   => $this->str($data['expiry_date'] ?? null),
            'serial_number' => $this->str($data['serial_number'] ?? null),
            'confidence'    => (float) ($data['confidence'] ?? 0.0),
            'raw_text'      => (string) ($data['raw_text'] ?? ''),
        ];
    }

    /** Blank strings the model may return for a missing field read as null. */
    protected function str(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return trim($value) === '' ? null : trim($value);
    }

    protected function systemPrompt(): string
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

    /** @return array<string, mixed> */
    protected function schema(): array
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
}
