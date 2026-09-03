<?php

namespace Tests\Feature;

use App\Services\ClaudeVisionClient;
use App\Services\ScanExtractionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Everything about the vision path that can be verified without spending an
 * API call: the pre-flight guards, and how a bad or refused response is
 * handled. The extraction *quality* on real packaging still has to be judged
 * against a real photo — `php artisan surgical:test-label-ocr <file>`.
 */
class LabelExtractionGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected function client(): ClaudeVisionClient
    {
        return new class extends ClaudeVisionClient
        {
            /** @param array<string, mixed> $payload */
            public function parsePayload(array $payload): array
            {
                return $this->parse($this->fakeMessage($payload));
            }

            public function parseRaw(?string $text, ?string $stopReason = null): array
            {
                return $this->parse((object) [
                    'stopReason' => $stopReason,
                    'content'    => $text === null
                        ? []
                        : [(object) ['type' => 'text', 'text' => $text]],
                ]);
            }

            /** @param array<string, mixed> $payload */
            protected function fakeMessage(array $payload): object
            {
                return (object) [
                    'stopReason' => null,
                    'content'    => [(object) ['type' => 'text', 'text' => json_encode($payload)]],
                ];
            }
        };
    }

    /* ------------------------------------------------------------------ */
    /*  Configuration                                                      */
    /* ------------------------------------------------------------------ */

    /** Barcode scanning must keep working with no key configured. */
    public function test_barcode_path_works_without_an_api_key(): void
    {
        config(['surgical.ocr.api_key' => null]);

        $extraction = app(ScanExtractionService::class);

        $this->assertFalse($extraction->visionAvailable());
        $this->assertSame('11129D250603', $extraction->parseGs1('(10)11129D250603')['lot_number']);
    }

    /** An unconfigured key produces a message that says what to do. */
    public function test_unconfigured_vision_explains_itself(): void
    {
        config(['surgical.ocr.api_key' => null]);

        try {
            app(ScanExtractionService::class)->extractFromImage('bytes', 'image/jpeg');
            $this->fail('Expected an unconfigured-vision error.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ANTHROPIC_API_KEY', $e->getMessage());
            $this->assertStringContainsString('barcode scanning does not require it', $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Pre-flight guards — these run before any request is made           */
    /* ------------------------------------------------------------------ */

    public function test_unsupported_image_type_is_rejected(): void
    {
        config(['surgical.ocr.api_key' => 'sk-ant-test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported image type');

        $this->client()->extractLabel('bytes', 'image/heic');
    }

    /** 10 MB is the API's hard limit; refuse locally rather than round-trip. */
    public function test_oversized_image_is_rejected(): void
    {
        config(['surgical.ocr.api_key' => 'sk-ant-test']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('too large');

        $this->client()->extractLabel(str_repeat('x', 11 * 1024 * 1024), 'image/jpeg');
    }

    public function test_every_documented_format_is_accepted_by_the_guard(): void
    {
        config(['surgical.ocr.api_key' => 'sk-ant-test']);

        foreach (['image/jpeg', 'image/png', 'image/gif', 'image/webp'] as $mime) {
            try {
                $this->client()->extractLabel('bytes', $mime);
            } catch (RuntimeException $e) {
                // Past the guards is what matters; the request itself has no
                // network here, so anything other than a guard message is fine.
                $this->assertStringNotContainsString('Unsupported image type', $e->getMessage());
            } catch (\Throwable) {
                // A transport failure also means the guards passed.
            }
        }

        $this->addToAssertionCount(1);
    }

    /* ------------------------------------------------------------------ */
    /*  Response handling                                                  */
    /* ------------------------------------------------------------------ */

    public function test_reads_a_well_formed_extraction(): void
    {
        $out = $this->client()->parsePayload([
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
        $out = $this->client()->parsePayload([
            'ref' => '12012029', 'gtin' => '', 'lot_number' => '   ',
            'expiry_date' => null, 'serial_number' => '',
            'confidence' => 0.4, 'raw_text' => '',
        ]);

        $this->assertNull($out['gtin']);
        $this->assertNull($out['lot_number']);
        $this->assertNull($out['expiry_date']);
        $this->assertNull($out['serial_number']);
        $this->assertSame('', $out['raw_text']);
    }

    /** Values are trimmed — trailing whitespace would break lot matching. */
    public function test_values_are_trimmed(): void
    {
        $out = $this->client()->parsePayload([
            'ref' => '  12012029 ', 'gtin' => null, 'lot_number' => " 11129D250603\n",
            'expiry_date' => null, 'serial_number' => null,
            'confidence' => 1, 'raw_text' => '',
        ]);

        $this->assertSame('12012029', $out['ref']);
        $this->assertSame('11129D250603', $out['lot_number']);
    }

    /** A safety refusal must surface, not read as an empty label. */
    public function test_a_refusal_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('declined');

        $this->client()->parseRaw('{}', 'refusal');
    }

    /** A truncated or non-JSON body must not silently yield empty fields. */
    public function test_malformed_json_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('malformed JSON');

        $this->client()->parseRaw('{"ref": "12012029"');
    }

    public function test_a_response_with_no_text_block_is_surfaced(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no text content');

        $this->client()->parseRaw(null);
    }

    /** A number returned as a JSON string must still compare numerically. */
    public function test_confidence_is_always_a_float(): void
    {
        $out = $this->client()->parsePayload([
            'ref' => '1', 'gtin' => null, 'lot_number' => null, 'expiry_date' => null,
            'serial_number' => null, 'confidence' => '0.55', 'raw_text' => '',
        ]);

        $this->assertIsFloat($out['confidence']);
        $this->assertSame(0.55, $out['confidence']);
    }

    /** A missing confidence reads as zero, which routes the scan to a human. */
    public function test_missing_confidence_defaults_to_review(): void
    {
        $out = $this->client()->parseRaw(json_encode(['ref' => '12012029']));

        $this->assertSame(0.0, $out['confidence']);
        $this->assertLessThan((float) config('surgical.ocr.min_confidence'), $out['confidence']);
    }
}
