<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Jobs\SendStockCountDiscrepancyDigest;
use App\Mail\StockCountDiscrepancyDigestMail;
use App\Mail\StockCountSummaryMail;
use App\Models\Document;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockCountScan;
use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\StockCountDiscrepancyNotification;
use App\Services\PdfService;
use App\Services\StockCountScanService;
use App\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 3: the discrepancy digest, the generalised PDF layer and the Final
 * Summary Report.
 */
class StockCountReportingTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $rep;

    protected Location $boot;

    protected StockItem $circular;

    protected StockCount $count;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@report.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@report.test');

        $this->boot = Location::create([
            'name' => 'Mike Boot', 'type' => 'boot', 'owner_user_id' => $this->rep->id,
        ]);

        $this->circular = StockItem::create([
            'name' => '29 Circular', 'catalogue_number' => '12012029',
        ]);
        foreach (range(1, 3) as $i) {
            $this->circular->units()->create([
                'serial_number' => "A{$i}", 'lot_number' => '11129D250603',
                'expiry_date' => '2027-06-03', 'location_id' => $this->boot->id, 'status' => 'available',
            ]);
        }

        $this->count = app(StockCountService::class)->create(
            ['location_id' => $this->boot->id, 'assigned_to' => $this->rep->id],
            $this->admin,
        );
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        $u = User::create([
            'name' => $email, 'email' => $email,
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $u->assignRole($role->value);

        return $u;
    }

    protected function scan(?string $lot, ?string $ref = '12012029', ?string $expiry = null): StockCountScan
    {
        return app(StockCountScanService::class)->record(
            $this->count,
            [
                'ref' => $ref, 'gtin' => null, 'lot_number' => $lot,
                'expiry_date' => $expiry, 'serial_number' => null,
                'confidence' => 1.0, 'raw_text' => '',
            ],
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Discrepancy email throttling                                       */
    /* ------------------------------------------------------------------ */

    /** The first discrepancy mails at once; no digest is queued for it. */
    public function test_first_discrepancy_mails_immediately_without_a_digest(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        Queue::fake();

        $this->scan('ROGUE-A');

        Notification::assertSentTo(
            $this->admin,
            StockCountDiscrepancyNotification::class,
            fn ($n) => $n->via($this->admin) === ['database', 'mail'],
        );
        Queue::assertNotPushed(SendStockCountDiscrepancyDigest::class);

        // Marked so the digest cannot repeat it.
        $this->assertNotNull($this->count->items()->adjustments()->firstOrFail()->digest_notified_at);
    }

    /** Later discrepancies notify in-app and coalesce into a single digest. */
    public function test_later_discrepancies_coalesce_into_one_digest(): void
    {
        config(['queue.default' => 'database']);
        Notification::fake();
        Queue::fake();

        $this->scan('ROGUE-A');
        $this->scan('ROGUE-B');
        $this->scan('ROGUE-C');

        // Two further discrepancies, one queued digest — ShouldBeUnique drops
        // the repeat dispatch rather than queueing a second email.
        Queue::assertPushed(SendStockCountDiscrepancyDigest::class, 1);

        $pending = $this->count->items()->adjustments()->whereNull('digest_notified_at')->count();
        $this->assertSame(2, $pending);
    }

    /** ShouldBeUnique keeps one pending digest per count. */
    public function test_digest_job_is_unique_per_count(): void
    {
        $job = new SendStockCountDiscrepancyDigest($this->count->id);

        $this->assertSame((string) $this->count->id, $job->uniqueId());
        // Delay window plus margin, so a lost worker can't wedge the lock.
        $this->assertSame(5 * 60 + 300, $job->uniqueFor());
    }

    /** The digest mails one email covering every un-mailed line, then claims them. */
    public function test_digest_mails_pending_lines_once(): void
    {
        config(['queue.default' => 'database']);
        Queue::fake(); // hold the dispatched digest so we can run it deliberately
        Notification::fake();
        $this->scan('ROGUE-A'); // mails immediately
        $this->scan('ROGUE-B');
        $this->scan('ROGUE-C');

        Mail::fake();
        app(SendStockCountDiscrepancyDigest::class, ['stockCountId' => $this->count->id])
            ->handle(app(\App\Services\NotificationService::class));

        Mail::assertQueued(StockCountDiscrepancyDigestMail::class, 0);
        Mail::assertSent(
            StockCountDiscrepancyDigestMail::class,
            fn (StockCountDiscrepancyDigestMail $m) => $m->lines->count() === 2,
        );

        // Every adjustment is now claimed, so a re-run sends nothing.
        $this->assertSame(0, $this->count->items()->adjustments()->whereNull('digest_notified_at')->count());

        Mail::fake();
        app(SendStockCountDiscrepancyDigest::class, ['stockCountId' => $this->count->id])
            ->handle(app(\App\Services\NotificationService::class));
        Mail::assertNothingSent();
    }

    /** Setting the window to 0 restores the literal spec behaviour. */
    public function test_zero_digest_window_mails_every_line(): void
    {
        config(['surgical.stock_count.discrepancy_digest_minutes' => 0]);
        Notification::fake();
        Queue::fake();

        $this->scan('ROGUE-A');
        $this->scan('ROGUE-B');

        $channels = [];
        Notification::assertSentTo($this->admin, StockCountDiscrepancyNotification::class, function ($n) use (&$channels) {
            $channels[] = $n->via($this->admin);

            return true;
        });

        $this->assertCount(2, $channels);
        $this->assertSame([['database', 'mail'], ['database', 'mail']], $channels);
        Queue::assertNotPushed(SendStockCountDiscrepancyDigest::class);
    }

    /**
     * On a sync queue a delayed dispatch runs immediately, so batching would
     * fire one digest per scan — one email per line, the thing the digest
     * exists to prevent. Batching is therefore off without a queue worker, and
     * every line mails directly instead.
     */
    public function test_sync_queue_mails_every_line_instead_of_digesting(): void
    {
        config(['queue.default' => 'sync']);
        Notification::fake();
        Queue::fake();

        $this->scan('ROGUE-A');
        $this->scan('ROGUE-B');

        $channels = [];
        Notification::assertSentTo($this->admin, StockCountDiscrepancyNotification::class, function ($n) use (&$channels) {
            $channels[] = $n->via($this->admin);

            return true;
        });

        $this->assertSame([['database', 'mail'], ['database', 'mail']], $channels);
        Queue::assertNotPushed(SendStockCountDiscrepancyDigest::class);

        // Both mailed directly, so nothing is left for a digest to pick up.
        $this->assertSame(0, $this->count->items()->adjustments()->whereNull('digest_notified_at')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  Scanned quantities folding into the submit                         */
    /* ------------------------------------------------------------------ */

    /** A count completed purely by scanning submits with no keyed lines. */
    public function test_scanned_quantities_fold_in_on_submit(): void
    {
        Notification::fake();
        Mail::fake();

        $this->scan('11129D250603', expiry: '2027-06-03');
        $this->scan('11129D250603', expiry: '2027-06-03');

        $count = app(StockCountService::class)->submit($this->count);

        $line = $count->items()->expected()->firstOrFail();
        $this->assertSame(2, $line->scanned_quantity);
        $this->assertSame(2, $line->counted_quantity);
        $this->assertSame(-1, $line->variance); // 2 counted of 3 expected
    }

    /** A keyed quantity beats the scan tally — it is a deliberate correction. */
    public function test_keyed_quantity_overrides_the_scan_tally(): void
    {
        Notification::fake();
        Mail::fake();

        $this->scan('11129D250603', expiry: '2027-06-03');
        $line = $this->count->items()->expected()->firstOrFail();

        $count = app(StockCountService::class)->submit($this->count, [
            ['id' => $line->id, 'counted_quantity' => 3],
        ]);

        $fresh = $count->items()->expected()->firstOrFail();
        $this->assertSame(1, $fresh->scanned_quantity);
        $this->assertSame(3, $fresh->counted_quantity);
        $this->assertSame(0, $fresh->variance);
    }

    /** Lines neither scanned nor keyed stay uncounted, with no variance. */
    public function test_untouched_lines_stay_uncounted(): void
    {
        Notification::fake();
        Mail::fake();

        $count = app(StockCountService::class)->submit($this->count);

        $line = $count->items()->expected()->firstOrFail();
        $this->assertNull($line->counted_quantity);
        $this->assertNull($line->variance);
    }

    /** A fully-scanned count submits over HTTP without a lines payload. */
    public function test_submit_over_http_without_lines(): void
    {
        Notification::fake();
        Mail::fake();

        $this->scan('11129D250603', expiry: '2027-06-03');

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/submit", [])
            ->assertOk();

        $this->assertSame('submitted', $this->count->fresh()->status->value);
    }

    /* ------------------------------------------------------------------ */
    /*  Final Summary Report                                               */
    /* ------------------------------------------------------------------ */

    /** Submitting generates the summary PDF and emails it. */
    public function test_submit_generates_and_emails_the_summary_report(): void
    {
        Notification::fake();
        Mail::fake();

        $this->scan('11129D250603', expiry: '2027-06-03');
        $this->scan('ROGUE-LOT'); // one orange line for the report to surface

        app(StockCountService::class)->submit($this->count);

        $doc = $this->count->document('stock_count_summary');
        $this->assertNotNull($doc);
        $this->assertSame('application/pdf', $doc->mime_type);
        $this->assertStringContainsString("documents/stock_counts/{$this->count->id}/", $doc->path);
        Storage::disk(config('filesystems.default'))->assertExists($doc->path);
        $this->assertGreaterThan(0, $doc->size);

        Mail::assertQueued(
            StockCountSummaryMail::class,
            fn (StockCountSummaryMail $m) => $m->count->id === $this->count->id
                && $m->document->id === $doc->id,
        );
    }

    /** The PDF is a real document that renders the exception block. */
    public function test_summary_pdf_renders_with_adjustments(): void
    {
        Notification::fake();

        $this->scan('ROGUE-LOT');
        $doc = app(PdfService::class)->generateStockCountSummary($this->count->fresh());

        $bytes = Storage::disk($doc->disk)->get($doc->path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1000, strlen($bytes));
    }

    /** A report failure must not undo a submission made in the field. */
    public function test_report_failure_does_not_roll_back_the_submit(): void
    {
        Notification::fake();
        Mail::fake();

        // A missing view is the cheapest way to make render() throw.
        config(['surgical.stock_count.discrepancy_digest_minutes' => 5]);
        $this->mock(PdfService::class, function ($mock) {
            $mock->shouldReceive('generateStockCountSummary')
                ->andThrow(new \RuntimeException('dompdf exploded'));
        });

        app(StockCountService::class)->submit($this->count);

        $this->assertSame('submitted', $this->count->fresh()->status->value);
        $this->assertNull($this->count->document('stock_count_summary'));
        Mail::assertNotQueued(StockCountSummaryMail::class);
    }

    /* ------------------------------------------------------------------ */
    /*  Generalised PdfService                                             */
    /* ------------------------------------------------------------------ */

    /** Transfer documents keep their existing path and type after the refactor. */
    public function test_transfer_pdf_paths_are_unchanged(): void
    {
        Notification::fake();
        Mail::fake();

        $office = Location::create(['name' => 'JHB Office', 'type' => 'office']);
        $unit = $this->circular->units()->create([
            'serial_number' => 'Z1', 'lot_number' => 'L9',
            'location_id' => $this->boot->id, 'status' => 'available',
        ]);

        $svc = app(\App\Services\TransferService::class);
        $transfer = $svc->request([
            'from_location_id' => $this->boot->id,
            'to_location_id'   => $office->id,
            'unit_ids'         => [$unit->id],
            'signature_path'   => 'sig.png',
            'signer_name'      => 'Mike',
        ], $this->rep);

        $svc->approve($transfer->fresh(), $this->admin);

        $doc = Document::where('documentable_type', Transfer::class)
            ->where('documentable_id', $transfer->id)
            ->firstOrFail();

        $this->assertSame('transfer_pdf', $doc->type);
        $this->assertStringContainsString("documents/transfers/{$transfer->id}/", $doc->path);
        Storage::disk($doc->disk)->assertExists($doc->path);
    }
}
