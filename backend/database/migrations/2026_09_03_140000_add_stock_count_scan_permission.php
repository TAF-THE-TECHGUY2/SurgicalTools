<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grants the new `stock_count.scan` ability on an existing database.
 *
 * Re-running RolePermissionSeeder would also do this, but it calls
 * syncPermissions() — which replaces a role's whole permission set and would
 * silently discard any customisation an admin made in the Users screen. This
 * migration is additive: it creates the permission and attaches it to the two
 * roles that should hold it, touching nothing else.
 */
return new class extends Migration
{
    protected const PERMISSION = 'stock_count.scan';

    /** Roles that get scanning by default; mirrors RolePermissionSeeder. */
    protected const ROLES = ['general_user', 'admin', 'super_admin'];

    public function up(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            $permissionId = DB::table('permissions')->insertGetId([
                'name'       => self::PERMISSION,
                'guard_name' => 'web',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $roleIds = DB::table('roles')
            ->whereIn('name', self::ROLES)
            ->where('guard_name', 'web')
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $alreadyGranted = DB::table('role_has_permissions')
                ->where('permission_id', $permissionId)
                ->where('role_id', $roleId)
                ->exists();

            if (! $alreadyGranted) {
                DB::table('role_has_permissions')->insert([
                    'permission_id' => $permissionId,
                    'role_id'       => $roleId,
                ]);
            }
        }

        // Spatie caches the permission map; a stale cache would deny the new
        // ability until the next cache clear.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->value('id');

        if (! $permissionId) {
            return;
        }

        DB::table('role_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $permissionId)->delete();
        DB::table('permissions')->where('id', $permissionId)->delete();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
