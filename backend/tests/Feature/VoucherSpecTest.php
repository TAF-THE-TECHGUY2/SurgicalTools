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
    /*  §2 steps 4–6 — the recipient may sign before submission            */
    /* ------------------------------------------------------------------ */

    /** @return array<string, mixed> */
    protected function deliveryPayload(array $overrides = []): array
    {
        return [
            'from_location_id'  => $this->boot->id,
            'to_location_id'    => $this->hospital->id,
            'unit_ids'          => [$this->item->units()->first()->id],
            'signature'         => base64_encode('rep-sig'),
            'delivery_address'  => '20 Pine Avenue, Kempton Park',
            ...$overrides,
        ];
    }

    public function test_the_recipient_can_sign_while_the_voucher_is_raised(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', $this->deliveryPayload([
            'recipient_name'      => 'Sister N. Dlamini',
            'recipient_signature' => base64_encode('recipient-sig'),
            'recipient_email'     => 'theatre@arwyp.co.za',
        ]))->assertCreated();

        $transfer = Transfer::latest('id')->first();

        $this->assertSame('Sister N. Dlamini', $transfer->recipient_name);
        $this->assertNotNull($transfer->delivery_timestamp, 'DATE DELIVERED stamps itself');
        $this->assertSame('theatre@arwyp.co.za', $transfer->recipient_email);
        $this->assertSame(1, $transfer->signatures()->where('signer_role', 'recipient')->count());
        $this->assertSame(1, $transfer->signatures()->where('signer_role', 'requester')->count());
    }

    /** Signed at the counter, so an admin can approve without a second trip. */
    public function test_a_voucher_signed_on_creation_approves_without_an_override(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', $this->deliveryPayload([
            'recipient_name'      => 'Sister N. Dlamini',
            'recipient_signature' => base64_encode('recipient-sig'),
        ]))->assertCreated();

        $transfer = app(TransferService::class)->approve(
            Transfer::latest('id')->first(), $this->admin,
        );

        $this->assertSame('completed', $transfer->status->value);
        $this->assertFalse($transfer->admin_override, 'no override needed — it was signed for');
    }

    /** Leaving it out keeps the two-stage hand-over for a later delivery. */
    public function test_the_recipient_block_is_optional(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', $this->deliveryPayload())
            ->assertCreated();

        $transfer = Transfer::latest('id')->first();

        $this->assertNull($transfer->recipient_name);
        $this->assertSame(0, $transfer->signatures()->where('signer_role', 'recipient')->count());

        // Unsigned, so approval still refuses without an override.
        $this->expectException(ValidationException::class);
        app(TransferService::class)->approve($transfer, $this->admin);
    }

    /**
     * The pad has NAME OF RECIPIENT and SIGNATURE on every sheet, so stock
     * handed to another rep's boot can be signed for too. Whether a signature
     * is *required* is the approval gate's question, not the form's.
     */
    public function test_an_internal_move_can_also_be_signed_for(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', [
            'from_location_id'    => $this->boot->id,
            'to_location_id'      => $this->warehouse->id,
            'unit_ids'            => [$this->item->units()->first()->id],
            'signature'           => base64_encode('rep-sig'),
            'recipient_name'      => 'Warehouse Controller',
            'recipient_signature' => base64_encode('recipient-sig'),
        ])->assertCreated();

        $transfer = Transfer::latest('id')->first();

        $this->assertSame('Warehouse Controller', $transfer->recipient_name);
        $this->assertNotNull($transfer->delivery_timestamp);
        $this->assertSame(1, $transfer->signatures()->where('signer_role', 'recipient')->count());

        // And it still needs no address, because there is no street to give.
        $this->assertNull($transfer->delivery_address);
    }

    /** §4: a signature with nobody named is what the rule exists to prevent. */
    public function test_a_signature_without_a_name_is_rejected(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', $this->deliveryPayload([
            'recipient_signature' => base64_encode('recipient-sig'),
        ]))->assertStatus(422)->assertJsonValidationErrors('recipient_name');

        $this->assertSame(0, Transfer::count(), 'nothing is created, so no number is burned');
    }

    /** A name without a signature is fine — the recipient signs later. */
    public function test_a_name_without_a_signature_does_not_close_the_voucher(): void
    {
        $this->actingAs($this->rep)->postJson('/api/transfers', $this->deliveryPayload([
            'recipient_name' => 'Sister N. Dlamini',
        ]))->assertCreated();

        $transfer = Transfer::latest('id')->first();

        $this->assertNull($transfer->delivery_timestamp, 'not delivered until it is signed');
        $this->assertSame(0, $transfer->signatures()->where('signer_role', 'recipient')->count());
    }

    /**
     * §6 puts this flow in a hospital basement. Everything typed on the form
     * down there has to survive the trip back up, including the signature.
     */
    public function test_a_voucher_raised_offline_keeps_its_header_and_signature(): void
    {
        $this->actingAs($this->rep)->postJson('/api/sync/push', [
            'operations' => [[
                'client_id' => 'offline-voucher-1',
                'type'      => 'transfer.request',
                'payload'   => [
                    'from_location_id'    => $this->boot->id,
                    'to_location_id'      => $this->hospital->id,
                    'unit_ids'            => [$this->item->units()->first()->id],
                    'signature'           => base64_encode('rep-sig'),
                    'delivery_address'    => 'Theatre 4, Level 2',
                    'contact_person_name' => 'Sister N. Dlamini',
                    'invoice_reference'   => 'INV-4471',
                    'recipient_name'      => 'Sister N. Dlamini',
                    'recipient_signature' => base64_encode('recipient-sig'),
                    'recipient_email'     => 'theatre@arwyp.co.za',
                ],
            ]],
        ])->assertOk();

        $transfer = Transfer::latest('id')->first();

        $this->assertNotNull($transfer, 'the queued voucher replayed');
        $this->assertSame('Theatre 4, Level 2', $transfer->delivery_address);
        $this->assertSame('Sister N. Dlamini', $transfer->contact_person_name);
        $this->assertSame('INV-4471', $transfer->invoice_reference);
        $this->assertSame('Sister N. Dlamini', $transfer->recipient_name);
        $this->assertSame('theatre@arwyp.co.za', $transfer->recipient_email);
        $this->assertNotNull($transfer->delivery_timestamp);
        $this->assertSame(1, $transfer->signatures()->where('signer_role', 'recipient')->count());
    }

    /* ------------------------------------------------------------------ */
    /*  The shipped default must not reissue a written voucher             */
    /* ------------------------------------------------------------------ */

    /**
     * The highest pad seen in the field is 130233. If VOUCHER_START_NUMBER
     * ever goes missing — a rebuilt box, a restored database, a .env edited
     * in a hurry — the sequence falls back to whatever is compiled in, and a
     * fallback below the pads would silently reissue numbers that already
     * exist on carbon paper in a rep's car.
     *
     * This locks the default above that range. It is asserted without setting
     * config(), deliberately: the value under test is the one the application
     * actually ships with.
     */
    public function test_the_default_start_number_clears_the_known_paper_pads(): void
    {
        $this->artisan('surgical:voucher-status', ['--paper-high' => 130233])
            ->assertSuccessful();

        $this->assertGreaterThan(
            130233,
            (int) config('surgical.voucher.start_number'),
            'the shipped default must sit above every printed pad',
        );
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
