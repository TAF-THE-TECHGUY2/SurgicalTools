<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks a destination created in the field.
 *
 * A rep delivering to a clinic that isn't on the hospitals master still has to
 * be able to raise the voucher. The destination is created from what they
 * type, but the system genuinely does not know its details — no linked
 * hospital record, so no address on file, no assigned rep, and no
 * hospital-scoped approval rule. This flag lets an admin find those afterwards
 * and either link them to a proper Hospital or merge them away.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->boolean('is_ad_hoc')->default(false)->after('is_active');
            $table->index('is_ad_hoc');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropIndex(['is_ad_hoc']);
            $table->dropColumn('is_ad_hoc');
        });
    }
};
