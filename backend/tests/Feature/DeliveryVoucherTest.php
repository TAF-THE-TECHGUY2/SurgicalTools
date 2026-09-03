<?php

namespace Tests\Feature;

use App\Enums\StockCountAdjustmentType;
use App\Enums\UserRole;
use App\Models\DeviceUnit;
use App\Models\Hospital;
use App\Models\HospitalContact;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\Transfer;
use App\Models\User;
use App\Notifications\TransferStatusNotification;
use App\Services\TransferService;
use App\Support\ReferenceGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Phase 5: the digitised "Stock Movement / Delivery Voucher" — its own serial,
 * the header fields, the recipient signature that gates approval, and scanned
 * items the dispatch list did not authorise.
 */
class DeliveryVoucherTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $rep;

    protected Location $boot;

    protected Location $hospitalLocation;

    protected Hospital $hospital;

    protected StockItem $circular;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));
        Notification::fake();
        Mail::fake();

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@voucher.test');
        $this->rep = $this->makeUser(UserRole::GeneralUser, 'rep@voucher.test');

        $this->boot = Location::create([
            'name' => 'Mike Oliver Boot', 'type' => 'boot', 'owner_user_id' => $this->rep->id,
        ]);
        $this->rep->update(['location_id' => $this->boot->id]);

        $this->hospital = Hospital::create([
            'name' => 'Arwyp Medical Centre', 'category' => 'private',
            'address' => '20 Pine Avenue, Kempton Park, 1619',
        ]);
        HospitalContact::create([
            'hospital_id' => $this->hospital->id, 'name' => 'Sister Dlamini',
            'role' => 'Stock Controller', 'email' => 'stock@arwyp.test', 'is_primary' => true,
        ]);
        $this->hospitalLocation = Location::create([
            'name' => 'Arwyp Medical Centre', 'type' => 'hospital', 'hospital_id' => $this->hospital->id,
        ]);

        $this->circular = StockItem::create([
            'name' => '29 Circular', 'catalogue_number' => '12012029', 'unit_price' => 4200,
        ]);
        foreach (range(1, 3) as $i) {
            $this->circular->units()->create([
                'serial_number' => "A{$i}", 'lot_number' => '11129D250603',
                'expiry_date' => '2027-06-03', 'location_id' => $this->boot->id, 'status' => 'available',
            ]);
        }
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

    protected function service(): TransferService
    {
        return app(TransferService::class);
    }

    protected function requestVoucher(array $overrides = [], ?Location $to = null): Transfer
    {
        return $this->service()->request(array_merge([
            'from_location_id' => $this->boot->id,
            'to_location_id'   => ($to ?? $this->hospitalLocation)->id,
            'unit_ids'         => $this->circular->units()->pluck('id')->take(1)->all(),
            'signature_path'   => 'sig.png',
            'signer_name'      => $this->rep->name,
        ], $overrides), $this->rep);
    }

    /* ------------------------------------------------------------------ */
    /*  Voucher number                                                     */
    /* ------------------------------------------------------------------ */

    /** The first digital voucher continues the paper pad's serial. */
    public function test_voucher_number_starts_at_the_configured_seed(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $transfer = $this->requestVoucher();

        $this->assertSame('130119', $transfer->voucher_number);
        // The internal reference is untouched and still the primary key.
        $this->assertStringStartsWith('TR-', $transfer->reference);
    }

    /** Subsequent vouchers increment, and never collide. */
    public function test_voucher_numbers_increment(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $units = $this->circular->units()->pluck('id')->all();
        $first = $this->requestVoucher(['unit_ids' => [$units[0]]]);
        $second = $this->requestVoucher(['unit_ids' => [$units[1]]]);

        $this->assertSame('130119', $first->voucher_number);
        $this->assertSame('130120', $second->voucher_number);
    }

    /** Ordering is numeric, so 99 → 100 rather than 99 → 9. */
    public function test_serial_ordering_is_numeric_not_lexical(): void
    {
        Transfer::create([
            'reference' => 'TR-2026-000900', 'voucher_number' => '99',
            'type' => 'standard', 'status' => 'completed', 'requested_by' => $this->admin->id,
        ]);

        $next = ReferenceGenerator::nextSerial(Transfer::class, 'voucher_number', 1);

        $this->assertSame('100', $next);
    }

    /** A hand-entered value that isn't purely digits is skipped, not cast to 0. */
    public function test_serial_ignores_non_numeric_values(): void
    {
        Transfer::create([
            'reference' => 'TR-2026-000901', 'voucher_number' => 'LEGACY-A',
            'type' => 'standard', 'status' => 'completed', 'requested_by' => $this->admin->id,
        ]);
        Transfer::create([
            'reference' => 'TR-2026-000902', 'voucher_number' => '130150',
            'type' => 'standard', 'status' => 'completed', 'requested_by' => $this->admin->id,
        ]);

        $this->assertSame('130151', ReferenceGenerator::nextSerial(Transfer::class, 'voucher_number', 130119));
    }

    /* ------------------------------------------------------------------ */
    /*  Voucher header                                                     */
    /* ------------------------------------------------------------------ */

    /** Address and contact person auto-populate from the destination hospital. */
    public function test_header_autopopulates_from_the_hospital(): void
    {
        $transfer = $this->requestVoucher(['invoice_reference' => 'INV-4471']);

        $this->assertSame('20 Pine Avenue, Kempton Park, 1619', $transfer->delivery_address);
        $this->assertSame('Sister Dlamini', $transfer->contact_person_name);
        $this->assertSame('INV-4471', $transfer->invoice_reference);
        $this->assertSame(now()->toDateString(), $transfer->transfer_date->toDateString());
    }

    /** Explicit values win over the hospital defaults. */
    public function test_header_accepts_explicit_overrides(): void
    {
        $transfer = $this->requestVoucher([
            'delivery_address'    => 'Theatre 4, Basement Level',
            'contact_person_name' => 'Dr Oliver',
            'transfer_date'       => '2026-07-20',
        ]);

        $this->assertSame('Theatre 4, Basement Level', $transfer->delivery_address);
        $this->assertSame('Dr Oliver', $transfer->contact_person_name);
        $this->assertSame('2026-07-20', $transfer->transfer_date->toDateString());
    }

    /**
     * The snapshot is the point: a later edit to the hospital record must not
     * rewrite where a past delivery went.
     */
    public function test_address_snapshot_survives_a_hospital_edit(): void
    {
        $transfer = $this->requestVoucher();

        $this->hospital->update(['address' => 'Somewhere else entirely']);

        $this->assertSame('20 Pine Avenue, Kempton Park, 1619', $transfer->fresh()->delivery_address);
    }

    /* ------------------------------------------------------------------ */
    /*  Recipient signature — the submission lock                          */
    /* ------------------------------------------------------------------ */

    public function test_recipient_signature_is_recorded(): void
    {
        $transfer = $this->requestVoucher();

        $signed = $this->service()->signDelivery($transfer, [
            'recipient_name' => 'Sister Dlamini',
            'signature'      => base64_encode('png-bytes'),
        ], $this->rep);

        $this->assertSame('Sister Dlamini', $signed->recipient_name);
        $this->assertNotNull($signed->delivery_timestamp);
        $this->assertTrue($this->service()->hasRecipientSignature($signed));

        // Both signatures coexist: the requester's and the recipient's.
        $this->assertSame(
            ['requester', 'recipient'],
            $signed->signatures->pluck('signer_role')->sort()->values()->reverse()->values()->all(),
        );
    }

    public function test_signature_requires_a_recipient_name(): void
    {
        $transfer = $this->requestVoucher();

        $this->expectException(ValidationException::class);
        $this->service()->signDelivery($transfer, [
            'recipient_name' => '   ',
            'signature'      => base64_encode('png-bytes'),
        ], $this->rep);
    }

    /** Voucher spec §3: the payload must actually decode. */
    public function test_signature_requires_a_decodable_payload(): void
    {
        $transfer = $this->requestVoucher();

        try {
            $this->service()->signDelivery($transfer, [
                'recipient_name' => 'Sister Dlamini',
                'signature'      => '!!!not-base64!!!',
            ], $this->rep);
            $this->fail('An undecodable signature should be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('signature', $e->errors());
        }

        $this->assertFalse($this->service()->hasRecipientSignature($transfer->fresh()));
    }

    /** A hospital delivery cannot complete without the recipient signing. */
    public function test_hospital_delivery_cannot_be_approved_unsigned(): void
    {
        $transfer = $this->requestVoucher();

        try {
            $this->service()->approve($transfer->fresh(), $this->admin);
            $this->fail('Approval should be blocked without a recipient signature.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('recipient signature', $e->errors()['status'][0]);
        }

        // Nothing moved.
        $this->assertSame('pending_approval', $transfer->fresh()->status->value);
        $this->assertSame(0, DeviceUnit::where('location_id', $this->hospitalLocation->id)->count());
    }

    public function test_hospital_delivery_completes_once_signed(): void
    {
        $transfer = $this->requestVoucher();

        $this->service()->signDelivery($transfer, [
            'recipient_name' => 'Sister Dlamini',
            'signature'      => base64_encode('png-bytes'),
        ], $this->rep);

        $this->service()->approve($transfer->fresh(), $this->admin);

        $this->assertSame('completed', $transfer->fresh()->status->value);
        $this->assertSame(1, DeviceUnit::where('location_id', $this->hospitalLocation->id)->count());
    }

    /** An admin override covers the case where the pad was signed on paper. */
    public function test_admin_override_bypasses_the_signature_gate(): void
    {
        $transfer = $this->requestVoucher();

        $this->service()->approve($transfer->fresh(), $this->admin, override: true);

        $this->assertSame('completed', $transfer->fresh()->status->value);
        $this->assertTrue($transfer->fresh()->admin_override);
    }

    /** An internal move (boot → office) needs no recipient signature. */
    public function test_non_hospital_transfer_needs_no_recipient_signature(): void
    {
        $office = Location::create(['name' => 'JHB Office', 'type' => 'office']);
        $transfer = $this->requestVoucher(to: $office);

        $this->service()->approve($transfer->fresh(), $this->admin);

        $this->assertSame('completed', $transfer->fresh()->status->value);
    }

    /* ------------------------------------------------------------------ */
    /*  Scanned off-list stock                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Previously a hard 422. An item the dispatch list doesn't authorise is
     * physically in the box either way, so it becomes a flagged line.
     */
    public function test_scanned_off_list_item_becomes_a_flagged_line(): void
    {
        $transfer = $this->requestVoucher([
            'scanned_adjustments' => [[
                'ref_code'            => '12012029',
                'lot_number'          => 'HQ45D250902',
                'expected_lot_number' => '11129D250603',
            ]],
        ]);

        $adjustments = $transfer->items->where('is_transfer_adjustment', true);
        $this->assertCount(1, $adjustments);

        $line = $adjustments->first();
        $this->assertSame('HQ45D250902', $line->lot_number);
        $this->assertSame('11129D250603', $line->expected_lot_number);
        $this->assertSame(StockCountAdjustmentType::LotMismatch->value, $line->adjustment_type);
        // No reserved unit behind it, so approval moves no stock for this line.
        $this->assertNull($line->device_unit_id);
        $this->assertSame('29 Circular', $line->description);
    }

    /** An unknown code is flagged as unlisted rather than a lot mismatch. */
    public function test_unknown_code_is_flagged_as_unlisted(): void
    {
        $transfer = $this->requestVoucher([
            'scanned_adjustments' => [['ref_code' => 'NOT-IN-CATALOGUE', 'lot_number' => 'L1']],
        ]);

        $line = $transfer->items->firstWhere('is_transfer_adjustment', true);
        $this->assertSame(StockCountAdjustmentType::UnlistedItem->value, $line->adjustment_type);
    }

    /**
     * An unlisted item has no expected lot — printing one next to "unlisted"
     * on the voucher would contradict itself.
     */
    public function test_unlisted_item_carries_no_expected_lot(): void
    {
        $transfer = $this->requestVoucher([
            'scanned_adjustments' => [[
                'ref_code'            => 'NOT-IN-CATALOGUE',
                'lot_number'          => 'L1',
                'expected_lot_number' => '11129D250603',
            ]],
        ]);

        $line = $transfer->items->firstWhere('is_transfer_adjustment', true);
        $this->assertSame(StockCountAdjustmentType::UnlistedItem->value, $line->adjustment_type);
        $this->assertNull($line->expected_lot_number);
    }

    /** Voucher spec §3: a flagged line alerts admin operations immediately. */
    public function test_flagged_line_alerts_admins(): void
    {
        $this->requestVoucher([
            'scanned_adjustments' => [['ref_code' => '12012029', 'lot_number' => 'ROGUE']],
        ]);

        Notification::assertSentTo(
            $this->admin,
            TransferStatusNotification::class,
            fn (TransferStatusNotification $n) => $n->event === 'discrepancy',
        );
    }

    /** A voucher may consist solely of scanned off-list stock. */
    public function test_voucher_may_be_entirely_off_list(): void
    {
        $transfer = $this->requestVoucher([
            'unit_ids'            => [],
            'scanned_adjustments' => [['ref_code' => '12012029', 'lot_number' => 'ROGUE']],
        ]);

        $this->assertCount(1, $transfer->items);
        $this->assertTrue($transfer->items->first()->is_transfer_adjustment);
    }

    /** Neither units nor scans is still an error. */
    public function test_empty_voucher_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        $this->requestVoucher(['unit_ids' => [], 'scanned_adjustments' => []]);
    }

    /** A unit already reserved elsewhere stays a hard error, not a discrepancy. */
    public function test_double_reserved_unit_is_still_an_error(): void
    {
        $unitId = $this->circular->units()->first()->id;
        $this->requestVoucher(['unit_ids' => [$unitId]]);

        $this->expectException(ValidationException::class);
        $this->requestVoucher(['unit_ids' => [$unitId]]);
    }

    /* ------------------------------------------------------------------ */
    /*  PDF + HTTP                                                         */
    /* ------------------------------------------------------------------ */

    /** The delivery note renders with the voucher number and recipient block. */
    public function test_delivery_note_renders_the_voucher(): void
    {
        config(['surgical.voucher.start_number' => 130119]);

        $transfer = $this->requestVoucher([
            'invoice_reference'   => 'INV-4471',
            'scanned_adjustments' => [['ref_code' => '12012029', 'lot_number' => 'ROGUE']],
        ]);

        $this->service()->signDelivery($transfer, [
            'recipient_name' => 'Sister Dlamini',
            'signature'      => base64_encode('png-bytes'),
        ], $this->rep);

        $this->service()->approve($transfer->fresh(), $this->admin);

        $doc = $transfer->fresh()->documents()->where('type', 'delivery_note')->firstOrFail();
        $bytes = Storage::disk($doc->disk)->get($doc->path);

        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertGreaterThan(1500, strlen($bytes));
    }

    public function test_sign_delivery_over_http(): void
    {
        $transfer = $this->requestVoucher();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/transfers/{$transfer->id}/sign-delivery", [
                'recipient_name' => 'Sister Dlamini',
                'signature'      => 'data:image/png;base64,'.base64_encode('png-bytes'),
            ])
            ->assertOk()
            ->assertJsonPath('data.recipient_name', 'Sister Dlamini');

        $this->assertTrue($this->service()->hasRecipientSignature($transfer->fresh()));
    }

    public function test_sign_delivery_validates_the_recipient(): void
    {
        $transfer = $this->requestVoucher();

        $this->actingAs($this->rep, 'sanctum')
            ->postJson("/api/transfers/{$transfer->id}/sign-delivery", [
                'signature' => base64_encode('png-bytes'),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('recipient_name');
    }

    /** The voucher fields reach the client. */
    public function test_voucher_fields_are_exposed_by_the_api(): void
    {
        config(['surgical.voucher.start_number' => 130119]);
        $transfer = $this->requestVoucher(['invoice_reference' => 'INV-9']);

        $this->actingAs($this->rep, 'sanctum')
            ->getJson("/api/transfers/{$transfer->id}")
            ->assertOk()
            ->assertJsonPath('data.voucher_number', '130119')
            ->assertJsonPath('data.invoice_reference', 'INV-9')
            ->assertJsonPath('data.contact_person_name', 'Sister Dlamini')
            ->assertJsonPath('data.delivery_address', '20 Pine Avenue, Kempton Park, 1619');
    }
}
