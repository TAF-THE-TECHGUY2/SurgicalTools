<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Hospital;
use App\Models\Location;
use App\Models\PreferenceCard;
use App\Models\StockItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds the demo world from the functional spec:
 *   Locations: Zamokuhle Hospital · Mike Boot · Josh Boot · JHB Office · Netcare Montana
 *   Users: super, admin, Mike (Mike Boot), Josh (Josh Boot)
 *   Stock: Trochar / Guide Wire / Mesh device units with serials, lots, expiries.
 * Idempotent — safe to re-run.
 */
class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // -- Users -----------------------------------------------------------
        $super = $this->user('Sarah Naidoo', 'super@surgical.test', UserRole::SuperAdmin, 'office');
        $admin = $this->user('David Botha', 'admin@surgical.test', UserRole::Admin, 'office');
        $mike  = $this->user('Mike Dlamini', 'mike@surgical.test', UserRole::GeneralUser, 'rep');
        $josh  = $this->user('Josh van Wyk', 'josh@surgical.test', UserRole::GeneralUser, 'rep');

        // -- Hospitals (master data behind the hospital locations) -----------
        $zamokuhle = Hospital::firstOrCreate(
            ['name' => 'Zamokuhle Hospital'],
            ['code' => 'ZAMO', 'category' => 'private', 'region' => 'Gauteng',
                'city' => 'Tembisa', 'province' => 'Gauteng',
                'address' => 'Hospital Rd, Tembisa', 'assigned_rep_id' => $mike->id],
        );
        $montana = Hospital::firstOrCreate(
            ['name' => 'Netcare Montana'],
            ['code' => 'MONT', 'category' => 'netcare', 'region' => 'Gauteng',
                'city' => 'Pretoria', 'province' => 'Gauteng',
                'address' => 'Dr Swanepoel Rd, Montana Park', 'assigned_rep_id' => $josh->id],
        );

        $zamokuhle->users()->syncWithoutDetaching([$mike->id => ['role' => 'rep']]);
        $montana->users()->syncWithoutDetaching([$josh->id => ['role' => 'rep']]);

        $zamokuhle->contacts()->firstOrCreate(
            ['name' => 'Nomsa Zulu'],
            ['role' => 'Stock Controller', 'email' => 'stock@zamokuhle.test', 'is_primary' => true],
        );
        $montana->contacts()->firstOrCreate(
            ['name' => 'James Pillay'],
            ['role' => 'Stock Controller', 'email' => 'stock@montana.test', 'is_primary' => true],
        );

        // -- The five transfer entities (locations) ---------------------------
        $locations = [
            'Zamokuhle Hospital' => Location::firstOrCreate(
                ['name' => 'Zamokuhle Hospital'],
                ['type' => 'hospital', 'hospital_id' => $zamokuhle->id],
            ),
            'Mike Boot' => Location::firstOrCreate(
                ['name' => 'Mike Boot'],
                ['type' => 'boot', 'owner_user_id' => $mike->id],
            ),
            'Josh Boot' => Location::firstOrCreate(
                ['name' => 'Josh Boot'],
                ['type' => 'boot', 'owner_user_id' => $josh->id],
            ),
            'JHB Office' => Location::firstOrCreate(
                ['name' => 'JHB Office'],
                ['type' => 'office'],
            ),
            'Netcare Montana' => Location::firstOrCreate(
                ['name' => 'Netcare Montana'],
                ['type' => 'hospital', 'hospital_id' => $montana->id],
            ),
        ];

        // Link each user's login to their "My Inventory" location.
        $mike->update(['location_id' => $locations['Mike Boot']->id]);
        $josh->update(['location_id' => $locations['Josh Boot']->id]);
        $admin->update(['location_id' => $locations['JHB Office']->id]);
        $super->update(['location_id' => $locations['JHB Office']->id]);

        // -- Stock catalog -----------------------------------------------------
        $trochar = StockItem::firstOrCreate(
            ['catalogue_number' => 'TRO-12'],
            ['name' => 'Trochar', 'item_code' => 'TR', 'uom' => 'each', 'unit_price' => 850, 'min_threshold' => 5],
        );
        $guideWire = StockItem::firstOrCreate(
            ['catalogue_number' => 'GW-035'],
            ['name' => 'Guide Wire', 'item_code' => 'GW', 'uom' => 'each', 'unit_price' => 320, 'min_threshold' => 5],
        );
        $mesh = StockItem::firstOrCreate(
            ['catalogue_number' => 'MESH-15'],
            ['name' => 'Mesh', 'item_code' => 'ME', 'uom' => 'each', 'unit_price' => 3200, 'min_threshold' => 4],
        );

        // -- Device units (serial / lot / expiry per the spec example) --------
        // Josh Boot — the spec's worked example.
        $this->unit($trochar, $locations['Josh Boot'], 'TR001', 'LOT123', '2027-12-01');
        $this->unit($trochar, $locations['Josh Boot'], 'TR002', 'LOT124', '2028-02-01');
        $this->unit($trochar, $locations['Josh Boot'], 'TR003', 'LOT130', '2027-07-01');
        foreach (range(1, 4) as $i) {
            $this->unit($guideWire, $locations['Josh Boot'], sprintf('GWJ%03d', $i), 'LOT210', '2028-05-01');
        }

        // Mike Boot.
        $this->unit($trochar, $locations['Mike Boot'], 'TR010', 'LOT131', '2027-09-01');
        $this->unit($trochar, $locations['Mike Boot'], 'TR011', 'LOT131', '2027-09-01');
        foreach (range(1, 3) as $i) {
            $this->unit($mesh, $locations['Mike Boot'], sprintf('MEM%03d', $i), 'LOT501', '2028-01-01');
        }

        // JHB Office — main store.
        foreach (range(20, 29) as $i) {
            $this->unit($trochar, $locations['JHB Office'], "TR0{$i}", 'LOT140', '2028-06-01');
        }
        foreach (range(1, 5) as $i) {
            $this->unit($guideWire, $locations['JHB Office'], sprintf('GWO%03d', $i), 'LOT211', '2028-08-01');
        }
        foreach (range(1, 12) as $i) {
            $this->unit($mesh, $locations['JHB Office'], sprintf('MEO%03d', $i), 'LOT502', '2028-03-01');
        }

        // A couple of near-expiry units to exercise the alerts.
        $this->unit($guideWire, $locations['JHB Office'], 'GWEXP1', 'LOT-EXP', now()->addDays(25)->toDateString());
        $this->unit($guideWire, $locations['JHB Office'], 'GWEXP2', 'LOT-EXP', now()->addDays(25)->toDateString());

        // Hospital consignment examples.
        $this->unit($mesh, $locations['Zamokuhle Hospital'], 'MEZ001', 'LOT503', '2028-04-01');
        $this->unit($mesh, $locations['Netcare Montana'], 'MEN001', 'LOT503', '2028-04-01');

        // -- Doctors + preference card (unchanged modules) ---------------------
        $drJones = Doctor::firstOrCreate(
            ['name' => 'Dr A. Jones'],
            ['age' => 52, 'specialty' => 'general_surgeon',
                'operating_days' => ['monday', 'wednesday', 'friday'],
                'equipment_used' => ['Trochar 12mm', 'Mesh 15x15'],
                'procedure_preferences' => 'Prefers 12mm trochars.'],
        );
        $drJones->hospitals()->syncWithoutDetaching([$zamokuhle->id, $montana->id]);

        $card = PreferenceCard::firstOrCreate(
            ['doctor_id' => $drJones->id, 'procedure_name' => 'Laparoscopic Hernia Repair'],
            ['notes' => 'Reverse Trendelenburg. Confirm CO2 supply.'],
        );
        if ($card->items()->count() === 0) {
            $card->items()->createMany([
                ['ref_code' => 'TRO-12', 'description' => 'Trochar', 'preferred_size' => '12mm', 'quantity' => 2],
                ['ref_code' => 'MESH-15', 'description' => 'Mesh', 'preferred_size' => '15x15cm', 'quantity' => 1],
            ]);
        }

        $this->command?->info('Demo world seeded. Logins (password: "password"):');
        $this->command?->table(['Role', 'Email', 'My Inventory'], [
            ['Super Admin', 'super@surgical.test', 'JHB Office (sees all)'],
            ['Admin', 'admin@surgical.test', 'JHB Office'],
            ['General (rep)', 'mike@surgical.test', 'Mike Boot'],
            ['General (rep)', 'josh@surgical.test', 'Josh Boot'],
        ]);
    }

    protected function user(string $name, string $email, UserRole $role, string $staffType): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make('password'), 'staff_type' => $staffType, 'is_active' => true],
        );
        $user->syncRoles([$role->value]);

        return $user;
    }

    protected function unit(StockItem $item, Location $location, string $serial, string $lot, string $expiry): void
    {
        $item->units()->firstOrCreate(
            ['serial_number' => $serial],
            ['lot_number' => $lot, 'expiry_date' => $expiry, 'location_id' => $location->id, 'status' => 'available'],
        );
    }
}
