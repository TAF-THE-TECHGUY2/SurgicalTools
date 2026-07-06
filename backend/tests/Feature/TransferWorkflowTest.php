<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\User;
use App\Services\TransferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class TransferWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $josh;
    protected User $mike;
    protected Location $joshBoot;
    protected Location $mikeBoot;
    protected Location $office;
    protected StockItem $trochar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));

        $this->admin = $this->makeUser(UserRole::Admin, 'admin@test.com');
        $this->josh = $this->makeUser(UserRole::GeneralUser, 'josh@test.com');
        $this->mike = $this->makeUser(UserRole::GeneralUser, 'mike@test.com');

        $this->joshBoot = Location::create(['name' => 'Josh Boot', 'type' => 'boot', 'owner_user_id' => $this->josh->id]);
        $this->mikeBoot = Location::create(['name' => 'Mike Boot', 'type' => 'boot', 'owner_user_id' => $this->mike->id]);
        $this->office = Location::create(['name' => 'JHB Office', 'type' => 'office']);

        $this->josh->update(['location_id' => $this->joshBoot->id]);
        $this->mike->update(['location_id' => $this->mikeBoot->id]);

        $this->trochar = StockItem::create(['name' => 'Trochar', 'catalogue_number' => 'TRO-12', 'min_threshold' => 1]);
        foreach ([['TR001', 'LOT123', '2027-12-01'], ['TR002', 'LOT124', '2028-02-01'], ['TR003', 'LOT130', '2027-07-01']] as [$sn, $lot, $exp]) {
            $this->trochar->units()->create([
                'serial_number' => $sn, 'lot_number' => $lot, 'expiry_date' => $exp,
                'location_id' => $this->joshBoot->id, 'status' => 'available',
            ]);
        }
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('password'), 'is_active' => true]);
        $u->assignRole($role->value);

        return $u;
    }

    protected function requestTransfer(User $requester, array $unitIds, ?Location $from = null, ?Location $to = null)
    {
        return app(TransferService::class)->request([
            'from_location_id' => ($from ?? $this->joshBoot)->id,
            'to_location_id'   => ($to ?? $this->mikeBoot)->id,
            'unit_ids'         => $unitIds,
            'signature_path'   => 'sig.png',
            'signer_name'      => $requester->name,
        ], $requester);
    }

    public function test_request_reserves_units_but_inventory_only_moves_on_approval(): void
    {
        $units = $this->trochar->units()->pluck('id')->take(2)->all();

        $transfer = $this->requestTransfer($this->mike, $units);

        // Pending: units still AT Josh Boot (inventory unchanged) but reserved.
        $this->assertSame('pending_approval', $transfer->status->value);
        $this->assertSame(3, DeviceUnit::where('location_id', $this->joshBoot->id)->count(), 'units stay at source while pending');
        $this->assertSame(2, DeviceUnit::where('status', 'pending_transfer')->count(), 'selected units reserved');
        $this->assertSame(1, $transfer->signatures()->count(), 'signature captured at request');

        // Approve as Josh (the source owner) — now the units move.
        app(TransferService::class)->approve($transfer->fresh(), $this->josh);

        $this->assertSame('completed', $transfer->fresh()->status->value);
        $this->assertSame(1, DeviceUnit::where('location_id', $this->joshBoot->id)->count(), 'Josh: 3 → 1');
        $this->assertSame(2, DeviceUnit::where('location_id', $this->mikeBoot->id)->where('stock_item_id', $this->trochar->id)->count(), 'Mike: +2');
        $this->assertSame(0, DeviceUnit::where('status', 'pending_transfer')->count());
        $this->assertSame(2, $transfer->fresh()->movements()->count(), 'one ledger row per unit');
        $this->assertSame(1, $transfer->fresh()->documents()->count(), 'transfer note PDF generated');
    }

    public function test_reject_releases_reserved_units(): void
    {
        $units = $this->trochar->units()->pluck('id')->take(1)->all();
        $transfer = $this->requestTransfer($this->mike, $units);

        app(TransferService::class)->reject($transfer->fresh(), $this->josh, 'Not available this week');

        $this->assertSame('rejected', $transfer->fresh()->status->value);
        $this->assertSame('Not available this week', $transfer->fresh()->rejection_reason);
        $this->assertSame(3, DeviceUnit::where('location_id', $this->joshBoot->id)->where('status', 'available')->count());
    }

    public function test_unit_in_pending_transfer_cannot_be_requested_twice(): void
    {
        $unitId = $this->trochar->units()->first()->id;
        $this->requestTransfer($this->mike, [$unitId]);

        $this->expectException(ValidationException::class);
        $this->requestTransfer($this->admin, [$unitId]);
    }

    public function test_approval_authority_source_owner_or_admin_only(): void
    {
        $units = $this->trochar->units()->pluck('id')->take(1)->all();
        $transfer = $this->requestTransfer($this->admin, $units); // out of Josh Boot

        $this->assertTrue($this->josh->can('approve', $transfer), 'source owner approves');
        $this->assertFalse($this->mike->can('approve', $transfer), 'unrelated rep denied');
        $this->assertTrue($this->admin->can('approve', $transfer), 'admin approves anything');
    }

    public function test_delivery_note_generated_for_hospital_destination(): void
    {
        $hospital = \App\Models\Hospital::create(['name' => 'Zamokuhle Hospital', 'category' => 'private']);
        $hospitalLocation = Location::create(['name' => 'Zamokuhle Hospital', 'type' => 'hospital', 'hospital_id' => $hospital->id]);

        $units = $this->trochar->units()->pluck('id')->take(1)->all();
        $transfer = $this->requestTransfer($this->josh, $units, $this->joshBoot, $hospitalLocation);

        app(TransferService::class)->approve($transfer->fresh(), $this->admin);

        $this->assertSame('delivery_note', $transfer->fresh()->documents()->first()?->type);
        $this->assertSame(1, DeviceUnit::where('location_id', $hospitalLocation->id)->count());
    }
}
