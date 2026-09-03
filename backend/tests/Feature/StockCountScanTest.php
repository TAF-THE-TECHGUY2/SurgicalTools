<?php

namespace Tests\Feature;

use App\Enums\StockCountAdjustmentType;
use App\Enums\UserRole;
use App\Models\Location;
use App\Models\StockCount;
use App\Models\StockCountScan;
use App\Models\StockItem;
use App\Models\User;
use App\Notifications\StockCountDiscrepancyNotification;
use App\Services\ScanExtractionService;
use App\Services\StockCountScanService;
use App\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Phase 2: GS1 parsing and the four match outcomes from the spec's exception
 * rule. A discrepancy must INSERT a secondary line pointing at the primary
 * product, never overwrite the expected lot row.
 */
class StockCountScanTest extends TestCase
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
        Notification::fake();

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@scan.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@scan.test');

        $this->boot = Location::create([
            'name' => 'Mike Boot', 'type' => 'boot', 'owner_user_id' => $this->rep->id,
        ]);
        $this->rep->update(['location_id' => $this->boot->id]);

        // Expected: three of 12012029 on lot 11129D250603, expiring 2027-06-03.
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

    protected function scanService(): StockCountScanService
    {
        return app(StockCountScanService::class);
    }

    /** @return array<string, mixed> */
    protected function triple(?string $ref, ?string $lot, ?string $expiry = null, ?string $gtin = null): array
    {
        return [
            'ref' => $ref, 'gtin' => $gtin, 'lot_number' => $lot,
            'expiry_date' => $expiry, 'serial_number' => null,
            'confidence' => 1.0, 'raw_text' => '',
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  GS1 parsing                                                        */
    /* ------------------------------------------------------------------ */

    /** The raw scanner form: fixed-length AIs run on, variable ones need FNC1. */
    public function test_parses_raw_gs1_element_string(): void
    {
        $fnc1 = "\x1D";
        $raw = '010345678901234510'.'11129D250603'.$fnc1.'17'.'250603'.'21'.'ABC123';

        $out = app(ScanExtractionService::class)->parseGs1($raw);

        $this->assertSame('03456789012345', $out['gtin']);
        $this->assertSame('11129D250603', $out['lot_number']);
        $this->assertSame('2025-06-03', $out['expiry_date']);
        $this->assertSame('ABC123', $out['serial_number']);
        $this->assertSame(1.0, $out['confidence']);
    }

    /** The human-readable bracketed form needs no length table. */
    public function test_parses_bracketed_gs1_element_string(): void
    {
        $out = app(ScanExtractionService::class)
            ->parseGs1('(01)03456789012345(10)HQ45D250902(17)270902(21)SN7');

        $this->assertSame('03456789012345', $out['gtin']);
        $this->assertSame('HQ45D250902', $out['lot_number']);
        $this->assertSame('2027-09-02', $out['expiry_date']);
        $this->assertSame('SN7', $out['serial_number']);
    }

    /** A leading symbology identifier is not part of the data. */
    public function test_strips_symbology_identifier(): void
    {
        $out = app(ScanExtractionService::class)->parseGs1(']d201034567890123451017AB');

        $this->assertSame('03456789012345', $out['gtin']);
        $this->assertSame('17AB', $out['lot_number']);
    }

    /** A GS1 day of 00 means end of month. */
    public function test_gs1_day_zero_is_end_of_month(): void
    {
        $out = app(ScanExtractionService::class)->parseGs1('(17)270200');

        $this->assertSame('2027-02-28', $out['expiry_date']);
    }

    /** AI 240 carries the maker's own reference on some labels. */
    public function test_parses_additional_product_id_as_ref(): void
    {
        $out = app(ScanExtractionService::class)->parseGs1('(240)12012029(10)L1');

        $this->assertSame('12012029', $out['ref']);
        $this->assertSame('L1', $out['lot_number']);
    }

    public function test_rejects_unparseable_barcode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ScanExtractionService::class)->parseGs1('not-a-barcode');
    }

    public function test_rejects_empty_barcode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(ScanExtractionService::class)->parseGs1('   ');
    }

    /* ------------------------------------------------------------------ */
    /*  The four match outcomes                                            */
    /* ------------------------------------------------------------------ */

    /** Item and lot both expected → tally the existing line, insert nothing. */
    public function test_matching_scan_increments_the_expected_line(): void
    {
        $before = $this->count->items()->count();

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12012029', '11129D250603', '2027-06-03'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountScan::MATCH, $scan->match_result);
        $this->assertSame($before, $this->count->items()->count());

        $line = $this->count->items()->firstOrFail();
        $this->assertSame(1, $line->scanned_quantity);
        $this->assertSame(3, $line->expected_quantity); // snapshot untouched
        $this->assertFalse($line->is_adjustment);
        $this->assertNotNull($line->first_scanned_at);

        // A clean match is not a discrepancy, so nobody is alerted.
        Notification::assertNotSentTo($this->admin, StockCountDiscrepancyNotification::class);
    }

    /** Repeat scans of the same lot increment rather than inserting. */
    public function test_repeat_matching_scans_increment(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->scanService()->record(
                $this->count,
                $this->triple('12012029', '11129D250603', '2027-06-03'),
                ['source' => StockCountScan::SOURCE_BARCODE],
                $this->rep,
            );
        }

        $this->assertSame(1, $this->count->items()->count());
        $this->assertSame(3, $this->count->items()->firstOrFail()->scanned_quantity);
    }

    /**
     * The spec's headline exception: known product, different lot. One orange
     * line inserted, pointing at the expected line, whose snapshot is intact.
     */
    public function test_lot_mismatch_inserts_an_orange_line(): void
    {
        $expected = $this->count->items()->firstOrFail();

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12012029', 'HQ45D250902', '2027-09-02'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountAdjustmentType::LotMismatch->value, $scan->match_result);

        $adjustments = $this->count->items()->adjustments()->get();
        $this->assertCount(1, $adjustments);

        $line = $adjustments->first();
        $this->assertTrue($line->is_adjustment);
        $this->assertSame(StockCountAdjustmentType::LotMismatch, $line->adjustment_type);
        $this->assertSame('HQ45D250902', $line->lot_number);
        $this->assertSame('11129D250603', $line->expected_lot_number);
        $this->assertSame($expected->id, $line->parent_item_id);
        $this->assertSame(0, $line->expected_quantity);
        $this->assertSame(1, $line->scanned_quantity);

        // The expected row was not overwritten.
        $expected->refresh();
        $this->assertSame(3, $expected->expected_quantity);
        $this->assertSame('11129D250603', $expected->lot_number);
        $this->assertSame(0, $expected->scanned_quantity);
    }

    /** A product not on the location's list at all. */
    public function test_unlisted_item_inserts_an_orange_line(): void
    {
        $stapler = StockItem::create(['name' => 'Contour Stapler', 'catalogue_number' => '12009045']);

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12009045', 'HQ45D250902'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountAdjustmentType::UnlistedItem->value, $scan->match_result);

        $line = $this->count->items()->adjustments()->firstOrFail();
        $this->assertSame(StockCountAdjustmentType::UnlistedItem, $line->adjustment_type);
        $this->assertSame($stapler->id, $line->stock_item_id);
        $this->assertNull($line->parent_item_id); // nothing expected to point at
        $this->assertNull($line->expected_lot_number);
    }

    /** Item and lot agree but the physical expiry doesn't. */
    public function test_expiry_mismatch_inserts_an_orange_line(): void
    {
        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12012029', '11129D250603', '2028-01-01'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountAdjustmentType::ExpiryMismatch->value, $scan->match_result);

        $line = $this->count->items()->adjustments()->firstOrFail();
        $this->assertSame(StockCountAdjustmentType::ExpiryMismatch, $line->adjustment_type);
        $this->assertSame($this->count->items()->expected()->first()->id, $line->parent_item_id);
    }

    /** An unreadable expiry is not evidence of a mismatch. */
    public function test_missing_expiry_does_not_raise_a_mismatch(): void
    {
        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12012029', '11129D250603', null),
            ['source' => StockCountScan::SOURCE_VISION],
            $this->rep,
        );

        $this->assertSame(StockCountScan::MATCH, $scan->match_result);
        $this->assertCount(0, $this->count->items()->adjustments()->get());
    }

    /** An unknown code creates no line — it is held for manual entry. */
    public function test_unresolved_item_creates_no_line(): void
    {
        $before = $this->count->items()->count();

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('NOT-IN-CATALOGUE', 'L1'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountScan::UNRESOLVED, $scan->match_result);
        $this->assertNull($scan->stock_count_item_id);
        $this->assertNull($scan->stock_item_id);
        $this->assertSame($before, $this->count->items()->count());
        $this->assertTrue($scan->needsReview());
    }

    /** Repeat scans of the same discrepancy tally onto one orange line. */
    public function test_repeat_discrepancy_scans_tally_onto_one_line(): void
    {
        foreach (range(1, 3) as $ignored) {
            $this->scanService()->record(
                $this->count,
                $this->triple('12012029', 'HQ45D250902'),
                ['source' => StockCountScan::SOURCE_BARCODE],
                $this->rep,
            );
        }

        $adjustments = $this->count->items()->adjustments()->get();
        $this->assertCount(1, $adjustments);
        $this->assertSame(3, $adjustments->first()->scanned_quantity);
    }

    /** Lot comparison survives case, spacing and hyphen noise from OCR. */
    public function test_lot_matching_tolerates_formatting_noise(): void
    {
        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('12012029', ' 11129-d250603 ', '2027-06-03'),
            ['source' => StockCountScan::SOURCE_VISION],
            $this->rep,
        );

        $this->assertSame(StockCountScan::MATCH, $scan->match_result);
        $this->assertCount(0, $this->count->items()->adjustments()->get());
    }

    /* ------------------------------------------------------------------ */
    /*  Resolution, alerting, idempotency                                  */
    /* ------------------------------------------------------------------ */

    /** GTIN wins over the printed reference. */
    public function test_gtin_resolves_ahead_of_ref(): void
    {
        $this->circular->update(['gtin' => '03456789012345']);

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple(null, '11129D250603', '2027-06-03', '03456789012345'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountScan::MATCH, $scan->match_result);
        $this->assertSame($this->circular->id, $scan->stock_item_id);
    }

    /** Falls back to item_code when the catalogue number doesn't match. */
    public function test_item_code_resolves_when_catalogue_number_does_not(): void
    {
        $item = StockItem::create(['name' => 'Guide Wire', 'item_code' => 'GW-REF-9']);

        $scan = $this->scanService()->record(
            $this->count,
            $this->triple('GW-REF-9', 'L1'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame($item->id, $scan->stock_item_id);
        $this->assertSame(StockCountAdjustmentType::UnlistedItem->value, $scan->match_result);
    }

    /** Spec §4: a flagged line alerts admin staff immediately. */
    public function test_discrepancy_alerts_admins(): void
    {
        $this->scanService()->record(
            $this->count,
            $this->triple('12012029', 'ROGUE'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        Notification::assertSentTo($this->admin, StockCountDiscrepancyNotification::class);
        // The rep is looking at the orange row already.
        Notification::assertNotSentTo($this->rep, StockCountDiscrepancyNotification::class);
    }

    /**
     * Only the first discrepancy on a count mails; the rest are in-app and
     * batched. Batching needs a real queue — on the sync driver every line
     * mails directly instead (see StockCountReportingTest).
     */
    public function test_only_the_first_discrepancy_mails(): void
    {
        config(['queue.default' => 'database']);
        \Illuminate\Support\Facades\Queue::fake();

        foreach (['ROGUE-A', 'ROGUE-B'] as $lot) {
            $this->scanService()->record(
                $this->count,
                $this->triple('12012029', $lot),
                ['source' => StockCountScan::SOURCE_BARCODE],
                $this->rep,
            );
        }

        $channels = [];
        Notification::assertSentTo(
            $this->admin,
            StockCountDiscrepancyNotification::class,
            function ($notification) use (&$channels) {
                $channels[] = $notification->via($this->admin);

                return true;
            },
        );

        $this->assertContains(['database', 'mail'], $channels);
        $this->assertContains(['database'], $channels);
    }

    /** Offline replay of the same capture must not double-count. */
    public function test_replayed_scan_is_idempotent(): void
    {
        $payload = $this->triple('12012029', '11129D250603', '2027-06-03');
        $context = ['source' => StockCountScan::SOURCE_BARCODE, 'client_id' => 'client-uuid-1'];

        $first = $this->scanService()->record($this->count, $payload, $context, $this->rep);
        $second = $this->scanService()->record($this->count, $payload, $context, $this->rep);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, StockCountScan::count());
        $this->assertSame(1, $this->count->items()->firstOrFail()->scanned_quantity);
    }

    /** Confirming an unresolved scan teaches the catalogue the GTIN. */
    public function test_confirming_a_scan_learns_the_gtin(): void
    {
        $scan = $this->scanService()->record(
            $this->count,
            $this->triple(null, '11129D250603', '2027-06-03', '09999888877776'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $this->assertSame(StockCountScan::UNRESOLVED, $scan->match_result);

        // The runner supplies the reference the barcode didn't resolve.
        $confirmed = $this->scanService()->confirm($scan, ['ref' => '12012029'], $this->rep);

        $this->assertSame(StockCountScan::MATCH, $confirmed->match_result);
        $this->assertTrue($confirmed->confirmed);
        $this->assertSame('09999888877776', $this->circular->fresh()->gtin);
        $this->assertSame(1, $this->count->items()->firstOrFail()->scanned_quantity);
    }

    /* ------------------------------------------------------------------ */
    /*  HTTP surface                                                       */
    /* ------------------------------------------------------------------ */

    public function test_rep_can_scan_a_barcode_over_http(): void
    {
        $response = $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", [
                'barcode' => '(240)12012029(10)11129D250603(17)270603',
            ])
            ->assertCreated();

        $this->assertSame(StockCountScan::MATCH, $response->json('scan.match_result'));
        $this->assertFalse($response->json('needs_review'));
    }

    public function test_scan_requires_some_input(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_unparseable_barcode_is_a_validation_error(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", ['barcode' => 'rubbish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    /** A rep the count isn't assigned to cannot scan into it. */
    public function test_unassigned_rep_cannot_scan(): void
    {
        $other = $this->makeUser(UserRole::GeneralUser, 'other@scan.test');

        $this->actingAs($other, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", [
                'barcode' => '(240)12012029(10)11129D250603',
            ])
            ->assertForbidden();
    }

    /** Withholding the permission per user blocks scanning. */
    public function test_permission_override_blocks_scanning(): void
    {
        $this->rep->update(['permission_overrides' => ['stock_count.capture']]);

        $this->actingAs($this->rep->fresh(), 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", [
                'barcode' => '(240)12012029(10)11129D250603',
            ])
            ->assertForbidden();
    }

    /** Mis-scans can be removed; snapshot lines cannot. */
    public function test_only_adjustment_lines_can_be_deleted(): void
    {
        $this->scanService()->record(
            $this->count,
            $this->triple('12012029', 'ROGUE'),
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );

        $adjustment = $this->count->items()->adjustments()->firstOrFail();
        $expected = $this->count->items()->expected()->firstOrFail();

        $this->actingAs($this->rep, 'sanctum')
            ->deleteJson("/api/stock-counts/{$this->count->id}/lines/{$adjustment->id}")
            ->assertOk();

        $this->assertCount(0, $this->count->items()->adjustments()->get());

        $this->actingAs($this->rep, 'sanctum')
            ->deleteJson("/api/stock-counts/{$this->count->id}/lines/{$expected->id}")
            ->assertStatus(422);

        $this->assertNotNull($expected->fresh());
    }

    /** Vision extraction is refused, clearly, when no API key is configured. */
    public function test_vision_path_reports_when_unconfigured(): void
    {
        config(['surgical.ocr.api_key' => null]);

        $this->assertFalse(app(ScanExtractionService::class)->visionAvailable());

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", [
                'photo' => \Illuminate\Http\Testing\File::image('label.jpg'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }
}
