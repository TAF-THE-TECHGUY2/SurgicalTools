<?php

namespace Tests\Feature;

use App\Services\ClaudeVisionClient;
use App\Services\ScanExtractionService;
use App\Services\Vision\LabelPrompt;
use App\Services\Vision\LabelVisionClient;
use App\Services\Vision\OpenAiVisionClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * Everything about the photo-reading fallback that can be verified without
 * spending a real API call: the pre-flight guards, how each provider's bad or
 * refused answer is handled, and that both providers normalise a reading the
 * same way.
 *
 * The extraction *quality* on real packaging still has to be judged against a
 * real photo — `php artisan surgical:test-label-ocr <file>`.
 */
class LabelExtractionGuardsTest extends TestCase
{
    use RefreshDatabase;

    /** Exposes the Anthropic client's decode step for direct testing. */
    protected function claude(): ClaudeVisionClient
    {
        return new class extends ClaudeVisionClient
        {
            public function decodeRaw(?string $text, ?string $stopReason = null): array
            {
                return $this->decode((object) [
                    'stopReason' => $stopReason,
                    'content'    => $text === null
                        ? []
                        : [(object) ['type' => 'text', 'text' => $text]],
                ]);
            }
        };
    }

    /** @param array<string, mixed> $payload */
    protected function fakeOpenAi(array $payload, int $status = 200): void
    {
        Http::fake(['*/chat/completions' => Http::response($payload, $status)]);
    }

    /** @return array<string, mixed> */
    protected function completion(mixed $content, array $extra = []): array
    {
        return ['choices' => [array_merge([
            'finish_reason' => 'stop',
            'message'       => ['content' => is_string($content) ? $content : json_encode($content)],
        ], $extra)]];
    }

    /* ------------------------------------------------------------------ */
    /*  Provider selection                                                 */
    /* ------------------------------------------------------------------ */

    public function test_provider_is_chosen_by_configuration(): void
    {
        config(['surgical.ocr.provider' => 'openai']);
        $this->assertInstanceOf(OpenAiVisionClient::class, app(LabelVisionClient::class));

        config(['surgical.ocr.provider' => 'anthropic']);
        $this->assertInstanceOf(ClaudeVisionClient::class, app(LabelVisionClient::class));
    }

    /** An unrecognised value falls back rather than failing to boot. */
    public function test_an_unknown_provider_falls_back_to_anthropic(): void
    {
        config(['surgical.ocr.provider' => 'nonsense']);
        $this->assertInstanceOf(ClaudeVisionClient::class, app(LabelVisionClient::class));
    }

    /* ------------------------------------------------------------------ */
    /*  Configuration                                                      */
    /* ------------------------------------------------------------------ */

    /** Barcode scanning must keep working with no provider configured. */
    public function test_barcode_path_works_without_any_api_key(): void
    {
        config(['surgical.ocr.api_key' => null, 'surgical.ocr.openai.api_key' => null]);

        $extraction = app(ScanExtractionService::class);

        $this->assertFalse($extraction->visionAvailable());
        $this->assertSame('11129D250603', $extraction->parseGs1('(10)11129D250603')['lot_number']);
    }

    public function test_unconfigured_anthropic_explains_itself(): void
    {
        config(['surgical.ocr.api_key' => null]);

        try {
            $this->claude()->extractLabel('bytes', 'image/jpeg');
            $this->fail('Expected an unconfigured error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
            $this->assertStringContainsString('barcode scanning does not require it', $e->getMessage());
        }
    }

    public function test_unconfigured_openai_explains_itself(): void
    {
        config(['surgical.ocr.openai.api_key' => null]);

        try {
            (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
            $this->fail('Expected an unconfigured error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('OPENAI_API_KEY', $e->getMessage());
            $this->assertStringContainsString('barcode scanning does not require it', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pre-flight guards — run before any request is made                 */
    /* ------------------------------------------------------------------ */

    public function test_unsupported_image_type_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');

        LabelPrompt::assertUsableImage('bytes', 'image/heic');
    }

    public function test_oversized_image_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        LabelPrompt::assertUsableImage(str_repeat('x', 11 * 1024 * 1024), 'image/jpeg');
    }

    public function test_every_documented_format_passes_the_guard(): void
    {
        foreach (LabelPrompt::SUPPORTED_MIMES as $mime) {
            LabelPrompt::assertUsableImage('bytes', $mime);
        }

        $this->addToAssertionCount(1);
    }

    /** The guards run before the network, so a bad image costs nothing. */
    public function test_a_bad_image_never_reaches_the_provider(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        Http::fake();

        try {
            (new OpenAiVisionClient)->extractLabel('bytes', 'image/heic');
        } catch (RuntimeException) {
            // expected
        }

        Http::assertNothingSent();
    }

    /* ------------------------------------------------------------------ */
    /*  Shared normalisation — identical for every provider                */
    /* ------------------------------------------------------------------ */

    public function test_reads_a_well_formed_extraction(): void
    {
        $out = LabelPrompt::normalise([
            'ref' => '12012029', 'gtin' => '03456789012345',
            'lot_number' => '11129D250603', 'expiry_date' => '2027-06-03',
            'serial_number' => 'SN7', 'confidence' => 0.93, 'raw_text' => 'REF 12012029',
        ]);

        $this->assertSame('12012029', $out['ref']);
        $this->assertSame('11129D250603', $out['lot_number']);
        $this->assertSame('2027-06-03', $out['expiry_date']);
        $this->assertSame(0.93, $out['confidence']);
    }

    /**
     * A field the model could not read comes back blank or null. Both must
     * become null, so an empty string is never matched as a lot number.
     */
    public function test_blank_fields_become_null(): void
    {
        $out = LabelPrompt::normalise([
            'ref' => '12012029', 'gtin' => '', 'lot_number' => '   ',
            'expiry_date' => null, 'serial_number' => '',
            'confidence' => 0.4, 'raw_text' => '',
        ]);

        $this->assertNull($out['gtin']);
        $this->assertNull($out['lot_number']);
        $this->assertNull($out['expiry_date']);
        $this->assertNull($out['serial_number']);
    }

    /** Trailing whitespace would break lot matching. */
    public function test_values_are_trimmed(): void
    {
        $out = LabelPrompt::normalise([
            'ref' => '  12012029 ', 'lot_number' => " 11129D250603\n",
            'confidence' => 1, 'raw_text' => '',
        ]);

        $this->assertSame('12012029', $out['ref']);
        $this->assertSame('11129D250603', $out['lot_number']);
    }

    /** A number returned as a JSON string must still compare numerically. */
    public function test_confidence_is_always_a_float(): void
    {
        $out = LabelPrompt::normalise(['confidence' => '0.55']);

        $this->assertIsFloat($out['confidence']);
        $this->assertSame(0.55, $out['confidence']);
    }

    /** A missing confidence reads as zero, which routes the scan to a human. */
    public function test_missing_confidence_defaults_to_review(): void
    {
        $out = LabelPrompt::normalise(['ref' => '12012029']);

        $this->assertSame(0.0, $out['confidence']);
        $this->assertLessThan((float) config('surgical.ocr.min_confidence'), $out['confidence']);
    }

    /* ------------------------------------------------------------------ */
    /*  Anthropic response handling                                        */
    /* ------------------------------------------------------------------ */

    public function test_anthropic_refusal_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');

        $this->claude()->decodeRaw('{}', 'refusal');
    }

    public function test_anthropic_malformed_json_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $this->claude()->decodeRaw('{"ref": "12012029"');
    }

    public function test_anthropic_response_with_no_text_block_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no text content');

        $this->claude()->decodeRaw(null);
    }

    /* ------------------------------------------------------------------ */
    /*  OpenAI response handling                                           */
    /* ------------------------------------------------------------------ */

    public function test_openai_reads_a_label(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi($this->completion([
            'ref' => '12012029', 'gtin' => null, 'lot_number' => '11129D250603',
            'expiry_date' => '2027-06-03', 'serial_number' => null,
            'confidence' => 0.91, 'raw_text' => 'REF 12012029 LOT 11129D250603',
        ]));

        $out = (new OpenAiVisionClient)->extractLabel('jpeg-bytes', 'image/jpeg');

        $this->assertSame('12012029', $out['ref']);
        $this->assertSame('11129D250603', $out['lot_number']);
        $this->assertSame(0.91, $out['confidence']);
    }

    /** The request carries the image, the schema, and high detail. */
    public function test_openai_request_shape(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test', 'surgical.ocr.openai.model' => 'gpt-4o']);
        $this->fakeOpenAi($this->completion(['ref' => '1', 'confidence' => 1, 'raw_text' => '']));

        (new OpenAiVisionClient)->extractLabel('jpeg-bytes', 'image/jpeg');

        Http::assertSent(function ($request) {
            $body = $request->data();
            $content = $body['messages'][1]['content'];

            return $body['model'] === 'gpt-4o'
                && $body['response_format']['type'] === 'json_schema'
                && $body['response_format']['json_schema']['strict'] === true
                // Small type on a curved label: low detail would down-sample
                // exactly the digits that must not be misread.
                && $content[0]['image_url']['detail'] === 'high'
                && str_starts_with($content[0]['image_url']['url'], 'data:image/jpeg;base64,')
                && $request->hasHeader('Authorization', 'Bearer sk-test');
        });
    }

    public function test_openai_refusal_is_surfaced(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi(['choices' => [[
            'finish_reason' => 'stop',
            'message' => ['refusal' => 'I cannot help with that.'],
        ]]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');

        (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
    }

    /** A truncated answer must not be read as a label with missing fields. */
    public function test_openai_truncated_answer_is_surfaced(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi($this->completion('{"ref":"120', ['finish_reason' => 'length']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cut off');

        (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
    }

    public function test_openai_malformed_json_is_surfaced(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi($this->completion('{"ref": "12012029"'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
    }

    public function test_openai_empty_content_is_surfaced(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi($this->completion(''));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no content');

        (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
    }

    /**
     * HTTP failures are reported in terms a rep in a store room can act on,
     * and say that barcode scanning is unaffected.
     */
    public function test_openai_rejected_key_names_the_setting(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-bad']);
        $this->fakeOpenAi(['error' => ['message' => 'Incorrect API key']], 401);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OPENAI_API_KEY');

        (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
    }

    public function test_openai_rate_limit_mentions_barcode_still_works(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi(['error' => ['message' => 'Rate limit']], 429);

        try {
            (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
            $this->fail('Expected a rate-limit error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Barcode scanning still works', $e->getMessage());
        }
    }

    public function test_openai_outage_mentions_barcode_still_works(): void
    {
        config(['surgical.ocr.openai.api_key' => 'sk-test']);
        $this->fakeOpenAi(['error' => ['message' => 'oops']], 503);

        try {
            (new OpenAiVisionClient)->extractLabel('bytes', 'image/jpeg');
            $this->fail('Expected an outage error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Barcode scanning still works', $e->getMessage());
        }
    }
}
