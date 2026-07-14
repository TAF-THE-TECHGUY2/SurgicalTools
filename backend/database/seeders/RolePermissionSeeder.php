<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\App;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /** Every permission in the system, grouped for readability. */
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (User::SYSTEM_PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }

        // General User — front-line rep/runner.
        $general = Role::firstOrCreate(['name' => UserRole::GeneralUser->value, 'guard_name' => 'web']);
        $general->syncPermissions([
            'inventory.view',
            'transfer.view', 'transfer.create', 'transfer.approve',
            'stock_count.capture',
            'hospital.view', 'doctor.view',
        ]);

        // Admin — operational manager.
        $admin = Role::firstOrCreate(['name' => UserRole::Admin->value, 'guard_name' => 'web']);
        $admin->syncPermissions([
            'inventory.view', 'inventory.manage',
            'transfer.view', 'transfer.create', 'transfer.approve', 'transfer.override', 'transfer.review',
            'stock_count.capture', 'stock_count.review',
            'hospital.view', 'hospital.manage',
            'doctor.view', 'doctor.manage',
            'report.view', 'pastel.export', 'audit.view',
        ]);

        // Super Admin — everything (Gate::before also short-circuits, but we
        // grant the full set so token abilities and UI reflect it).
        $super = Role::firstOrCreate(['name' => UserRole::SuperAdmin->value, 'guard_name' => 'web']);
        $super->syncPermissions(Permission::all());

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
