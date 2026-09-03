<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\DeviceUnit;
use App\Models\Location;
use App\Models\StockCountItem;
use App\Models\StockItem;
use App\Models\User;
use App\Services\StockCountService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Phase 1: the expected snapshot is per (stock item, lot number). Without this
 * the spec's core exception rule — a known product turning up under an
 * unexpected lot — has nothing to compare against.
 */
class StockCountLotSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        Storage::fake(config('filesystems.default'));
        Notification::fake();
    }

    protected function admin(string $email = 'admin@snapshot.test'): User
    {
        $u = User::create([
            'name' => 'Admin', 'email' => $email,
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $u->assignRole(UserRole::Admin->value);

        return $u;
    }

    /** Two lots of the same catalogue item produce two expected lines. */
    public function test_same_item_under_two_lots_produces_two_expected_lines(): void
    {
        $admin = $this->admin();
        $boot = Location::create(['name' => 'Mike Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => '29 Circular', 'catalogue_number' => '12012029']);

        // Three units on lot 11129D250603, two on lot HQ45D250902.
        foreach (range(1, 3) as $i) {
            $item->units()->create([
                'serial_number' => "A{$i}", 'lot_number' => '11129D250603',
                'expiry_date' => '2027-06-03', 'location_id' => $boot->id, 'status' => 'available',
            ]);
        }
        foreach (range(1, 2) as $i) {
            $item->units()->create([
                'serial_number' => "B{$i}", 'lot_number' => 'HQ45D250902',
                'expiry_date' => '2027-09-02', 'location_id' => $boot->id, 'status' => 'available',
            ]);
        }

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);
        $lines = $count->items()->orderBy('lot_number')->get();

        $this->assertCount(2, $lines);

        $this->assertSame('11129D250603', $lines[0]->lot_number);
        $this->assertSame(3, $lines[0]->expected_quantity);
        $this->assertSame('2027-06-03', $lines[0]->expiry_date->toDateString());

        $this->assertSame('HQ45D250902', $lines[1]->lot_number);
        $this->assertSame(2, $lines[1]->expected_quantity);
        $this->assertSame('2027-09-02', $lines[1]->expiry_date->toDateString());

        // Both lines carry the catalogue code, and neither is an adjustment.
        $this->assertSame(['12012029', '12012029'], $lines->pluck('ref_code')->all());
        $this->assertSame([false, false], $lines->pluck('is_adjustment')->all());
        $this->assertSame([0, 0], $lines->pluck('scanned_quantity')->all());
    }

    /** Units carrying no lot collapse into a single no-lot line. */
    public function test_units_without_a_lot_produce_one_line(): void
    {
        $admin = $this->admin('admin2@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => 'Contour Stapler', 'catalogue_number' => '12009045']);

        foreach (range(1, 4) as $i) {
            $item->units()->create([
                'serial_number' => "C{$i}", 'location_id' => $boot->id, 'status' => 'available',
            ]);
        }

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);

        $this->assertCount(1, $count->items);
        $this->assertNull($count->items->first()->lot_number);
        $this->assertSame(4, $count->items->first()->expected_quantity);
    }

    /** Distinct catalogue items each get their own per-lot lines. */
    public function test_lines_are_scoped_per_item_as_well_as_per_lot(): void
    {
        $admin = $this->admin('admin3@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);

        $circular = StockItem::create(['name' => '29 Circular', 'catalogue_number' => '12012029']);
        $stapler = StockItem::create(['name' => 'Contour Stapler', 'catalogue_number' => '12009045']);

        $circular->units()->create(['serial_number' => 'X1', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'available']);
        $circular->units()->create(['serial_number' => 'X2', 'lot_number' => 'L2', 'location_id' => $boot->id, 'status' => 'available']);
        $stapler->units()->create(['serial_number' => 'Y1', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'available']);

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);

        $this->assertCount(3, $count->items);
        $this->assertSame(
            [[$circular->id, 'L1'], [$circular->id, 'L2'], [$stapler->id, 'L1']],
            $count->items()->orderBy('stock_item_id')->orderBy('lot_number')->get()
                ->map(fn ($l) => [$l->stock_item_id, $l->lot_number])->all(),
        );
    }

    /** Pending-transfer units still count as on hand; missing/used ones don't. */
    public function test_snapshot_respects_unit_status(): void
    {
        $admin = $this->admin('admin4@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => 'Guide Wire', 'catalogue_number' => 'GW']);

        $item->units()->create(['serial_number' => 'G1', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'available']);
        $item->units()->create(['serial_number' => 'G2', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'pending_transfer']);
        $item->units()->create(['serial_number' => 'G3', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'missing']);
        $item->units()->create(['serial_number' => 'G4', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'used']);

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);

        $this->assertCount(1, $count->items);
        $this->assertSame(2, $count->items->first()->expected_quantity);
    }

    /**
     * A shortfall on one lot writes off units of that lot only — the other
     * lot's units, counted correctly, must survive.
     */
    public function test_variance_write_off_is_confined_to_its_own_lot(): void
    {
        $admin = $this->admin('admin5@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => '29 Circular', 'catalogue_number' => '12012029']);

        // Lot EARLY expires sooner, so an item-wide write-off would take it first.
        foreach (range(1, 2) as $i) {
            $item->units()->create([
                'serial_number' => "E{$i}", 'lot_number' => 'EARLY',
                'expiry_date' => '2027-01-01', 'location_id' => $boot->id, 'status' => 'available',
            ]);
        }
        foreach (range(1, 2) as $i) {
            $item->units()->create([
                'serial_number' => "L{$i}", 'lot_number' => 'LATE',
                'expiry_date' => '2029-01-01', 'location_id' => $boot->id, 'status' => 'available',
            ]);
        }

        $svc = app(StockCountService::class);
        $count = $svc->create(['location_id' => $boot->id], $admin);

        $early = $count->items()->where('lot_number', 'EARLY')->firstOrFail();
        $late = $count->items()->where('lot_number', 'LATE')->firstOrFail();

        // EARLY counted in full; LATE is one short.
        $svc->submit($count, [
            ['id' => $early->id, 'counted_quantity' => 2],
            ['id' => $late->id, 'counted_quantity' => 1],
        ]);
        $svc->review($count->fresh(), $admin, 'approve');

        $missing = DeviceUnit::where('status', 'missing')->get();
        $this->assertCount(1, $missing);
        $this->assertSame('LATE', $missing->first()->lot_number);

        $this->assertSame(2, DeviceUnit::where('lot_number', 'EARLY')->where('status', 'available')->count());
    }

    /** One expected line per (count, item, lot) is enforced at the database. */
    public function test_duplicate_expected_lines_are_rejected(): void
    {
        if (! in_array(config('database.default'), ['pgsql', 'sqlite'], true)) {
            $this->markTestSkipped('Partial unique index is only created on pgsql/sqlite.');
        }

        $admin = $this->admin('admin6@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => 'Mesh', 'catalogue_number' => 'MESH']);
        $item->units()->create(['serial_number' => 'M1', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'available']);

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);

        $this->expectException(QueryException::class);
        $count->items()->create([
            'stock_item_id' => $item->id, 'ref_code' => 'MESH',
            'lot_number' => 'L1', 'expected_quantity' => 1,
        ]);
    }

    /** Adjustment lines are exempt: the same off-list lot may recur. */
    public function test_adjustment_lines_may_repeat(): void
    {
        $admin = $this->admin('admin7@snapshot.test');
        $boot = Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create(['name' => 'Mesh', 'catalogue_number' => 'MESH']);
        $item->units()->create(['serial_number' => 'M1', 'lot_number' => 'L1', 'location_id' => $boot->id, 'status' => 'available']);

        $count = app(StockCountService::class)->create(['location_id' => $boot->id], $admin);
        $expected = $count->items()->firstOrFail();

        foreach (range(1, 2) as $i) {
            $count->items()->create([
                'stock_item_id'       => $item->id,
                'ref_code'            => 'MESH',
                'lot_number'          => 'ROGUE-LOT',
                'expected_quantity'   => 0,
                'is_adjustment'       => true,
                'adjustment_type'     => 'lot_mismatch',
                'parent_item_id'      => $expected->id,
                'expected_lot_number' => 'L1',
            ]);
        }

        $this->assertCount(2, $count->items()->adjustments()->get());
        $this->assertSame(
            $expected->id,
            $count->items()->adjustments()->first()->parentItem->id,
        );
        $this->assertCount(2, $expected->adjustments);
    }

    /** Lot comparison ignores case, spacing and hyphens; blanks read as no lot. */
    public function test_lot_normalisation(): void
    {
        $this->assertSame('HQ45D250902', StockCountItem::normalizeLot(' hq45-d250902 '));
        $this->assertSame('11129D250603', StockCountItem::normalizeLot('11129D250603'));
        $this->assertSame('ABC123', StockCountItem::normalizeLot("abc\t123"));
        $this->assertNull(StockCountItem::normalizeLot(null));
        $this->assertNull(StockCountItem::normalizeLot('   '));
        $this->assertNull(StockCountItem::normalizeLot('--'));
    }
}
