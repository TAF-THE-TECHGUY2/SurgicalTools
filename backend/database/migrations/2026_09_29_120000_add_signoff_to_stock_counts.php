<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finishing a count the way the paper Inventory Count Listing is finished.
 *
 * Every expected line must be resolved before the count can close: scanned,
 * keyed, or minus-confirmed as "none found" (rule 6). The stock controller then
 * signs on the screen and the count locks — the signature, the printed name,
 * the moment and the device are kept with it so the signed sheet can be
 * rebuilt exactly.
 *
 * Lines also snapshot the columns the paper sheet carries (item code, supplier
 * group, product group, list/unit price) so a later catalogue edit cannot
 * change what a signed sheet said.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_counts', function (Blueprint $table) {
            $table->foreignId('signed_by')->nullable()->after('reviewed_at')
                ->constrained('users')->nullOnDelete();
            $table->string('signed_by_name')->nullable()->after('signed_by');
            $table->string('signature_path')->nullable()->after('signed_by_name');
            $table->timestamp('signed_at')->nullable()->after('signature_path');
            $table->string('signed_device')->nullable()->after('signed_at');
        });

        // SQLite rebuilds the table to add foreign keys and does not carry a
        // partial expression index across intact — it comes back as a plain
        // unique (count, item) that forbids a second lot. Drop it around the
        // change and recreate it exactly.
        $this->dropExpectedLineIndex();

        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->string('item_code')->nullable()->after('ref_code');
            $table->string('supplier')->nullable()->after('item_code');
            $table->string('product_group')->nullable()->after('supplier');
            $table->decimal('unit_price', 12, 2)->nullable()->after('expiry_date');

            // Rule 6: the counter confirmed none of this line were found.
            $table->timestamp('not_found_at')->nullable();
            $table->foreignId('not_found_by')->nullable()->constrained('users')->nullOnDelete();

            // Lot adjustment report: the reviewer moved stock from the old lot
            // onto the lot actually found.
            $table->integer('lot_adjusted_quantity')->default(0);
            $table->timestamp('lot_adjusted_at')->nullable();
            $table->foreignId('lot_adjusted_by')->nullable()->constrained('users')->nullOnDelete();
        });

        $this->createExpectedLineIndex();

        Schema::table('stock_items', function (Blueprint $table) {
            // The paper sheet groups by supplier (DANNIK, FENGHM…) and shows a
            // product group (LAP…) beside the warehouse.
            $table->string('supplier')->nullable()->after('item_code');
            $table->string('product_group')->nullable()->after('supplier');
            $table->index('supplier');
        });
    }

    /** Mirrors 2026_09_03_100000_add_lot_and_adjustment_to_stock_count_items. */
    protected function createExpectedLineIndex(): void
    {
        $name = 'stock_count_items_expected_line_unique';

        match (DB::connection()->getDriverName()) {
            'pgsql' => DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON stock_count_items "
                ."(stock_count_id, stock_item_id, COALESCE(lot_number, '')) "
                .'WHERE is_adjustment = false'
            ),
            'sqlite' => DB::statement(
                "CREATE UNIQUE INDEX IF NOT EXISTS {$name} ON stock_count_items "
                ."(stock_count_id, stock_item_id, COALESCE(lot_number, '')) "
                .'WHERE is_adjustment = 0'
            ),
            default => null,
        };
    }

    protected function dropExpectedLineIndex(): void
    {
        if (in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true)) {
            DB::statement('DROP INDEX IF EXISTS stock_count_items_expected_line_unique');
        }
    }

    public function down(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['supplier']);
            $table->dropColumn(['supplier', 'product_group']);
        });

        $this->dropExpectedLineIndex();

        Schema::table('stock_count_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lot_adjusted_by');
            $table->dropConstrainedForeignId('not_found_by');
            $table->dropColumn([
                'item_code', 'supplier', 'product_group', 'unit_price',
                'not_found_at', 'lot_adjusted_quantity', 'lot_adjusted_at',
            ]);
        });

        $this->createExpectedLineIndex();

        Schema::table('stock_counts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signed_by');
            $table->dropColumn(['signed_by_name', 'signature_path', 'signed_at', 'signed_device']);
        });
    }
};
