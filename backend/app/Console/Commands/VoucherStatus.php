<?php

namespace App\Console\Commands;

use App\Models\Transfer;
use App\Support\ReferenceGenerator;
use Illuminate\Console\Command;

/**
 * Go-live gate for the delivery-voucher sequence.
 *
 * The digital vouchers continue the numbering of the physical pads, so the
 * seed has to sit above every number still outstanding in a rep's car. That
 * fact lives in the stationery cupboard, not the codebase — this command makes
 * the current state visible, checks a number supplied by operations against
 * it, and reports any collision that has already happened.
 *
 *   php artisan surgical:voucher-status
 *   php artisan surgical:voucher-status --paper-high=130250
 */
class VoucherStatus extends Command
{
    protected $signature = 'surgical:voucher-status
                            {--paper-high= : Highest voucher number issued or outstanding on paper}';

    protected $description = 'Report the delivery-voucher sequence and check it against the paper pads.';

    public function handle(): int
    {
        $seed = (int) config('surgical.voucher.start_number', 0);
        $issued = $this->issuedNumbers();
        $highestIssued = $issued->max();
        $next = ReferenceGenerator::nextSerial(Transfer::class, 'voucher_number', $seed);

        $this->line('');
        $this->components->twoColumnDetail('<fg=gray>Configured seed</>', (string) $seed);
        $this->components->twoColumnDetail(
            '<fg=gray>Digital vouchers issued</>',
            $issued->isEmpty() ? 'none yet' : $issued->count()." (up to {$highestIssued})",
        );
        $this->components->twoColumnDetail('<fg=gray>Next voucher number</>', $next);
        $this->line('');

        $paperHigh = $this->option('paper-high');

        if ($paperHigh === null) {
            return $this->reportUnconfirmed($seed, $issued->isEmpty());
        }

        if (! ctype_digit((string) $paperHigh)) {
            $this->components->error('--paper-high must be a plain number, e.g. --paper-high=130250');

            return self::FAILURE;
        }

        return $this->checkAgainstPaper((int) $paperHigh, $seed, (int) $next, $issued);
    }

    /** No paper number given — say what still has to be established. */
    protected function reportUnconfirmed(int $seed, bool $noneIssued): int
    {
        $this->components->warn('The seed has not been checked against the paper pads.');
        $this->line('  Ask operations one question:');
        $this->line('');
        $this->line('    <options=bold>"What is the highest voucher number on any pad that has been');
        $this->line('     issued or is still out with a rep?"</>');
        $this->line('');
        $this->line('  Then re-run with that number:');
        $this->line('    <fg=cyan>php artisan surgical:voucher-status --paper-high=NNNNNN</>');
        $this->line('');

        if ($noneIssued) {
            $this->line('  <fg=gray>Nothing has been issued digitally yet, so the seed can still be</>');
            $this->line('  <fg=gray>changed freely via VOUCHER_START_NUMBER.</>');
        } else {
            $this->line("  <fg=yellow>Vouchers have already been issued from seed {$seed}. If the paper</>");
            $this->line('  <fg=yellow>pads overlap, this command will list the affected vouchers.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /**
     * A number from operations — verify the sequence and report any overlap.
     *
     * The check is against the *next* number to be issued, not the raw seed:
     * once vouchers exist the sequence continues from the highest one, so a
     * seed that now sits inside the paper range is harmless if the sequence
     * has already run past it.
     */
    protected function checkAgainstPaper(int $paperHigh, int $seed, int $next, $issued): int
    {
        $safeSeed = $paperHigh + 1;
        $collisions = $issued->filter(fn (int $n) => $n <= $paperHigh)->values();

        if ($collisions->isNotEmpty()) {
            $this->components->error(
                $collisions->count().' digital voucher(s) duplicate a paper number.'
            );
            $this->table(
                ['Voucher', 'Reference', 'Date', 'Destination'],
                Transfer::withTrashed()
                    ->whereIn('voucher_number', $collisions->map(fn ($n) => (string) $n)->all())
                    ->with('toLocation')
                    ->orderBy('voucher_number')
                    ->get()
                    ->map(fn (Transfer $t) => [
                        $t->voucher_number,
                        $t->reference,
                        optional($t->transfer_date)->format('d/m/Y') ?? '—',
                        $t->toLocation?->name ?? '—',
                    ])
                    ->all(),
            );
            $this->line('  These need reconciling with the paper records by hand — the digital');
            $this->line("  and written vouchers share a number. Raise VOUCHER_START_NUMBER to {$safeSeed}");
            $this->line('  so no further duplicates are issued.');
            $this->line('');

            return self::FAILURE;
        }

        if ($next <= $paperHigh) {
            $this->components->error("The next voucher ({$next}) would duplicate a paper number ({$paperHigh}).");
            $this->line('  Nothing has clashed yet. Set <fg=cyan>VOUCHER_START_NUMBER='.$safeSeed.'</> before going live.');
            $this->line('');

            return self::FAILURE;
        }

        $this->components->info("Clear: the next voucher is {$next}, above the highest paper number ({$paperHigh}).");

        if ($seed <= $paperHigh) {
            // Harmless now, but a fresh database would restart inside the
            // paper range — worth correcting while it costs nothing.
            $this->line("  <fg=gray>Note: the configured seed ({$seed}) sits inside the paper range. The</>");
            $this->line("  <fg=gray>sequence has run past it, but set VOUCHER_START_NUMBER={$safeSeed} so a</>");
            $this->line('  <fg=gray>rebuilt database cannot restart inside it.</>');
        }

        $this->line('');

        return self::SUCCESS;
    }

    /** Purely numeric voucher numbers already issued, as integers. */
    protected function issuedNumbers()
    {
        return Transfer::withTrashed()
            ->whereNotNull('voucher_number')
            ->pluck('voucher_number')
            ->map(fn ($v) => (string) $v)
            ->filter(fn (string $v) => ctype_digit($v))
            ->map(fn (string $v) => (int) $v)
            ->sort()
            ->values();
    }
}
