<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\StockCountDocumentsMail;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\Setting;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockCountScan;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\StockCountScanService;
use App\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\SignsStockCounts;
use Tests\TestCase;

/**
 * Stock Count Mobile Scanning spec §3.3 rule 6, §3.4 and §3.5: minus-confirm,
 * the finish gate, the stock controller's sign-off and lock, the three
 * outputs and where they go, and the reviewer's "Adjust lot".
 *
 * The fixture is the spec's own paper example: F5LT (5mm × 100mm Sleeve with
 * Stopcock) held under lots MG03J2 (2) and PC13Y1 (1).
 */
class StockCountSignOffTest extends TestCase
{
    use RefreshDatabase, SignsStockCounts;

    protected User $admin;

    protected User $rep;

    protected Location $site;

    protected StockItem $sleeve;

    protected StockCount $count;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));
        Notification::fake();
        Mail::fake();

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@signoff.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@signoff.test');

        $this->site = Location::create([
            'name' => 'Zamokuhle', 'code' => 'CDMONT', 'type' => 'boot', 'owner_user_id' => $this->rep->id,
        ]);

        $this->sleeve = StockItem::create([
            'name' => '5mm x 100mm Sleeve with Stopcock', 'catalogue_number' => 'LM715',
            'item_code' => 'F5LT', 'supplier' => 'DANNIK', 'product_group' => 'LAP',
            'unit_price' => 450.00,
        ]);

        $this->units('MG03J2', 2, '2028-01-08');
        $this->units('PC13Y1', 1, '2028-03-01');

        $this->count = app(StockCountService::class)->create(
            ['location_id' => $this->site->id, 'assigned_to' => $this->rep->id],
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

    protected function units(string $lot, int $n, string $expiry): void
    {
        foreach (range(1, $n) as $i) {
            $this->sleeve->units()->create([
                'serial_number' => "{$lot}-{$i}", 'lot_number' => $lot, 'expiry_date' => $expiry,
                'location_id' => $this->site->id, 'status' => 'available',
            ]);
        }
    }

    protected function scan(?string $lot, ?string $expiry = null, string $ref = 'LM715'): StockCountScan
    {
        return app(StockCountScanService::class)->record(
            $this->count->fresh(),
            [
                'ref' => $ref, 'gtin' => null, 'lot_number' => $lot, 'expiry_date' => $expiry,
                'serial_number' => null, 'confidence' => 1.0, 'raw_text' => '',
            ],
            ['source' => StockCountScan::SOURCE_BARCODE],
            $this->rep,
        );
    }

    protected function line(string $lot, bool $adjustment = false): StockCountItem
    {
        return $this->count->items()->where('lot_number', $lot)
            ->where('is_adjustment', $adjustment)->firstOrFail();
    }

    protected function finish(): StockCount
    {
        return app(StockCountService::class)->submit($this->count->fresh(), [], $this->signOff(), $this->rep);
    }

    /** The paper example, scanned: MG03J2 none found, PC13Y1 ×2, NE17F1 ×1. */
    protected function countThePaperExample(): void
    {
        $this->scan('PC13Y1', '2028-03-01');
        $this->scan('PC13Y1', '2028-03-01');
        $this->scan('NE17F1', '2029-05-05');
        app(StockCountService::class)->markNotFound($this->count, $this->line('MG03J2'), $this->rep);
    }

    /* ------------------------------------------------------------------ */
    /*  Snapshot columns                                                   */
    /* ------------------------------------------------------------------ */

    public function test_lines_snapshot_the_paper_sheet_columns(): void
    {
        $line = $this->line('MG03J2');

        $this->assertSame('F5LT', $line->item_code);
        $this->assertSame('LM715', $line->ref_code);
        $this->assertSame('DANNIK', $line->supplier);
        $this->assertSame('LAP', $line->product_group);
        $this->assertSame('450.00', $line->unit_price);

        // A later catalogue edit does not rewrite the count.
        $this->sleeve->update(['unit_price' => 999, 'supplier' => 'OTHER']);
        $this->assertSame('DANNIK', $line->fresh()->supplier);
    }

    /* ------------------------------------------------------------------ */
    /*  Rule 6: minus-confirm                                              */
    /* ------------------------------------------------------------------ */

    public function test_minus_confirm_sets_variance_to_minus_system_quantity(): void
    {
        $line = app(StockCountService::class)->markNotFound($this->count, $this->line('MG03J2'), $this->rep);

        $this->assertSame(0, $line->counted_quantity);
        $this->assertSame(-2, $line->variance);
        $this->assertNotNull($line->not_found_at);
        $this->assertTrue($line->isResolved());
        $this->assertFalse($line->isTicked());
    }

    public function test_minus_confirm_can_be_undone(): void
    {
        $svc = app(StockCountService::class);
        $svc->markNotFound($this->count, $this->line('MG03J2'), $this->rep);
        $line = $svc->clearNotFound($this->count, $this->line('MG03J2'));

        $this->assertNull($line->counted_quantity);
        $this->assertNull($line->variance);
        $this->assertNull($line->not_found_at);
        $this->assertFalse($line->isResolved());
    }

    public function test_a_scanned_line_cannot_be_minus_confirmed(): void
    {
        $this->scan('PC13Y1', '2028-03-01');

        $this->expectException(ValidationException::class);
        app(StockCountService::class)->markNotFound($this->count, $this->line('PC13Y1'), $this->rep);
    }

    /** "None found" was wrong the moment a unit of that lot is scanned. */
    public function test_scanning_withdraws_a_minus_confirmation(): void
    {
        app(StockCountService::class)->markNotFound($this->count, $this->line('MG03J2'), $this->rep);
        $this->scan('MG03J2', '2028-01-08');

        $line = $this->line('MG03J2');
        $this->assertNull($line->not_found_at);
        $this->assertNull($line->counted_quantity);
        $this->assertSame(1, $line->scanned_quantity);

        $this->scan('PC13Y1', '2028-03-01');
        $this->finish();

        $this->assertSame(-1, $this->line('MG03J2')->variance);
    }

    public function test_minus_confirm_over_http(): void
    {
        $line = $this->line('MG03J2');

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$line->id}/not-found")
            ->assertOk()
            ->assertJsonPath('data.unresolved_count', 1);

        $this->actingAs($this->rep, 'sanctum')
            ->deleteJson("/api/stock-counts/{$this->count->id}/lines/{$line->id}/not-found")
            ->assertOk()
            ->assertJsonPath('data.unresolved_count', 2);
    }

    /* ------------------------------------------------------------------ */
    /*  The paper example end to end                                       */
    /* ------------------------------------------------------------------ */

    /** Spec §2: MG03J2 -2, PC13Y1 +1, NE17F1 a new "adjust lot" line at +1. */
    public function test_paper_example_variances(): void
    {
        $this->countThePaperExample();
        $this->finish();

        $this->assertSame(-2, $this->line('MG03J2')->variance);
        $this->assertSame(1, $this->line('PC13Y1')->variance);

        $new = $this->line('NE17F1', adjustment: true);
        $this->assertSame(0, $new->expected_quantity);
        $this->assertSame(1, $new->counted_quantity);
        $this->assertSame(1, $new->variance);
        $this->assertSame('lot_mismatch', $new->adjustment_type->value);

        // Valued at list/unit price for the accounts department.
        $this->assertSame(-900.0, $this->line('MG03J2')->varianceValue());
        $this->assertSame(450.0, $new->varianceValue());
    }

    /* ------------------------------------------------------------------ */
    /*  §3.4 finish gate + §3.5 sign-off and lock                          */
    /* ------------------------------------------------------------------ */

    public function test_finish_is_refused_until_every_line_is_resolved(): void
    {
        $this->scan('PC13Y1', '2028-03-01');

        try {
            $this->finish();
            $this->fail('MG03J2 is unresolved.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('MG03J2', $e->errors()['lines'][0]);
        }

        $this->assertFalse($this->count->fresh()->isLocked());
        Storage::disk(config('filesystems.default'))->assertDirectoryEmpty('documents/signatures');

        app(StockCountService::class)->markNotFound($this->count, $this->line('MG03J2'), $this->rep);
        $this->assertTrue($this->finish()->isLocked());
    }

    public function test_sign_off_records_the_signature_block(): void
    {
        $this->countThePaperExample();
        $count = $this->finish();

        $this->assertSame('submitted', $count->status->value);
        $this->assertSame('Mike Controller', $count->signed_by_name);
        $this->assertSame($this->rep->id, $count->signed_by);
        $this->assertSame('PHPUnit', $count->signed_device);
        $this->assertNotNull($count->signed_at);
        Storage::disk(config('filesystems.default'))->assertExists($count->signature_path);
    }

    public function test_signed_count_is_locked(): void
    {
        $this->countThePaperExample();
        $this->finish();
        $line = $this->line('PC13Y1');

        try {
            $this->scan('PC13Y1', '2028-03-01');
            $this->fail('Scanning a signed count must be refused.');
        } catch (ValidationException) {
            // expected
        }
        $this->assertSame(2, $line->fresh()->scanned_quantity);

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$line->id}/not-found")
            ->assertForbidden();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/submit", [
                'signature' => self::SIGNATURE_PNG, 'signed_by_name' => 'Someone Else',
            ])
            ->assertForbidden();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/scan", ['ref' => 'LM715', 'lot_number' => 'PC13Y1'])
            ->assertForbidden();

        $this->assertSame('Mike Controller', $this->count->fresh()->signed_by_name);
    }

    public function test_signature_must_be_a_png_data_uri(): void
    {
        $this->countThePaperExample();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/submit", [
                'signature' => 'not-an-image', 'signed_by_name' => 'Mike',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature');
    }

    /* ------------------------------------------------------------------ */
    /*  §3.5 outputs and where they go                                     */
    /* ------------------------------------------------------------------ */

    public function test_sign_off_produces_the_three_outputs(): void
    {
        $this->countThePaperExample();
        $this->finish();

        foreach (['stock_count_sheet', 'stock_count_variance', 'stock_count_lot_adjustments'] as $type) {
            $doc = $this->count->document($type);
            $this->assertNotNull($doc, "{$type} was not generated");
            $bytes = Storage::disk($doc->disk)->get($doc->path);
            $this->assertStringStartsWith('%PDF-', $bytes);
        }
    }

    public function test_no_lot_adjustment_report_when_nothing_was_flagged(): void
    {
        $this->scan('PC13Y1', '2028-03-01');
        $this->scan('MG03J2', '2028-01-08');
        $this->finish();

        $this->assertNotNull($this->count->document('stock_count_sheet'));
        $this->assertNull($this->count->document('stock_count_lot_adjustments'));
    }

    public function test_signed_sheet_goes_to_the_stock_controller(): void
    {
        $this->countThePaperExample();
        $this->finish();

        Mail::assertQueued(StockCountDocumentsMail::class, fn (StockCountDocumentsMail $m) => $m->audience === 'controller'
            && $m->hasTo($this->rep->email)
            && count($m->documents) === 1
            && $m->documents[0]->type === 'stock_count_sheet');
    }

    public function test_variance_report_goes_to_the_configured_accounts_address(): void
    {
        Setting::put(Setting::ACCOUNTS_EMAIL, 'accounts@surgicaldevices.test');

        $this->countThePaperExample();
        $this->finish();

        Mail::assertQueued(StockCountDocumentsMail::class, function (StockCountDocumentsMail $m) {
            $types = collect($m->documents)->pluck('type')->all();

            return $m->audience === 'accounts'
                && $m->hasTo('accounts@surgicaldevices.test')
                && $types === ['stock_count_variance', 'stock_count_sheet', 'stock_count_lot_adjustments'];
        });
    }

    public function test_accounts_falls_back_to_the_office_when_unconfigured(): void
    {
        config(['surgical.notifications.accounts' => null, 'surgical.notifications.office' => 'office@sd.test']);

        $this->countThePaperExample();
        $this->finish();

        Mail::assertQueued(StockCountDocumentsMail::class, fn ($m) => $m->audience === 'accounts' && $m->hasTo('office@sd.test'));
    }

    public function test_documents_render_as_draft_then_serve_the_signed_copy(): void
    {
        $this->countThePaperExample();

        foreach (['sheet', 'variance', 'lot-adjustments'] as $kind) {
            $this->actingAs($this->rep, 'sanctum')
                ->get("/api/stock-counts/{$this->count->id}/documents/{$kind}")
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
        $this->assertNull($this->count->document('stock_count_sheet'), 'A draft print must not be stored.');

        $this->finish();

        $stored = Storage::disk(config('filesystems.default'))->get($this->count->document('stock_count_sheet')->path);
        $response = $this->actingAs($this->rep, 'sanctum')
            ->get("/api/stock-counts/{$this->count->id}/documents/sheet?download=1")
            ->assertOk();

        $this->assertSame($stored, $response->getContent());
        $this->assertStringStartsWith('attachment;', $response->headers->get('Content-Disposition'));
    }

    public function test_sheet_can_be_emailed_once_signed(): void
    {
        $this->countThePaperExample();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/email-sheet", ['emails' => ['theatre@hospital.test']])
            ->assertStatus(422);

        $this->finish();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/email-sheet", ['emails' => ['theatre@hospital.test']])
            ->assertOk();

        Mail::assertQueued(StockCountDocumentsMail::class, fn ($m) => $m->audience === 'copy' && $m->hasTo('theatre@hospital.test'));
    }

    /* ------------------------------------------------------------------ */
    /*  Lot adjustment report: Adjust lot                                  */
    /* ------------------------------------------------------------------ */

    /**
     * The reviewer accepts NE17F1: one unit moves off MG03J2 onto it. On
     * approval MG03J2 writes off only the one unit still unaccounted for, and
     * NE17F1 is not reported as surplus.
     */
    public function test_adjust_lot_moves_stock_and_approval_accounts_for_it(): void
    {
        $this->countThePaperExample();
        $this->finish();
        $new = $this->line('NE17F1', adjustment: true);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$new->id}/adjust-lot")
            ->assertOk()
            ->assertJsonPath('line.lot_adjusted_quantity', 1);

        $relabelled = DeviceUnit::where('lot_number', 'NE17F1')->get();
        $this->assertCount(1, $relabelled);
        $this->assertSame('2029-05-05', $relabelled->first()->expiry_date->toDateString());
        $this->assertSame(1, StockMovement::where('movement_type', 'lot_adjustment')->count());
        $this->assertSame(1, $this->line('MG03J2')->lot_adjusted_quantity);

        app(StockCountService::class)->review($this->count->fresh(), $this->admin, 'approve');

        $this->assertSame(1, DeviceUnit::where('lot_number', 'MG03J2')->where('status', 'missing')->count());
        $this->assertSame(0, DeviceUnit::where('lot_number', 'MG03J2')->where('status', 'available')->count());
        $this->assertSame(1, DeviceUnit::where('lot_number', 'NE17F1')->where('status', 'available')->count());
        $this->assertSame(1, DeviceUnit::where('lot_number', 'PC13Y1')->where('status', 'available')->count());

        $this->assertStringNotContainsString('Surplus', (string) $this->line('NE17F1', adjustment: true)->notes);
        $this->assertStringContainsString('Surplus of 1', (string) $this->line('PC13Y1')->notes);
    }

    public function test_adjust_lot_cannot_run_twice(): void
    {
        $this->countThePaperExample();
        $this->finish();
        $new = $this->line('NE17F1', adjustment: true);

        $svc = app(StockCountService::class);
        $svc->adjustLot($this->count->fresh(), $new, $this->admin);

        $this->expectException(ValidationException::class);
        $svc->adjustLot($this->count->fresh(), $new->fresh(), $this->admin);
    }

    /** A new lot with no shortfall elsewhere is surplus, not a relabel. */
    public function test_adjust_lot_refuses_when_no_lot_came_up_short(): void
    {
        $this->scan('MG03J2', '2028-01-08');
        $this->scan('MG03J2', '2028-01-08');
        $this->scan('PC13Y1', '2028-03-01');
        $this->scan('NE17F1', '2029-05-05');
        $this->finish();

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$this->line('NE17F1', true)->id}/adjust-lot")
            ->assertStatus(422);

        $this->assertSame(0, DeviceUnit::where('lot_number', 'NE17F1')->count());
    }

    public function test_adjust_lot_needs_a_signed_unapproved_count_and_a_reviewer(): void
    {
        $this->countThePaperExample();
        $new = $this->line('NE17F1', adjustment: true);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$new->id}/adjust-lot")
            ->assertStatus(422);

        $this->finish();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$new->id}/adjust-lot")
            ->assertForbidden();

        app(StockCountService::class)->review($this->count->fresh(), $this->admin, 'approve');

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/stock-counts/{$this->count->id}/lines/{$new->id}/adjust-lot")
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ */
    /*  Incomplete reads                                                   */
    /* ------------------------------------------------------------------ */

    /** A read with no lot must not raise a bogus lot-mismatch line. */
    public function test_a_read_without_a_lot_is_held_for_review(): void
    {
        $scan = $this->scan(null);

        $this->assertSame(StockCountScan::INCOMPLETE, $scan->match_result);
        $this->assertTrue($scan->needsReview());
        $this->assertNull($scan->stock_count_item_id);
        $this->assertSame(0, $this->count->items()->adjustments()->count());

        $confirmed = app(StockCountScanService::class)->confirm($scan, ['lot_number' => 'PC13Y1'], $this->rep);

        $this->assertSame(StockCountScan::MATCH, $confirmed->match_result);
        $this->assertSame(1, $this->line('PC13Y1')->scanned_quantity);
    }

    /* ------------------------------------------------------------------ */
    /*  Offline replay                                                     */
    /* ------------------------------------------------------------------ */

    public function test_offline_sign_off_replays_once(): void
    {
        $this->countThePaperExample();

        $op = fn (string $id, array $extra = []) => [
            'client_id' => $id,
            'type'      => 'stock_count.submit',
            'payload'   => ['stock_count_id' => $this->count->id, 'lines' => [], ...$extra],
        ];

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/sync/push', ['operations' => [$op('a')]])
            ->assertJsonPath('results.0.status', 'error');
        $this->assertFalse($this->count->fresh()->isLocked());

        $signed = ['signature' => self::SIGNATURE_PNG, 'signed_by_name' => 'Mike Controller'];
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/sync/push', ['operations' => [$op('b', $signed), $op('c', $signed)]])
            ->assertJsonPath('results.0.status', 'applied')
            ->assertJsonPath('results.1.status', 'applied');

        $this->assertTrue($this->count->fresh()->isLocked());
        Mail::assertQueued(StockCountDocumentsMail::class, fn ($m) => $m->audience === 'controller');
        $this->assertSame(1, $this->count->documents()->where('type', 'stock_count_sheet')->count());
    }

    public function test_offline_sign_off_is_authorised(): void
    {
        $this->countThePaperExample();
        $stranger = $this->makeUser(UserRole::GeneralUser, 'stranger@signoff.test');

        $this->actingAs($stranger, 'sanctum')
            ->postJson('/api/sync/push', ['operations' => [[
                'client_id' => 'x', 'type' => 'stock_count.submit',
                'payload'   => [
                    'stock_count_id' => $this->count->id,
                    'signature' => self::SIGNATURE_PNG, 'signed_by_name' => 'Not Me',
                ],
            ]]])
            ->assertJsonPath('results.0.status', 'error');

        $this->assertFalse($this->count->fresh()->isLocked());
    }
}
