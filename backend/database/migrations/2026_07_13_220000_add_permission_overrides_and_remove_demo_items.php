<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('permission_overrides')->nullable()->after('is_active');
        });

        // These were demo catalogue records and were explicitly requested to
        // be removed from normal use. Soft deletion keeps every record
        // recoverable and leaves transfer/audit relationships untouched.
        $itemIds = DB::table('stock_items')
            ->whereIn(DB::raw('LOWER(name)'), ['trochar', 'trochars', 'mesh'])
            ->pluck('id');
        $now = now();

        DB::table('device_units')->whereIn('stock_item_id', $itemIds)->update(['deleted_at' => $now]);
        DB::table('stock_items')->whereIn('id', $itemIds)->update([
            'is_active' => false,
            'deleted_at' => $now,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('permission_overrides');
        });

        // Clean up the short-lived permanent-delete permission if this
        // migration was applied during development before soft-delete won.
        DB::table('permissions')->where('name', 'inventory.delete')->where('guard_name', 'web')->delete();
    }
};
