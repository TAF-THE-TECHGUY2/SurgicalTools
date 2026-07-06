<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\InventoryAlertNotification;
use App\Services\StockCountService;
use App\Services\TransferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InventoryIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));
    }

    protected function makeUser(UserRole $role, string $email): User
    {
        $u = User::create(['name' => $email, 'email' => $email, 'password' => Hash::make('password'), 'is_active' => true]);
        $u->assignRole($role->value);

        return $u;
    }

    /** Receiving units writes a receipt movement per device. */
    public function test_receiving_units_writes_ledger_entries(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin@test.com');
        $office = Location::create(['name' => 'JHB Office', 'type' => 'office']);
        $item = StockItem::create(['name' => 'Mesh', 'catalogue_number' => 'MESH-15']);

        $this->actingAs($admin, 'sanctum')->postJson("/api/stock-items/{$item->id}/units", [
            'location_id' => $office->id,
            'units' => [
                ['serial_number' => 'ME001', 'lot_number' => 'LOT1', 'expiry_date' => '2028-01-01'],
                ['serial_number' => 'ME002', 'lot_number' => 'LOT1', 'expiry_date' => '2028-01-01'],
            ],
        ])->assertCreated();

        $this->assertSame(2, $item->units()->count());
        $this->assertSame(2, StockMovement::where('movement_type', 'receipt')->count());
    }

    /** Real-time low-stock alert fires when an approval drops an item to/below threshold. */
    public function test_low_stock_alert_fires_on_transfer_approval(): void
    {
        Notification::fake();
        $admin = $this->makeUser(UserRole::Admin, 'admin2@test.com');
        $josh = $this->makeUser(UserRole::GeneralUser, 'josh2@test.com');

        $boot = Location::create(['name' => 'Josh Boot', 'type' => 'boot', 'owner_user_id' => $josh->id]);
        $office = Location::create(['name' => 'Office', 'type' => 'office']);
        $josh->update(['location_id' => $boot->id]);

        $item = StockItem::create(['name' => 'Guide Wire', 'catalogue_number' => 'GW', 'min_threshold' => 2]);
        $units = collect(range(1, 3))->map(fn ($i) => $item->units()->create([
            'serial_number' => "GW00{$i}", 'location_id' => $boot->id, 'status' => 'available',
        ]));

        $svc = app(TransferService::class);
        $t = $svc->request([
            'from_location_id' => $boot->id, 'to_location_id' => $office->id,
            'unit_ids' => [$units[0]->id], 'signature_path' => 's.png', 'signer_name' => 'Josh',
        ], $josh);
        $svc->approve($t->fresh(), $admin);

        // 3 - 1 = 2 on hand == threshold → alert.
        Notification::assertSentTo($admin, InventoryAlertNotification::class);
    }

    /** Approving a count with a negative variance writes off the missing units. */
    public function test_count_approval_marks_missing_units(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin3@test.com');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => 'Trochar', 'catalogue_number' => 'TRO']);
        foreach (range(1, 5) as $i) {
            $item->units()->create(['serial_number' => "T{$i}", 'location_id' => $boot->id, 'status' => 'available']);
        }

        $svc = app(StockCountService::class);
        $count = $svc->create(['location_id' => $boot->id], $admin);

        $line = $count->items()->first();
        $this->assertSame(5, $line->expected_quantity);

        $svc->submit($count, [['id' => $line->id, 'counted_quantity' => 3]]); // 2 missing
        $svc->review($count->fresh(), $admin, 'approve');

        $this->assertSame(3, DeviceUnit::where('location_id', $boot->id)->where('status', 'available')->count());
        $this->assertSame(2, DeviceUnit::where('status', 'missing')->count());
        $this->assertSame(2, StockMovement::where('movement_type', 'count_correction')->count());
    }
}
