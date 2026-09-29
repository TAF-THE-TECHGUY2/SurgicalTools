<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Location;
use App\Models\Setting;
use App\Models\StockCountScan;
use App\Models\StockItem;
use App\Models\SupplierLabelTemplate;
use App\Models\User;
use App\Services\ClaudeVisionClient;
use App\Services\ScanExtractionService;
use App\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Mockery;
use Tests\TestCase;

/**
 * Spec §3.2: per-supplier label templates, configurable without a code
 * release. The Waston fixtures are the two barcodes printed on a real
 * Endoscopic Linear Cutter II label.
 */
class SupplierLabelTemplateTest extends TestCase
{
    use RefreshDatabase;

    /** Barcode 1: the (00) field carries the product Code 12019101. */
    protected const WASTON_CODE = '(00)693659440120191013';

    /** Barcode 2: (11) is the expiry, (21) is lot + 4-digit serial. */
    protected const WASTON_LOT = '(11)260206(21)HSDS2302020062';

    protected User $admin;

    protected User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));
        Notification::fake();

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@tpl.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@tpl.test');
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

    protected function extraction(): ScanExtractionService
    {
        return app(ScanExtractionService::class);
    }

    protected function waston(): SupplierLabelTemplate
    {
        return SupplierLabelTemplate::where('supplier', 'WASTON')->firstOrFail();
    }

    /* ------------------------------------------------------------------ */
    /*  The seeded Waston template                                         */
    /* ------------------------------------------------------------------ */

    public function test_waston_code_barcode_yields_the_product_code(): void
    {
        $out = $this->extraction()->readBarcode(self::WASTON_CODE);

        $this->assertSame('12019101', $out['ref']);
        $this->assertSame($this->waston()->id, $out['template_id']);
    }

    public function test_waston_lot_barcode_yields_expiry_lot_and_serial(): void
    {
        $out = $this->extraction()->readBarcode(self::WASTON_LOT);

        $this->assertSame('2026-02-06', $out['expiry_date']);
        $this->assertSame('HSDS230202', $out['lot_number']);
        $this->assertSame('0062', $out['serial_number']);
    }

    public function test_raw_scanner_form_of_the_waston_lot_barcode(): void
    {
        $out = $this->extraction()->readBarcode('1126020621HSDS2302020062');

        $this->assertSame('2026-02-06', $out['expiry_date']);
        $this->assertSame('HSDS230202', $out['lot_number']);
    }

    /** Why the template exists: plain GS1 reads no expiry and no lot here. */
    public function test_plain_gs1_misreads_the_waston_label(): void
    {
        $out = $this->extraction()->parseGs1(self::WASTON_LOT);

        $this->assertNull($out['expiry_date']);
        $this->assertNull($out['lot_number']);
        $this->assertSame('HSDS2302020062', $out['serial_number']);
    }

    public function test_standard_gs1_labels_are_untouched(): void
    {
        $out = $this->extraction()->readBarcode('(01)03456789012345(17)270603(10)11129D250603');

        $this->assertNull($out['template_id']);
        $this->assertSame('2027-06-03', $out['expiry_date']);
        $this->assertSame('11129D250603', $out['lot_number']);
    }

    /**
     * The two Waston barcodes end to end: the Code barcode resolves the
     * product but has no lot, so it is held; the lot barcode completes it.
     */
    public function test_waston_label_counts_after_both_barcodes(): void
    {
        $site = Location::create(['name' => 'Zamokuhle', 'type' => 'boot', 'owner_user_id' => $this->rep->id]);
        $item = StockItem::create(['name' => 'Endoscopic Linear Cutter II', 'catalogue_number' => '12019101', 'supplier' => 'WASTON']);
        $item->units()->create([
            'serial_number' => '0062', 'lot_number' => 'HSDS230202', 'expiry_date' => '2026-02-06',
            'location_id' => $site->id, 'status' => 'available',
        ]);
        $count = app(StockCountService::class)->create(['location_id' => $site->id, 'assigned_to' => $this->rep->id], $this->admin);

        $first = $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$count->id}/scan", ['barcode' => self::WASTON_CODE])
            ->assertCreated()
            ->assertJsonPath('needs_review', true)
            ->assertJsonPath('scan.match_result', StockCountScan::INCOMPLETE)
            ->json('scan.id');

        $second = $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', ['barcode' => self::WASTON_LOT])
            ->assertOk()
            ->json('extracted');

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/stock-counts/{$count->id}/scan/{$first}/confirm", [
                'lot_number' => $second['lot_number'], 'expiry_date' => $second['expiry_date'],
            ])
            ->assertOk()
            ->assertJsonPath('scan.match_result', StockCountScan::MATCH);

        $this->assertSame(1, $count->items()->expected()->firstOrFail()->scanned_quantity);
        $this->assertSame(0, $count->items()->adjustments()->count());
    }

    public function test_an_explicit_template_overrides_detection(): void
    {
        $custom = SupplierLabelTemplate::create([
            'supplier' => 'ACME', 'name' => 'ACME lot-in-serial',
            'field_mappings' => ['lot_number' => ['ai' => '21', 'pattern' => '^(\w{4})']],
        ]);

        $out = $this->extraction()->readBarcode(self::WASTON_LOT, $custom);

        $this->assertSame('HSDS', $out['lot_number']);
        $this->assertNull($out['expiry_date'] ?? null, 'Unmapped fields keep the plain GS1 reading.');
        $this->assertSame($custom->id, $out['template_id']);
    }

    /** A non-GS1 Code 128 label is readable once a template maps its raw text. */
    public function test_raw_text_template_reads_a_non_gs1_barcode(): void
    {
        $raw = 'FEN|REF:FLTC10|LOT:PX2291|EXP:20280108';

        try {
            $this->extraction()->readBarcode($raw);
            $this->fail('Without a template this is not a GS1 barcode.');
        } catch (\InvalidArgumentException) {
            // expected
        }

        SupplierLabelTemplate::create([
            'supplier' => 'FENGHM', 'name' => 'FENGHM pipe-delimited',
            'match_pattern' => '^FEN\|',
            'field_mappings' => [
                'ref'         => ['source' => 'raw', 'pattern' => 'REF:([^|]+)'],
                'lot_number'  => ['source' => 'raw', 'pattern' => 'LOT:([^|]+)'],
                'expiry_date' => ['source' => 'raw', 'pattern' => 'EXP:(\d{8})', 'date_format' => 'YYYYMMDD'],
            ],
        ]);

        $out = $this->extraction()->readBarcode($raw);

        $this->assertSame('FLTC10', $out['ref']);
        $this->assertSame('PX2291', $out['lot_number']);
        $this->assertSame('2028-01-08', $out['expiry_date']);
    }

    public function test_inactive_templates_are_not_applied(): void
    {
        $this->waston()->update(['is_active' => false]);

        $out = $this->extraction()->readBarcode(self::WASTON_LOT);

        $this->assertNull($out['template_id']);
        $this->assertNull($out['lot_number']);
    }

    /* ------------------------------------------------------------------ */
    /*  Photo reader hints                                                 */
    /* ------------------------------------------------------------------ */

    public function test_photo_reader_receives_supplier_hints(): void
    {
        $vision = Mockery::mock(ClaudeVisionClient::class);
        $vision->shouldReceive('extractLabel')
            ->once()
            ->withArgs(fn ($bin, $mime, $hints) => str_contains((string) $hints, 'For WASTON labels')
                && str_contains((string) $hints, 'For SURGICAL DEVICES labels'))
            ->andReturn([
                'ref' => '533-005-925', 'gtin' => null, 'lot_number' => '24042006907',
                'expiry_date' => '2029-04-27', 'serial_number' => null, 'confidence' => 0.95, 'raw_text' => '',
            ]);
        $this->app->instance(ClaudeVisionClient::class, $vision);

        $out = app(ScanExtractionService::class)->extractFromImage('bytes', 'image/jpeg');

        $this->assertSame('24042006907', $out['lot_number']);
    }

    public function test_a_chosen_template_sends_only_its_own_hints(): void
    {
        $own = SupplierLabelTemplate::where('supplier', 'SURGICAL DEVICES')->firstOrFail();

        $vision = Mockery::mock(ClaudeVisionClient::class);
        $vision->shouldReceive('extractLabel')
            ->once()
            ->withArgs(fn ($bin, $mime, $hints) => str_starts_with((string) $hints, 'This is a SURGICAL DEVICES label.')
                && ! str_contains((string) $hints, 'WASTON'))
            ->andReturn([
                'ref' => null, 'gtin' => null, 'lot_number' => null, 'expiry_date' => null,
                'serial_number' => null, 'confidence' => 0.1, 'raw_text' => '',
            ]);
        $this->app->instance(ClaudeVisionClient::class, $vision);

        $this->actingAs($this->rep, 'sanctum')
            ->post('/api/scan/extract', [
                'photo' => UploadedFile::fake()->image('label.jpg'),
                'template_id' => $own->id,
            ], ['Accept' => 'application/json'])
            ->assertOk();
    }

    /* ------------------------------------------------------------------ */
    /*  REF matching tolerates label punctuation                           */
    /* ------------------------------------------------------------------ */

    public function test_ref_resolves_ignoring_hyphens_and_spaces(): void
    {
        $grasper = StockItem::create(['name' => 'Johan Atraumatic Grasper', 'catalogue_number' => '533005925']);
        $cutter = StockItem::create(['name' => 'Linear Cutter', 'catalogue_number' => 'HSD-S']);

        $this->assertSame($grasper->id, StockItem::resolveFromScan(['ref' => '533-005-925'])?->id);
        $this->assertSame($cutter->id, StockItem::resolveFromScan(['ref' => 'hsd s'])?->id);
        $this->assertNull(StockItem::resolveFromScan(['ref' => '---']));
    }

    /* ------------------------------------------------------------------ */
    /*  Admin management                                                   */
    /* ------------------------------------------------------------------ */

    public function test_admin_manages_templates_without_a_release(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/label-templates', [
                'supplier' => 'DANNIK', 'name' => 'Dannik GS1', 'barcode_type' => 'gs1',
                'field_mappings' => ['ref' => ['ai' => '240']],
                'ocr_hints' => 'REF is under the barcode.',
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/label-templates/{$id}", [
                'supplier' => 'DANNIK', 'name' => 'Dannik GS1', 'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actingAs($this->admin, 'sanctum')->deleteJson("/api/label-templates/{$id}")->assertNoContent();
        $this->assertNull(SupplierLabelTemplate::find($id));
    }

    public function test_template_validation_rejects_broken_patterns_and_fields(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/label-templates', [
                'supplier' => 'X', 'name' => 'Broken',
                'match_pattern' => '([unclosed',
                'field_mappings' => [
                    'colour'     => ['ai' => '10'],
                    'lot_number' => ['pattern' => '(.+)'],
                ],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['match_pattern', 'field_mappings.colour', 'field_mappings.lot_number.ai']);
    }

    public function test_only_admins_change_templates_but_scanners_can_list_them(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/label-templates', ['supplier' => 'X', 'name' => 'Y'])
            ->assertForbidden();

        $this->actingAs($this->rep, 'sanctum')
            ->getJson('/api/label-templates')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_try_an_unsaved_template(): void
    {
        StockItem::create(['name' => 'Linear Cutter', 'catalogue_number' => '12019101']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/label-templates/test', [
                'barcode'  => self::WASTON_CODE,
                'template' => [
                    'supplier' => 'WASTON', 'name' => 'draft',
                    'match_pattern' => '^\(00\)6936594',
                    'field_mappings' => ['ref' => ['ai' => '00', 'pattern' => '^\d{9}(\d{8})\d$']],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('extracted.ref', '12019101')
            ->assertJsonPath('pattern_matches', true)
            ->assertJsonPath('stock_item.catalogue_number', '12019101');

        $this->assertSame(2, SupplierLabelTemplate::count(), 'Trying a template must not save it.');
    }

    /* ------------------------------------------------------------------ */
    /*  Accounts address                                                   */
    /* ------------------------------------------------------------------ */

    public function test_admin_sets_the_accounts_address(): void
    {
        $this->actingAs($this->rep, 'sanctum')->getJson('/api/settings/stock-counts')->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/stock-counts', ['accounts_emails' => ['accounts@sd.test', 'finance@sd.test']])
            ->assertOk()
            ->assertJsonPath('data.accounts_emails', ['accounts@sd.test', 'finance@sd.test']);

        $this->assertSame(['accounts@sd.test', 'finance@sd.test'], Setting::accountsEmails());

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/settings/stock-counts', ['accounts_emails' => ['not-an-email']])
            ->assertStatus(422);
    }

    public function test_accounts_address_defaults_to_config(): void
    {
        config(['surgical.notifications.accounts' => 'a@sd.test, b@sd.test']);

        $this->assertSame(['a@sd.test', 'b@sd.test'], Setting::accountsEmails());
    }
}
