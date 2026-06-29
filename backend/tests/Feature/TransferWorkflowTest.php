<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\Transfer;
use App\Models\User;
use App\Services\TransferService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TransferWorkflowTest extends TestCase
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

    public function test_transfer_one_moves_stock_to_boot_and_generates_pdf(): void
    {
        $rep = $this->makeUser(UserRole::GeneralUser, 'rep@test.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin@test.com');

        $warehouse = InventoryItem::create([
            'ref_code' => 'TRO-12', 'description' => 'Trocar 12mm', 'lot_number' => 'L1',
            'quantity' => 40, 'stock_type' => 'warehouse', 'location' => 'jhb_master_warehouse',
            'status' => 'available', 'unit_price' => 850,
        ]);

        $svc = app(TransferService::class);
        $transfer = $svc->createSourceToBoot([
            'from_location' => 'jhb_master_warehouse',
            'to_holder_user_id' => $rep->id,
            'items' => [['ref_code' => 'TRO-12', 'quantity' => 5]],
        ], $rep);

        $svc->submit($transfer);
        $svc->approve($transfer->fresh(), $admin);
        $svc->sign($transfer->fresh(), [
            'signer_name' => 'Rep', 'signer_role' => 'rep',
            'signature_path' => 'sig.png', 'ip_address' => '127.0.0.1',
        ], $rep);

        $transfer = $transfer->fresh(['documents']);

        $this->assertSame('completed', $transfer->status->value);
        $this->assertSame(35, $warehouse->fresh()->quantity, 'warehouse decremented');
        $this->assertSame(5, (int) InventoryItem::where('ref_code', 'TRO-12')
            ->where('location', 'boot_stock')->where('holder_user_id', $rep->id)->sum('quantity'),
            'boot incremented');
        $this->assertSame(1, $transfer->documents->where('type', 'transfer_pdf')->count(), 'PDF generated');
        $this->assertGreaterThan(0, $transfer->movements()->count(), 'movement ledger written');
    }

    public function test_general_user_cannot_approve_transfer_for_unassigned_hospital(): void
    {
        $repA = $this->makeUser(UserRole::GeneralUser, 'a@test.com');
        $repB = $this->makeUser(UserRole::GeneralUser, 'b@test.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin2@test.com');

        $hospital = Hospital::create(['name' => 'Arwyp', 'category' => 'private']);
        $hospital->users()->attach($repA->id, ['role' => 'rep']); // only repA assigned

        InventoryItem::create([
            'ref_code' => 'X', 'description' => 'X', 'quantity' => 10, 'stock_type' => 'boot',
            'location' => 'boot_stock', 'status' => 'available', 'holder_user_id' => $repA->id,
        ]);

        $svc = app(TransferService::class);
        $transfer = $svc->createBootToHospital([
            'hospital_id' => $hospital->id, 'from_holder_user_id' => $repA->id,
            'hospital_stock_type' => 'consignment',
            'items' => [['ref_code' => 'X', 'quantity' => 2]],
        ], $repA);
        $svc->submit($transfer);

        // repB (not assigned) is denied; repA (assigned) and admin are allowed.
        $this->assertFalse($repB->can('approve', $transfer), 'unassigned rep denied');
        $this->assertTrue($repA->can('approve', $transfer), 'assigned rep allowed');
        $this->assertTrue($admin->can('approve', $transfer), 'admin allowed');
    }

    public function test_transfer_two_requires_admin_review_after_signature(): void
    {
        $rep = $this->makeUser(UserRole::GeneralUser, 'rep3@test.com');
        $admin = $this->makeUser(UserRole::Admin, 'admin3@test.com');
        $hospital = Hospital::create(['name' => 'Milpark', 'category' => 'netcare']);
        $hospital->users()->attach($rep->id, ['role' => 'rep']);

        InventoryItem::create([
            'ref_code' => 'Y', 'description' => 'Y', 'quantity' => 10, 'stock_type' => 'boot',
            'location' => 'boot_stock', 'status' => 'available', 'holder_user_id' => $rep->id,
        ]);

        $svc = app(TransferService::class);
        $transfer = $svc->createBootToHospital([
            'hospital_id' => $hospital->id, 'from_holder_user_id' => $rep->id,
            'hospital_stock_type' => 'consignment',
            'items' => [['ref_code' => 'Y', 'quantity' => 3]],
        ], $rep);
        $svc->submit($transfer);
        $svc->approve($transfer->fresh(), $rep);
        $svc->sign($transfer->fresh(), [
            'signer_name' => 'Controller', 'signer_role' => 'hospital_controller',
            'signature_path' => 'sig2.png',
        ], $rep);

        // After signing, Transfer 2 awaits admin review (stock not yet posted).
        $this->assertSame('awaiting_admin_review', $transfer->fresh()->status->value);
        $this->assertSame('delivery_note', $transfer->fresh()->documents->first()?->type);

        $svc->adminReview($transfer->fresh(), $admin);
        $transfer = $transfer->fresh();

        $this->assertSame('completed', $transfer->status->value);
        $this->assertSame(3, (int) InventoryItem::where('ref_code', 'Y')
            ->where('location', 'hospital_stock')->where('hospital_id', $hospital->id)->sum('quantity'));
    }
}
