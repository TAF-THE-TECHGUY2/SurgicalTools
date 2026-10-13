<?php

namespace App\Services\Vision;

/**
 * Reads the identifying fields off a medical-device label photo.
 *
 * This is the fallback path. Barcodes are the primary one — a GS1 DataMatrix
 * decodes exactly, offline, at no cost — and nothing here is needed for
 * scanning to work. Vision is only reached when a barcode is damaged, missing
 * or unreadable.
 *
 * Implementations exist for more than one provider so the choice follows
 * whichever account the operator already has, rather than forcing a new one.
 */
interface LabelVisionClient
{
    /** Whether this provider has the configuration it needs to run. */
    public function isConfigured(): bool;

    /** Short name for logs and the CLI, e.g. "anthropic" or "openai". */
    public function providerName(): string;

    /**
     * Read one label.
     *
     * Returns the same shape as the GS1 parser, so the matching rule
     * downstream cannot tell which produced it. A field that could not be read
     * is null — never guessed, never filled in from another field.
     *
     * @return array{ref: ?string, gtin: ?string, lot_number: ?string, expiry_date: ?string, serial_number: ?string, confidence: float, raw_text: string}
     *
     * @throws \RuntimeException when unconfigured, when the image is unusable,
     *                           or when the provider's answer cannot be trusted
     */
    public function extractLabel(string $binary, string $mime): array;
}
