<?php

namespace App\Console\Commands;

use App\Models\StockCountItem;
use App\Models\StockItem;
use App\Services\ScanExtractionService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Validation harness for label reading.
 *
 * The vision prompt can only be judged against real packaging — foil glare,
 * curved surfaces, 5pt type. This runs one label through the extraction and
 * prints both what was read and how the catalogue lookup would resolve it, so
 * a photo taken on a phone can be checked in one command:
 *
 *   php artisan surgical:test-label-ocr storage/labels/circular.jpg
 *   php artisan surgical:test-label-ocr --barcode='(01)0345…(10)11129D250603'
 *
 * A barcode string needs no API key; a photo needs ANTHROPIC_API_KEY and
 * spends a request (roughly 1.5K visual tokens for a 1600px label).
 */
class TestLabelOcr extends Command
{
    protected $signature = 'surgical:test-label-ocr
                            {file? : Path to a label photo (JPEG/PNG/GIF/WebP)}
                            {--barcode= : A decoded GS1 string, to test the parser without an API call}
                            {--json : Print the raw extraction as JSON}';

    protected $description = 'Read one device label and show what the system extracts from it.';

    public function handle(ScanExtractionService $extraction): int
    {
        $barcode = $this->option('barcode');
        $file = $this->argument('file');

        if (blank($barcode) && blank($file)) {
            $this->components->error('Give a photo path, or --barcode to test the GS1 parser.');

            return self::FAILURE;
        }

        try {
            [$result, $source] = blank($barcode)
                ? [$this->fromPhoto($extraction, $file), 'vision']
                : [$extraction->parseGs1($barcode), 'barcode'];
        } catch (Throwable $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result === null) {
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->render($result, $source);

        return self::SUCCESS;
    }

    /** @return array<string, mixed>|null */
    protected function fromPhoto(ScanExtractionService $extraction, string $file): ?array
    {
        if (! is_file($file)) {
            $this->components->error("No such file: {$file}");

            return null;
        }

        if (! $extraction->visionAvailable()) {
            $this->components->error('Vision OCR is not configured.');
            $this->line('  Set ANTHROPIC_API_KEY in .env to read labels from photos.');
            $this->line('  Barcode scanning works without it — try --barcode instead.');

            return null;
        }

        $bytes = (string) file_get_contents($file);
        $mime = (string) (mime_content_type($file) ?: 'image/jpeg');

        $this->components->info(sprintf(
            'Reading %s (%s, %s KB)…', basename($file), $mime, number_format(strlen($bytes) / 1024, 0)
        ));

        return $extraction->extractFromImage($bytes, $mime);
    }

    /** @param array<string, mixed> $r */
    protected function render(array $r, string $source): void
    {
        $show = fn (?string $v) => blank($v) ? '<fg=red>not read</>' : "<options=bold>{$v}</>";

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Source</>', $source);
        $this->components->twoColumnDetail('<fg=gray>REF</>', $show($r['ref'] ?? null));
        $this->components->twoColumnDetail('<fg=gray>GTIN</>', $show($r['gtin'] ?? null));
        $this->components->twoColumnDetail('<fg=gray>Lot</>', $show($r['lot_number'] ?? null));
        $this->components->twoColumnDetail('<fg=gray>Expiry</>', $show($r['expiry_date'] ?? null));
        $this->components->twoColumnDetail('<fg=gray>Serial</>', $show($r['serial_number'] ?? null));

        $confidence = (float) ($r['confidence'] ?? 0);
        $threshold = (float) config('surgical.ocr.min_confidence', 0.8);
        $this->components->twoColumnDetail(
            '<fg=gray>Confidence</>',
            $confidence >= $threshold
                ? '<fg=green>'.number_format($confidence * 100, 0).'%</>'
                : '<fg=yellow>'.number_format($confidence * 100, 0).'% — would be held for review</>',
        );
        $this->line('');

        $this->resolveAgainstCatalogue($r);

        if (filled($r['raw_text'] ?? null)) {
            $this->line('<fg=gray>All text read from the label:</>');
            $this->line('  '.str_replace("\n", "\n  ", trim((string) $r['raw_text'])));
            $this->line('');
        }
    }

    /**
     * Mirror the resolution order the scan service uses, so a failed lookup is
     * visible here rather than only showing up as an "unresolved" scan.
     *
     * @param  array<string, mixed>  $r
     */
    protected function resolveAgainstCatalogue(array $r): void
    {
        $gtin = $r['gtin'] ?? null;
        $ref = $r['ref'] ?? null;

        $item = filled($gtin) ? StockItem::where('gtin', $gtin)->first() : null;
        $matchedOn = $item ? 'gtin' : null;

        if (! $item && filled($ref)) {
            $item = StockItem::where('catalogue_number', $ref)->first();
            $matchedOn = $item ? 'catalogue_number' : null;

            if (! $item) {
                $item = StockItem::where('item_code', $ref)->first();
                $matchedOn = $item ? 'item_code' : null;
            }
        }

        if (! $item) {
            $this->components->warn('No catalogue match — this scan would be held for manual entry.');
            $this->line('  Confirming it in the app with the right REF also teaches the GTIN,');
            $this->line('  so the next scan of this product resolves straight off the barcode.');
            $this->line('');

            return;
        }

        $this->components->info("Resolved to \"{$item->name}\" on {$matchedOn}.");

        $lot = StockCountItem::normalizeLot($r['lot_number'] ?? null);
        if ($lot !== null) {
            $this->line("  <fg=gray>Lot normalises to</> <options=bold>{$lot}</> <fg=gray>for matching.</>");
        }
        if (blank($item->gtin) && filled($gtin)) {
            $this->line("  <fg=gray>This item has no GTIN stored yet; confirming a scan would save</> {$gtin}<fg=gray>.</>");
        }
        $this->line('');
    }
}
