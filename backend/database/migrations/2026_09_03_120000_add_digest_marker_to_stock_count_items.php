<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks which adjustment lines have already gone out by email.
 *
 * The first discrepancy on a count mails immediately; the rest are coalesced
 * into a digest. Tracking that per line — rather than as a timestamp on the
 * count — means a line raised while a digest job is already queued cannot be
 * skipped or sent twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->timestamp('digest_notified_at')->nullable()->after('last_scanned_at');
            $table->index(['stock_count_id', 'digest_notified_at']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropIndex(['stock_count_id', 'digest_notified_at']);
            $table->dropColumn('digest_notified_at');
        });
    }
};
