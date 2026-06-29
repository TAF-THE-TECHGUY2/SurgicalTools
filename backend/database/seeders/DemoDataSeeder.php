<?php

namespace Database\Seeders;

use App\Enums\StockLocation;
use App\Enums\StockStatus;
use App\Enums\StockType;
use App\Enums\UserRole;
use App\Models\Doctor;
use App\Models\Hospital;
use App\Models\InventoryItem;
use App\Models\PreferenceCard;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoDataSeeder extends Seeder
{
    public function run(): void
    {
        // -- Users -----------------------------------------------------------
        $super = $this->user('Sarah Naidoo', 'super@surgical.test', UserRole::SuperAdmin, 'office');
        $admin = $this->user('David Botha', 'admin@surgical.test', UserRole::Admin, 'office');
        $mike  = $this->user('Mike Dlamini', 'mike@surgical.test', UserRole::GeneralUser, 'rep');
        $lerato = $this->user('Lerato Khumalo', 'lerato@surgical.test', UserRole::GeneralUser, 'rep');
        $runner = $this->user('Peter Smith', 'runner@surgical.test', UserRole::GeneralUser, 'runner');

        // -- Hospitals -------------------------------------------------------
        $arwyp = Hospital::create([
            'name' => 'Arwyp Medical Centre', 'code' => 'ARWYP', 'category' => 'private',
            'region' => 'Gauteng', 'city' => 'Kempton Park', 'province' => 'Gauteng',
            'address' => '20 Central Ave, Kempton Park', 'phone' => '011 922 1000',
            'assigned_rep_id' => $mike->id, 'assigned_runner_id' => $runner->id,
        ]);
        $milpark = Hospital::create([
            'name' => 'Netcare Milpark Hospital', 'code' => 'MILPARK', 'category' => 'netcare',
            'region' => 'Gauteng', 'city' => 'Johannesburg', 'province' => 'Gauteng',
            'address' => '9 Guild Rd, Parktown West', 'phone' => '011 480 5600',
            'assigned_rep_id' => $lerato->id,
        ]);
        $stAugustine = Hospital::create([
            'name' => 'Life St Augustine\'s Hospital', 'code' => 'STAUG', 'category' => 'life',
            'region' => 'KZN', 'city' => 'Durban', 'province' => 'KwaZulu-Natal',
            'address' => '107 JB Marks Rd, Glenwood', 'phone' => '031 268 5000',
            'assigned_rep_id' => $lerato->id,
        ]);

        // Link reps/runners to login accounts (the assignment that scopes approvals).
        $arwyp->users()->attach($mike->id, ['role' => 'rep']);
        $arwyp->users()->attach($runner->id, ['role' => 'runner']);
        $milpark->users()->attach($lerato->id, ['role' => 'rep']);
        $stAugustine->users()->attach($lerato->id, ['role' => 'rep']);

        // Key contacts
        $arwyp->contacts()->createMany([
            ['name' => 'Nomsa Zulu', 'role' => 'Stock Controller', 'email' => 'stock@arwyp.test', 'phone' => '011 922 1010', 'is_primary' => true],
            ['name' => 'Dr Theatre Manager', 'role' => 'Theatre Manager', 'email' => 'theatre@arwyp.test'],
        ]);
        $milpark->contacts()->create([
            'name' => 'James Pillay', 'role' => 'Stock Controller', 'email' => 'stock@milpark.test', 'is_primary' => true,
        ]);

        // -- Doctors ---------------------------------------------------------
        $drJones = Doctor::create([
            'name' => 'Dr A. Jones', 'age' => 52, 'specialty' => 'general_surgeon',
            'operating_days' => ['monday', 'wednesday', 'friday'],
            'equipment_used' => ['Laparoscopic Stapler', 'Trocar 12mm'],
            'procedure_preferences' => 'Prefers 12mm trocars, blue cartridges for bowel.',
            'phone' => '082 111 2222', 'email' => 'a.jones@doctors.test',
        ]);
        $drPatel = Doctor::create([
            'name' => 'Dr S. Patel', 'age' => 45, 'specialty' => 'gynaecologist',
            'operating_days' => ['tuesday', 'thursday'],
            'equipment_used' => ['Hysteroscope', 'Bipolar Forceps'],
            'phone' => '083 333 4444', 'email' => 's.patel@doctors.test',
        ]);

        $drJones->hospitals()->attach([$arwyp->id, $milpark->id]);
        $drPatel->hospitals()->attach([$stAugustine->id, $milpark->id]);

        // -- Preference card -------------------------------------------------
        $card = PreferenceCard::create([
            'doctor_id' => $drJones->id,
            'procedure_name' => 'Laparoscopic Cholecystectomy',
            'notes' => 'Patient positioned reverse Trendelenburg. Confirm CO2 supply.',
            'preferred_sizes' => ['trocar' => '12mm', 'stapler' => '60mm'],
        ]);
        $card->items()->createMany([
            ['ref_code' => 'STP-60', 'description' => 'Endo Stapler 60mm', 'preferred_size' => '60mm', 'quantity' => 1],
            ['ref_code' => 'TRO-12', 'description' => 'Trocar 12mm', 'preferred_size' => '12mm', 'quantity' => 2],
            ['ref_code' => 'CLP-ML', 'description' => 'Polymer Clips Medium/Large', 'quantity' => 1],
        ]);

        // -- Inventory -------------------------------------------------------
        $catalog = [
            ['STP-60', 'Endo Stapler 60mm', 4500.00],
            ['TRO-12', 'Trocar 12mm', 850.00],
            ['CLP-ML', 'Polymer Clips Medium/Large', 1200.00],
            ['MESH-15', 'Hernia Mesh 15x15cm', 3200.00],
            ['SUT-30', 'Absorbable Suture 3-0', 95.00],
            ['HYS-SC', 'Hysteroscope Sheath', 7800.00],
        ];

        foreach ($catalog as $i => [$ref, $desc, $price]) {
            // Warehouse master stock
            InventoryItem::create([
                'ref_code' => $ref, 'description' => $desc,
                'lot_number' => 'LOT-'.(1000 + $i),
                'quantity' => 40 + $i * 5,
                'expiry_date' => now()->addMonths(8 + $i),
                'stock_type' => StockType::Warehouse->value,
                'location' => StockLocation::JhbMasterWarehouse->value,
                'status' => StockStatus::Available->value,
                'min_threshold' => 10, 'unit_price' => $price, 'uom' => 'each',
            ]);
        }

        // Some stock already in Mike's boot
        InventoryItem::create([
            'ref_code' => 'TRO-12', 'description' => 'Trocar 12mm', 'lot_number' => 'LOT-1001',
            'quantity' => 6, 'expiry_date' => now()->addMonths(9),
            'stock_type' => StockType::Boot->value, 'location' => StockLocation::BootStock->value,
            'status' => StockStatus::Available->value, 'holder_user_id' => $mike->id,
            'unit_price' => 850.00, 'uom' => 'each',
        ]);

        // Near-expiry + low-stock examples to trigger alerts
        InventoryItem::create([
            'ref_code' => 'SUT-30', 'description' => 'Absorbable Suture 3-0', 'lot_number' => 'LOT-EXP',
            'quantity' => 3, 'expiry_date' => now()->addDays(25),
            'stock_type' => StockType::Warehouse->value, 'location' => StockLocation::JhbMasterWarehouse->value,
            'status' => StockStatus::Available->value, 'min_threshold' => 10, 'unit_price' => 95.00,
        ]);
        InventoryItem::create([
            'ref_code' => 'CLP-ML', 'description' => 'Polymer Clips Medium/Large', 'lot_number' => 'LOT-CON',
            'quantity' => 8, 'expiry_date' => now()->addMonths(14),
            'stock_type' => StockType::Consignment->value, 'location' => StockLocation::HospitalStock->value,
            'status' => StockStatus::Available->value, 'hospital_id' => $arwyp->id, 'unit_price' => 1200.00,
        ]);

        $this->command?->info('Demo data seeded. Logins (password: "password"):');
        $this->command?->table(['Role', 'Email'], [
            ['Super Admin', 'super@surgical.test'],
            ['Admin', 'admin@surgical.test'],
            ['General (rep, Arwyp)', 'mike@surgical.test'],
            ['General (rep, Milpark/St Aug)', 'lerato@surgical.test'],
            ['General (runner, Arwyp)', 'runner@surgical.test'],
        ]);
    }

    protected function user(string $name, string $email, UserRole $role, string $staffType): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
                'staff_type' => $staffType,
                'is_active' => true,
            ],
        );
        $user->syncRoles([$role->value]);

        return $user;
    }
}
