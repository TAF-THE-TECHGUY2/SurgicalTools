<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Hospital;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use App\Support\ReferenceGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The rules the Digital Transfer System dev-spec states for the voucher form
 * itself (§4's field matrix, §2's form initialisation) rather than for the
 * stock movement behind it.
 */
class VoucherSpecTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $rep;
    protected Location $boot;
    protected Location $warehouse;
    protected Location $hospital;
    protected StockItem $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@voucher.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@voucher.test');

        $this->boot = Location::create(['name' => 'Rep Boot', 'type' => 'boot', 'owner_user_id' => $this->rep->id]);
        $this->warehouse = Location::create(['name' => 'JHB Master Warehouse', 'type' => 'warehouse']);
        $this->rep->update(['location_id' => $this->boot->id]);

        $hospital = Hospital::create(['name' => 'Arwyp Medical Centre', 'category' => 'private']);
        $this->hospital = Location::create([
            'name' => 'Arwyp Medical Centre', 'type' => 'hospital', 'hospital_id' => $hospital->id,
        ]);

        $this->item = StockItem::create(['name' => 'Circular Stapler', 'catalogue_number' => '12012029']);
        foreach (['SN1', 'SN2'] as $sn) {
            $this->item->units()->create([
                'serial_number' => $sn, 'lot_number' => 'LOT9', 'expiry_date' => '2028-01-01',
                'location_id' => $this->boot->id, 'status' => 'available',
            ]);
        }
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('password'), 'is_active' => true]);
        $u->assignRole($role->value);

        return $u;
    }

    /** @param  array<string, mixed>  $overrides */
    protected function request(Location $to, array $overrides = [], ?User $as = null): Transfer
    {
        return app(TransferService::class)->request([
            'from_location_id' => $this->boot->id,
            'to_location_id'   => $to->id,
            'unit_ids'         => [$this->item->units()->first()->id],
            'signature_path'   => 'sig.png',
            'signer_name'      => 'Rep',
            ...$overrides,
        ], $as ?? $this->rep);
    }

    /* ------------------------------------------------------------------ */
    /*  §4 — Address is a required field                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_delivery_to_a_hospital_requires_an_address(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('delivery address is required');

        $this->request($this->hospital);
    }

    public function test_the_hospital_record_satisfies_the_address_requirement(): void
    {
        $this->hospital->hospital->update(['address' => '20 Pine Avenue, Kempton Park']);

        $transfer = $this->request($this->hospital);

        $this->assertSame('20 Pine Avenue, Kempton Park', $transfer->delivery_address);
    }

    /** A typed address wins over the hospital's, for a one-off ward delivery. */
    public function test_a_typed_address_overrides_the_hospital_record(): void
    {
        $this->hospital->hospital->update(['address' => '20 Pine Avenue, Kempton Park']);

        $transfer = $this->request($this->hospital, ['delivery_address' => 'Theatre 4, Level 2']);

        $this->assertSame('Theatre 4, Level 2', $transfer->delivery_address);
    }

    /**
     * An internal move has no street to give — it is addressed by the location
     * name — so the requirement must not reach it.
     */
    public function test_an_internal_move_needs_no_address(): void
    {
        $transfer = $this->request($this->warehouse);

        $this->assertNull($transfer->delivery_address);
        $this->assertSame('pending_approval', $transfer->status->value);
    }

    /** An unlisted clinic is a delivery too, so it is held to the same rule. */
    public function test_an_ad_hoc_destination_requires_an_address(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('delivery address is required');

        app(TransferService::class)->request([
            'from_location_id' => $this->boot->id,
            'to_location_id'   => null,
            'new_destination'  => ['name' => 'Kempton Day Clinic'],
            'unit_ids'         => [$this->item->units()->first()->id],
            'signature_path'   => 'sig.png',
            'signer_name'      => 'Rep',
        ], $this->rep);
    }

    /* ------------------------------------------------------------------ */
    /*  §2 step 1 — the number is on the form before it is submitted       */
    /* ------------------------------------------------------------------ */

    public function test_the_previewed_number_matches_the_one_actually_issued(): void
    {
        config(['surgical.voucher.start_number' => 140001]);

        $preview = ReferenceGenerator::peekSerial(Transfer::class, 'voucher_number', 140001);
        $transfer = $this->request($this->warehouse);

        $this->assertSame('140001', $preview);
        $this->assertSame($preview, $transfer->voucher_number);
    }

    /** Previewing must never consume a number: no gaps from abandoned forms. */
    public function test_previewing_does_not_consume_a_number(): void
    {
        config(['surgical.voucher.start_number' => 140001]);

        foreach (range(1, 5) as $ignored) {
            ReferenceGenerator::peekSerial(Transfer::class, 'voucher_number', 140001);
        }

        $this->assertSame('140001', $this->request($this->warehouse)->voucher_number);
    }

    public function test_the_preview_endpoint_reports_the_number_as_provisional(): void
    {
        config(['surgical.voucher.start_number' => 140001]);

        $this->actingAs($this->rep)
            ->getJson('/api/transfers/next-voucher')
            ->assertOk()
            ->assertJson(['voucher_number' => '140001', 'provisional' => true]);
    }

    /** The literal route must not be swallowed by the {transfer} binding. */
    public function test_the_preview_route_is_not_mistaken_for_a_transfer_id(): void
    {
        $this->actingAs($this->rep)
            ->getJson('/api/transfers/next-voucher')
            ->assertOk()
            ->assertJsonStructure(['voucher_number', 'provisional', 'date']);
    }

    /* ------------------------------------------------------------------ */
    /*  §4 — the header date is automatic unless an admin overrides it     */
    /* ------------------------------------------------------------------ */

    public function test_a_rep_cannot_back_date_a_voucher(): void
    {
        $response = $this->actingAs($this->rep)->postJson('/api/transfers', [
            'from_location_id' => $this->boot->id,
            'to_location_id'   => $this->warehouse->id,
            'unit_ids'         => [$this->item->units()->first()->id],
            'signature'        => base64_encode('sig'),
            'transfer_date'    => '2020-01-01',
        ]);

        $response->assertCreated();
        $this->assertSame(
            now()->toDateString(),
            Transfer::latest('id')->first()->transfer_date->toDateString(),
            'a rep-supplied date is ignored',
        );
    }

    public function test_an_admin_may_override_the_header_date(): void
    {
        $this->actingAs($this->admin)->postJson('/api/transfers', [
            'from_location_id' => $this->boot->id,
            'to_location_id'   => $this->warehouse->id,
            'unit_ids'         => [$this->item->units()->first()->id],
            'signature'        => base64_encode('sig'),
            'transfer_date'    => '2026-10-01',
        ])->assertCreated();

        $this->assertSame('2026-10-01', Transfer::latest('id')->first()->transfer_date->toDateString());
    }

    /* ------------------------------------------------------------------ */
    /*  §5 — distribution                                                  */
    /* ------------------------------------------------------------------ */

    /** The subject line operations filter their mailbox on. */
    public function test_the_mail_subject_follows_the_specified_format(): void
    {
        $this->hospital->hospital->update(['address' => '20 Pine Avenue']);
        $transfer = $this->request($this->hospital);

        $document = $transfer->documents()->create([
            'type' => 'delivery_note', 'original_name' => 'voucher.pdf',
            'disk' => config('filesystems.default'), 'path' => 'voucher.pdf', 'mime_type' => 'application/pdf',
        ]);

        $envelope = (new \App\Mail\TransferDocumentMail($transfer, $document, 'Delivery Note'))->envelope();

        $this->assertSame(
            "Transfer Voucher #{$transfer->voucher_number} - Arwyp Medical Centre",
            $envelope->subject,
        );
    }
}
