<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes stock-count lines lot-aware.
 *
 * The expected snapshot used to group device units by stock_item_id alone, so
 * the `lot_number` column existed but was never populated — leaving nothing to
 * compare a scanned lot against. Counts are now snapshotted per
 * (stock_item_id, lot_number), and a mismatch INSERTs an adjustment line that
 * points back at the expected line via `parent_item_id` rather than
 * overwriting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_items', function (Blueprint $table) {
            // Expiry snapshotted alongside the lot so an expiry mismatch is detectable.
            $table->date('expiry_date')->nullable()->after('lot_number');

            // Running tally from the scan loop, folded into counted_quantity on submit.
            $table->integer('scanned_quantity')->default(0)->after('expected_quantity');

            $table->boolean('is_adjustment')->default(false)->after('variance');
            $table->string('adjustment_type')->nullable()->after('is_adjustment');

            // The expected line this adjustment was raised against (spec §6: the
            // "specific primary product ID key"). Null for an unlisted item.
            $table->foreignId('parent_item_id')->nullable()->after('adjustment_type')
                ->constrained('stock_count_items')->nullOnDelete();

            // What the site was expecting, kept next to the lot actually found.
            $table->string('expected_lot_number')->nullable()->after('parent_item_id');

            $table->timestamp('first_scanned_at')->nullable();
            $table->timestamp('last_scanned_at')->nullable();

            $table->index('lot_number');
            $table->index(['stock_count_id', 'is_adjustment']);
        });

        $this->createExpectedLineUniqueIndex();
    }

    /**
     * One expected line per (count, item, lot). Adjustment lines are excluded —
     * the same off-list lot may legitimately be raised more than once — so this
     * is a partial index, which rules out MySQL. Postgres (production) and
     * SQLite (dev/tests) both support it; any other driver is left without the
     * constraint rather than silently getting a wrong one.
     *
     * lot_number is nullable and SQL treats NULLs as distinct in a unique
     * index, which would allow duplicate no-lot lines — COALESCE collapses
     * them to a single comparable value.
     */
    protected function createExpectedLineUniqueIndex(): void
    {
        $name = 'stock_count_items_expected_line_unique';

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement(
                "CREATE UNIQUE INDEX {$name} ON stock_count_items "
                ."(stock_count_id, stock_item_id, COALESCE(lot_number, '')) "
                .'WHERE is_adjustment = false'
            ),
            'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX {$name} ON stock_count_items "
                ."(stock_count_id, stock_item_id, COALESCE(lot_number, '')) "
                .'WHERE is_adjustment = 0'
            ),
            default => null,
        };
    }

    public function down(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS stock_count_items_expected_line_unique');
        }

        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropIndex(['stock_count_id', 'is_adjustment']);
            $table->dropIndex(['lot_number']);
            $table->dropConstrainedForeignId('parent_item_id');
            $table->dropColumn([
                'expiry_date', 'scanned_quantity', 'is_adjustment', 'adjustment_type',
                'expected_lot_number', 'first_scanned_at', 'last_scanned_at',
            ]);
        });
    }
};
