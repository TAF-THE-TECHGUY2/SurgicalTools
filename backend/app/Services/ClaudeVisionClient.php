<?php

namespace App\Services;

use Anthropic\Client;
use Anthropic\Messages\Base64ImageSource;
use Anthropic\Messages\ImageBlockParam;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\TextBlockParam;
use App\Services\Vision\LabelPrompt;
use App\Services\Vision\LabelVisionClient;
use RuntimeException;

/**
 * Label reading via Anthropic's Messages API, used only when no barcode could
 * be decoded. Returns the same shape as the GS1 parser.
 *
 * The response is constrained by a JSON schema, so the model cannot answer in
 * prose and the result never needs to be scraped out of free text. The prompt
 * and schema themselves live in LabelPrompt, shared with every other provider
 * so the same label cannot read differently depending on which account is
 * configured.
 */
class ClaudeVisionClient implements LabelVisionClient
{
    protected ?Client $client = null;

    public function providerName(): string
    {
        return 'anthropic';
    }

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
                'Anthropic label reading is not configured. Set ANTHROPIC_API_KEY to read labels '
                .'from photos; barcode scanning does not require it.'
            );
        }

        LabelPrompt::assertUsableImage($binary, $mime);

        $message = $this->client()->messages->create(
            model: (string) config('surgical.ocr.model', 'claude-opus-5'),
            maxTokens: 2048,
            // The instructions are identical on every scan, so caching the
            // prefix keeps repeat calls during a count cheap.
            system: [TextBlockParam::with(
                text: LabelPrompt::system(),
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
                    TextBlockParam::with(text: LabelPrompt::instruction()),
                ],
            )],
            outputConfig: OutputConfig::with(
                format: JSONOutputFormat::with(schema: LabelPrompt::schema()),
            ),
            requestOptions: ['timeout' => (int) config('surgical.ocr.timeout', 45)],
        );

        return LabelPrompt::normalise($this->decode($message));
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
     * @return array<string, mixed>
     */
    protected function decode(object $message): array
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

        return $data;
    }
}
