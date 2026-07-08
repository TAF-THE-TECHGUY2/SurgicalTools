<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Requested account corrections (applies to live data; the seeder matches):
 *  - Josh van Wyk        → Josh E Hall
 *  - Mike Dlamini        → Mike Oliver   ("Mike Boot" = Mike Oliver's boot)
 *  - super@surgical.test → mj@surgical.test
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('email', 'josh@surgical.test')
            ->update(['name' => 'Josh E Hall']);

        DB::table('users')->where('email', 'mike@surgical.test')
            ->update(['name' => 'Mike Oliver']);

        DB::table('users')->where('email', 'super@surgical.test')
            ->update(['email' => 'mj@surgical.test']);
    }

    public function down(): void
    {
        DB::table('users')->where('email', 'mj@surgical.test')
            ->update(['email' => 'super@surgical.test']);
        DB::table('users')->where('email', 'josh@surgical.test')
            ->update(['name' => 'Josh van Wyk']);
        DB::table('users')->where('email', 'mike@surgical.test')
            ->update(['name' => 'Mike Dlamini']);
    }
};
