<?php

namespace Tests\Feature;

use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The two go-live checks: verifying the voucher sequence against the paper
 * pads, and reading one label to see what the system extracts.
 */
class OperationsCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function voucher(int $number, string $reference): Transfer
    {
        $user = User::firstOrCreate(
            ['email' => 'ops@test.test'],
            ['name' => 'Ops', 'password' => Hash::make('x'), 'is_active' => true],
        );

        return Transfer::create([
            'reference'      => $reference,
            'voucher_number' => (string) $number,
            'type'           => 'standard',
            'status'         => 'completed',
            'requested_by'   => $user->id,
            'transfer_date'  => '2026-07-20',
        ]);
    }

    /* ------------------------------------------------------------------ */
    /*  surgical:voucher-status                                            */
    /* ------------------------------------------------------------------ */

    /** With no number from operations, it says what still has to be asked. */
    public function test_voucher_status_reports_an_unconfirmed_seed(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $this->artisan('surgical:voucher-status')
            ->expectsOutputToContain('has not been checked against the paper pads')
            ->expectsOutputToContain('--paper-high=')
            ->assertSuccessful();
    }

    /** A seed above the paper pads is the all-clear. */
    public function test_voucher_status_passes_when_the_seed_clears_paper(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130118])
            ->expectsOutputToContain('Clear: the next voucher is 130119')
            ->assertSuccessful();
    }

    /** A seed at or below the pads fails, and names the value to set. */
    public function test_voucher_status_fails_when_the_seed_is_too_low(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130200])
            ->expectsOutputToContain('VOUCHER_START_NUMBER=130201')
            ->assertFailed();
    }

    /** Vouchers already issued inside the paper range are named individually. */
    public function test_voucher_status_lists_existing_collisions(): void
    {
        config(['surgical.voucher.start_number' => 130119]);
        $this->voucher(130119, 'TR-2026-000001');
        $this->voucher(130120, 'TR-2026-000002');
        $this->voucher(130130, 'TR-2026-000003'); // above the paper range

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130125])
            ->expectsOutputToContain('2 digital voucher(s) duplicate a paper number')
            ->assertFailed();
    }

    /** The boundary is inclusive: the paper number itself counts as taken. */
    public function test_voucher_status_treats_the_paper_high_as_taken(): void
    {
        config(['surgical.voucher.start_number' => 130119]);
        $this->voucher(130125, 'TR-2026-000004');

        // 130125 is issued digitally and also on paper — a real duplicate.
        $this->artisan('surgical:voucher-status', ['--paper-high' => 130125])
            ->assertFailed();

        // Paper stopped at 130124, so nothing clashes: the sequence has
        // already run to 130126, past the paper range.
        $this->artisan('surgical:voucher-status', ['--paper-high' => 130124])
            ->expectsOutputToContain('the next voucher is 130126')
            ->assertSuccessful();
    }

    /**
     * A seed inside the paper range is only a warning once the sequence has
     * outrun it — but it still has to be corrected, or a rebuilt database
     * would restart inside the range.
     */
    public function test_voucher_status_still_flags_a_stale_seed(): void
    {
        config(['surgical.voucher.start_number' => 130119]);
        $this->voucher(130130, 'TR-2026-000006');

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130124])
            ->expectsOutputToContain('sits inside the paper range')
            ->expectsOutputToContain('VOUCHER_START_NUMBER=130125')
            ->assertSuccessful();
    }

    /** With nothing issued, the raw seed is the next number and must clear. */
    public function test_voucher_status_fails_on_a_low_seed_with_no_vouchers(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130119])
            ->expectsOutputToContain('would duplicate a paper number')
            ->assertFailed();
    }

    public function test_voucher_status_rejects_a_non_numeric_paper_high(): void
    {
        $this->artisan('surgical:voucher-status', ['--paper-high' => 'about 130k'])
            ->expectsOutputToContain('plain number')
            ->assertFailed();
    }

    /** Soft-deleted transfers still hold their number and must be counted. */
    public function test_voucher_status_counts_trashed_vouchers(): void
    {
        config(['surgical.voucher.start_number' => 130119]);
        $this->voucher(130119, 'TR-2026-000005')->delete();

        $this->artisan('surgical:voucher-status', ['--paper-high' => 130125])
            ->expectsOutputToContain('1 digital voucher(s) duplicate')
            ->assertFailed();
    }

    /* ------------------------------------------------------------------ */
    /*  surgical:test-label-ocr                                            */
    /* ------------------------------------------------------------------ */

    /** The barcode path needs no API key, and resolves against the catalogue. */
    public function test_label_ocr_reads_a_barcode_and_resolves_the_item(): void
    {
        config(['surgical.ocr.api_key' => null]);
        StockItem::create(['name' => '29 Circular', 'catalogue_number' => '12012029']);

        $this->artisan('surgical:test-label-ocr', [
            '--barcode' => '(240)12012029(10)11129D250603(17)270603',
        ])
            ->expectsOutputToContain('11129D250603')
            ->expectsOutputToContain('2027-06-03')
            ->expectsOutputToContain('Resolved to "29 Circular" on catalogue_number')
            ->assertSuccessful();
    }

    /** An unresolvable code is reported as such rather than failing quietly. */
    public function test_label_ocr_reports_an_unresolved_code(): void
    {
        $this->artisan('surgical:test-label-ocr', ['--barcode' => '(240)NOT-A-REF(10)L1'])
            ->expectsOutputToContain('No catalogue match')
            ->assertSuccessful();
    }

    /** A photo without a configured key says so, and points at the barcode path. */
    public function test_label_ocr_explains_a_missing_api_key(): void
    {
        config(['surgical.ocr.api_key' => null]);
        $path = sys_get_temp_dir().'/label-'.uniqid().'.jpg';
        file_put_contents($path, "\xff\xd8\xff\xe0");

        try {
            $this->artisan('surgical:test-label-ocr', ['file' => $path])
                ->expectsOutputToContain('Vision OCR is not configured')
                ->expectsOutputToContain('ANTHROPIC_API_KEY')
                ->assertFailed();
        } finally {
            @unlink($path);
        }
    }

    public function test_label_ocr_requires_an_input(): void
    {
        $this->artisan('surgical:test-label-ocr')
            ->expectsOutputToContain('Give a photo path')
            ->assertFailed();
    }

    public function test_label_ocr_reports_a_missing_file(): void
    {
        $this->artisan('surgical:test-label-ocr', ['file' => '/nope/label.jpg'])
            ->expectsOutputToContain('No such file')
            ->assertFailed();
    }

    public function test_label_ocr_rejects_an_unparseable_barcode(): void
    {
        $this->artisan('surgical:test-label-ocr', ['--barcode' => 'not-a-barcode'])
            ->assertFailed();
    }

    /** --json emits the raw extraction for piping into other checks. */
    public function test_label_ocr_can_emit_json(): void
    {
        $this->artisan('surgical:test-label-ocr', [
            '--barcode' => '(10)11129D250603',
            '--json'    => true,
        ])
            ->expectsOutputToContain('"lot_number": "11129D250603"')
            ->assertSuccessful();
    }
}
