<?php

namespace App\Services\Vision;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Label reading via OpenAI's chat completions API.
 *
 * Uses the HTTP client Laravel already ships rather than adding an SDK: the
 * call is one POST, and a dependency that exists only to wrap it would be more
 * to keep current than it saves.
 *
 * The answer is constrained by a JSON schema (`strict: true`), so the model
 * cannot reply in prose and nothing has to be scraped out of free text.
 */
class OpenAiVisionClient implements LabelVisionClient
{
    public function providerName(): string
    {
        return 'openai';
    }

    public function isConfigured(): bool
    {
        return filled(config('surgical.ocr.openai.api_key'));
    }

    public function extractLabel(string $binary, string $mime): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'OpenAI label reading is not configured. Set OPENAI_API_KEY to read labels from '
                .'photos; barcode scanning does not require it.'
            );
        }

        LabelPrompt::assertUsableImage($binary, $mime);

        $response = Http::withToken((string) config('surgical.ocr.openai.api_key'))
            ->timeout((int) config('surgical.ocr.timeout', 45))
            ->retry(2, 500, throw: false)
            ->post(rtrim((string) config('surgical.ocr.openai.base_url'), '/').'/chat/completions', [
                'model' => (string) config('surgical.ocr.openai.model'),
                'messages' => [
                    ['role' => 'system', 'content' => LabelPrompt::system()],
                    ['role' => 'user', 'content' => [
                        // Image before text: these models read image-then-question best.
                        ['type' => 'image_url', 'image_url' => [
                            'url' => 'data:'.$mime.';base64,'.base64_encode($binary),
                            // The label fills the frame and the type is small;
                            // low detail would down-sample exactly the digits
                            // that must not be misread.
                            'detail' => 'high',
                        ]],
                        ['type' => 'text', 'text' => LabelPrompt::instruction()],
                    ]],
                ],
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name'   => 'device_label',
                        'strict' => true,
                        'schema' => LabelPrompt::schema(),
                    ],
                ],
                'max_tokens' => 2048,
            ]);

        if ($response->failed()) {
            throw new RuntimeException($this->describeFailure($response->status(), $response->json()));
        }

        return LabelPrompt::normalise($this->decode($response->json()));
    }

    /**
     * Pull the JSON answer out of the completion.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>
     */
    protected function decode(?array $body): array
    {
        $choice = $body['choices'][0] ?? null;

        // A refusal arrives as a sibling of `content`, not as an error status.
        if (filled($choice['message']['refusal'] ?? null)) {
            throw new RuntimeException('The label image was declined by the extraction model.');
        }

        if (($choice['finish_reason'] ?? null) === 'length') {
            throw new RuntimeException('Label extraction was cut off before it finished.');
        }

        $content = $choice['message']['content'] ?? null;

        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('Label extraction returned no content.');
        }

        $data = json_decode($content, true);

        if (! is_array($data)) {
            throw new RuntimeException('Label extraction returned malformed JSON.');
        }

        return $data;
    }

    /**
     * Turn an HTTP failure into something the person holding the phone can act
     * on. "Server Error" tells a rep in a store room nothing.
     *
     * @param  array<string, mixed>|null  $body
     */
    protected function describeFailure(int $status, ?array $body): string
    {
        $detail = $body['error']['message'] ?? null;

        return match (true) {
            $status === 401 => 'The OpenAI API key was rejected. Check OPENAI_API_KEY.',
            $status === 429 => 'OpenAI is rate limiting or the account is out of credit. '
                .'Barcode scanning still works; try the photo again shortly.',
            $status >= 500  => 'OpenAI is unavailable right now. Barcode scanning still works.',
            default         => 'Label extraction failed'.($detail ? ": {$detail}" : " (HTTP {$status}).") ,
        };
    }

    /** Cheap reachability probe for the CLI check; never throws. */
    public function ping(): bool
    {
        try {
            return Http::withToken((string) config('surgical.ocr.openai.api_key'))
                ->timeout(10)
                ->get(rtrim((string) config('surgical.ocr.openai.base_url'), '/').'/models')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
