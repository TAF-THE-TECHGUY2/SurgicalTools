<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\StockItem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Testing\File;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Phase 6: the stateless label read.
 *
 * A delivery voucher is built before the transfer exists, so there is nothing
 * to scan *into* — this endpoint reads a label and hands back what it says,
 * keeping the GS1 parser on the server rather than duplicating it in the
 * browser.
 */
class ScanExtractEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected User $rep;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->rep = User::create([
            'name' => 'Rep', 'email' => 'rep@extract.test',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);
        $this->rep->assignRole(UserRole::GeneralUser->value);
    }

    public function test_parses_a_barcode_and_resolves_the_catalogue_item(): void
    {
        $item = StockItem::create(['name' => '29 Circular', 'catalogue_number' => '12012029']);

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', [
                'barcode' => '(240)12012029(10)11129D250603(17)270603(21)SN7',
            ])
            ->assertOk()
            ->assertJsonPath('extracted.ref', '12012029')
            ->assertJsonPath('extracted.lot_number', '11129D250603')
            ->assertJsonPath('extracted.expiry_date', '2027-06-03')
            ->assertJsonPath('extracted.serial_number', 'SN7')
            ->assertJsonPath('stock_item.id', $item->id)
            ->assertJsonPath('needs_review', false);
    }

    /** A GTIN resolves ahead of the printed reference. */
    public function test_resolves_on_gtin(): void
    {
        $item = StockItem::create([
            'name' => '29 Circular', 'catalogue_number' => '12012029', 'gtin' => '03456789012345',
        ]);

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', ['barcode' => '(01)03456789012345(10)L1'])
            ->assertOk()
            ->assertJsonPath('stock_item.id', $item->id);
    }

    /** An unknown code returns the reading, with no item and a review flag. */
    public function test_unresolved_code_still_returns_the_reading(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', ['barcode' => '(240)NOT-A-REF(10)L1'])
            ->assertOk()
            ->assertJsonPath('extracted.ref', 'NOT-A-REF')
            ->assertJsonPath('stock_item', null)
            ->assertJsonPath('needs_review', true);
    }

    public function test_requires_a_barcode_or_a_photo(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    public function test_rejects_an_unparseable_barcode(): void
    {
        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', ['barcode' => 'rubbish'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('barcode');
    }

    /** The photo path reports clearly when OCR is not configured. */
    public function test_photo_without_a_key_is_a_clear_error(): void
    {
        config(['surgical.ocr.api_key' => null]);

        $this->actingAs($this->rep, 'sanctum')
            ->postJson('/api/scan/extract', ['photo' => File::image('label.jpg')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('photo');
    }

    /** Reading labels needs either scanning or transfer-creation authority. */
    public function test_requires_scan_or_transfer_create_permission(): void
    {
        $outsider = User::create([
            'name' => 'Outsider', 'email' => 'out@extract.test',
            'password' => Hash::make('password'), 'is_active' => true,
        ]);

        $this->actingAs($outsider, 'sanctum')
            ->postJson('/api/scan/extract', ['barcode' => '(10)L1'])
            ->assertForbidden();
    }

    /** The grouped inventory payload carries the GTIN the browser matches on. */
    public function test_location_inventory_exposes_gtin(): void
    {
        $location = \App\Models\Location::create(['name' => 'Boot', 'type' => 'boot']);
        $item = StockItem::create([
            'name' => '29 Circular', 'catalogue_number' => '12012029', 'gtin' => '03456789012345',
        ]);
        $item->units()->create([
            'serial_number' => 'A1', 'lot_number' => 'L1',
            'location_id' => $location->id, 'status' => 'available',
        ]);

        $this->actingAs($this->rep, 'sanctum')
            ->getJson("/api/locations/{$location->id}/inventory")
            ->assertOk()
            ->assertJsonPath('items.0.gtin', '03456789012345');
    }

    /** The destination payload carries the contacts the voucher header uses. */
    public function test_location_show_exposes_hospital_contacts(): void
    {
        $hospital = \App\Models\Hospital::create([
            'name' => 'Arwyp', 'category' => 'private', 'address' => '20 Pine Avenue',
        ]);
        \App\Models\HospitalContact::create([
            'hospital_id' => $hospital->id, 'name' => 'Sister Dlamini',
            'role' => 'Stock Controller', 'is_primary' => true,
        ]);
        $location = \App\Models\Location::create([
            'name' => 'Arwyp', 'type' => 'hospital', 'hospital_id' => $hospital->id,
        ]);

        $this->actingAs($this->rep, 'sanctum')
            ->getJson("/api/locations/{$location->id}")
            ->assertOk()
            ->assertJsonPath('data.hospital.address', '20 Pine Avenue')
            ->assertJsonPath('data.hospital.contacts.0.name', 'Sister Dlamini');
    }
}
