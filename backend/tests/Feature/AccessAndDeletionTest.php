<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockItem;
use App\Models\StockMovement;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessAndDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    private function user(UserRole $role, string $email): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->assignRole($role->value);

        return $user;
    }

    public function test_inventory_item_and_devices_are_soft_deleted_and_can_be_restored(): void
    {
        $admin = $this->user(UserRole::Admin, 'admin-delete@test.com');
        $location = Location::create(['name' => 'Office', 'type' => 'office']);
        $item = StockItem::create(['name' => 'Old product', 'item_code' => 'OLD']);
        $unit = $item->units()->create([
            'serial_number' => 'OLD-1',
            'lot_number' => 'LOT-OLD',
            'location_id' => $location->id,
            'status' => 'available',
        ]);
        $movement = StockMovement::create([
            'device_unit_id' => $unit->id,
            'ref_code' => 'OLD',
            'lot_number' => 'LOT-OLD',
            'quantity' => 1,
            'movement_type' => 'receipt',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/stock-items/{$item->id}")
            ->assertOk()
            ->assertJsonPath('units_archived', 1);

        $this->assertSoftDeleted('stock_items', ['id' => $item->id]);
        $this->assertSoftDeleted('device_units', ['id' => $unit->id]);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $movement->id,
            'device_unit_id' => $unit->id,
            'ref_code' => 'OLD',
            'lot_number' => 'LOT-OLD',
        ]);

        $this->actingAs($admin, 'sanctum')->getJson('/api/stock-items')
            ->assertOk()->assertJsonMissing(['name' => 'Old product']);
        $this->actingAs($admin, 'sanctum')->getJson('/api/stock-items?include_archived=1')
            ->assertOk()->assertJsonFragment(['name' => 'Old product']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/stock-items/{$item->id}/restore")
            ->assertOk();

        $this->assertNotSoftDeleted('stock_items', ['id' => $item->id]);
        $this->assertNotSoftDeleted('device_units', ['id' => $unit->id]);
    }

    public function test_soft_delete_is_blocked_for_a_pending_transfer_unit(): void
    {
        $admin = $this->user(UserRole::Admin, 'admin-pending@test.com');
        $location = Location::create(['name' => 'Office', 'type' => 'office']);
        $item = StockItem::create(['name' => 'Reserved product']);
        $item->units()->create([
            'location_id' => $location->id,
            'status' => 'pending_transfer',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/stock-items/{$item->id}")
            ->assertUnprocessable();

        $this->assertDatabaseHas('stock_items', ['id' => $item->id]);
    }

    public function test_user_permission_ticks_are_an_exact_allowlist(): void
    {
        $super = $this->user(UserRole::SuperAdmin, 'super@test.com');
        $rep = $this->user(UserRole::GeneralUser, 'limited@test.com');

        $this->actingAs($super, 'sanctum')->putJson("/api/users/{$rep->id}", [
            'permissions' => ['inventory.view', 'transfer.view', 'transfer.create'],
        ])->assertOk();

        $rep->refresh();
        $this->assertTrue($rep->can('inventory.view'));
        $this->assertTrue($rep->can('transfer.create'));
        $this->assertFalse($rep->can('hospital.view'));
        $this->assertFalse($rep->can('stock_count.capture'));

        $this->actingAs($rep, 'sanctum')->getJson('/api/transfers')->assertOk();
        $this->actingAs($rep, 'sanctum')->getJson('/api/hospitals')->assertForbidden();
    }

    public function test_role_permissions_can_be_replaced_with_checked_permissions(): void
    {
        $super = $this->user(UserRole::SuperAdmin, 'role-admin@test.com');
        $rep = $this->user(UserRole::GeneralUser, 'role-rep@test.com');
        $role = Role::findByName(UserRole::GeneralUser->value);

        $this->actingAs($super, 'sanctum')->putJson("/api/users/roles/{$role->id}", [
            'permissions' => ['inventory.view', 'transfer.view'],
        ])->assertOk();

        $rep->refresh();
        $this->assertTrue($rep->can('inventory.view'));
        $this->assertTrue($rep->can('transfer.view'));
        $this->assertFalse($rep->can('transfer.create'));
        $this->assertFalse($rep->can('hospital.view'));
    }
}
