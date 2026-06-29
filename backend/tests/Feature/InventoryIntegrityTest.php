<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Notifications\InventoryAlertNotification;
use App\Notifications\TransferStatusNotification;
use App\Services\TransferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

    /** #1 — editing quantity writes a ledger entry instead of a silent overwrite. */
    public function test_manual_quantity_edit_records_an_adjustment_movement(): void
    {
        $admin = $this->makeUser(UserRole::Admin, 'admin@test.com');
        $item = InventoryItem::create([
            'ref_code' => 'ADJ-1', 'description' => 'Item', 'quantity' => 40,
            'stock_type' => 'warehouse', 'location' => 'jhb_master_warehouse', 'status' => 'available',
        ]);

        $this->actingAs($admin, 'sanctum')->putJson("/api/inventory/{$item->id}", [
            'ref_code' => 'ADJ-1', 'description' => 'Item', 'quantity' => 30,
            'stock_type' => 'warehouse', 'location' => 'jhb_master_warehouse', 'status' => 'available',
        ])->assertOk();

        $this->assertSame(30, $item->fresh()->quantity);

        $movement = StockMovement::where('inventory_item_id', $item->id)
            ->where('movement_type', 'adjustment')->first();
        $this->assertNotNull($movement, 'an adjustment movement should be logged');
        $this->assertSame(10, $movement->quantity);
    }

    /** #2 — a transfer for more than is available is rejected at creation. */
    public function test_transfer_creation_rejects_more_than_available(): void
    {
        $rep = $this->makeUser(UserRole::GeneralUser, 'rep@test.com');
        InventoryItem::create([
            'ref_code' => 'LOW', 'description' => 'Scarce', 'quantity' => 5,
            'stock_type' => 'warehouse', 'location' => 'jhb_master_warehouse', 'status' => 'available',
        ]);

        $this->expectException(ValidationException::class);

        app(TransferService::class)->createSourceToBoot([
            'from_location' => 'jhb_master_warehouse',
            'to_holder_user_id' => $rep->id,
            'items' => [['ref_code' => 'LOW', 'quantity' => 10]], // only 5 exist
        ], $rep);
    }

    /** #4 — dropping a holding below threshold fires a real-time low-stock alert. */
    public function test_low_stock_alert_fires_when_movement_drops_below_threshold(): void
    {
        Notification::fake();
        $admin = $this->makeUser(UserRole::Admin, 'admin2@test.com');
        $rep = $this->makeUser(UserRole::GeneralUser, 'rep2@test.com');

        InventoryItem::create([
            'ref_code' => 'THR', 'description' => 'Thresholded', 'quantity' => 12, 'min_threshold' => 10,
            'stock_type' => 'warehouse', 'location' => 'jhb_master_warehouse', 'status' => 'available',
        ]);

        $svc = app(TransferService::class);
        $t = $svc->createSourceToBoot([
            'from_location' => 'jhb_master_warehouse', 'to_holder_user_id' => $rep->id,
            'items' => [['ref_code' => 'THR', 'quantity' => 5]], // 12 -> 7, below threshold 10
        ], $rep);
        $svc->submit($t);
        $svc->approve($t->fresh(), $admin);
        $svc->sign($t->fresh(), ['signer_name' => 'Rep', 'signature_path' => 's.png'], $rep);

        Notification::assertSentTo($admin, InventoryAlertNotification::class);
    }

    /** #3 — admins are notified when a Transfer 2 is signed and awaits review. */
    public function test_admin_notified_when_transfer_awaits_review(): void
    {
        Notification::fake();
        $admin = $this->makeUser(UserRole::Admin, 'admin3@test.com');
        $rep = $this->makeUser(UserRole::GeneralUser, 'rep3@test.com');
        $hospital = Hospital::create(['name' => 'H', 'category' => 'private']);
        $hospital->users()->attach($rep->id, ['role' => 'rep']);

        InventoryItem::create([
            'ref_code' => 'B', 'description' => 'B', 'quantity' => 10, 'stock_type' => 'boot',
            'location' => 'boot_stock', 'status' => 'available', 'holder_user_id' => $rep->id,
        ]);

        $svc = app(TransferService::class);
        $t = $svc->createBootToHospital([
            'hospital_id' => $hospital->id, 'from_holder_user_id' => $rep->id,
            'hospital_stock_type' => 'consignment',
            'items' => [['ref_code' => 'B', 'quantity' => 2]],
        ], $rep);
        $svc->submit($t);
        $svc->approve($t->fresh(), $rep);
        $svc->sign($t->fresh(), ['signer_name' => 'Controller', 'signature_path' => 's.png'], $rep);

        Notification::assertSentTo($admin, TransferStatusNotification::class,
            fn ($n) => $n->event === 'awaiting_admin_review');
    }
}
