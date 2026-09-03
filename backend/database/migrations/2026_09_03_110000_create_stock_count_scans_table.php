<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The scan evidence trail. Every capture is kept — barcode string or OCR text,
 * what was extracted from it, how confident the extraction was and what the
 * match produced — so a disputed count can be reconstructed from the raw
 * reads rather than from the line totals alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        // GS1 barcodes carry a GTIN, not the Surgical Devices REF. Without a
        // mapping column a scanned barcode cannot be resolved to a catalogue
        // entry; it is learned the first time a runner confirms a scan.
        Schema::table('stock_items', function (Blueprint $table) {
            $table->string('gtin')->nullable()->after('item_code');
            $table->index('gtin');
        });

        Schema::create('stock_count_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            // The line this scan landed on. Null while the scan is unresolved.
            $table->foreignId('stock_count_item_id')->nullable()
                ->constrained('stock_count_items')->nullOnDelete();
            $table->foreignId('stock_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('image_path')->nullable();
            $table->string('source'); // barcode | vision | manual
            $table->text('raw_payload')->nullable(); // raw GS1 string or OCR text
            $table->json('extracted')->nullable(); // {ref, gtin, lot_number, expiry_date, serial_number}
            $table->decimal('confidence', 3, 2)->nullable();
            // match | lot_mismatch | unlisted_item | expiry_mismatch | unresolved
            $table->string('match_result');
            $table->boolean('confirmed')->default(false);

            $table->foreignId('scanned_by')->nullable()->constrained('users')->nullOnDelete();
            // Client-generated, for offline replay idempotency.
            $table->string('client_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['stock_count_id', 'match_result']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_scans');

        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropIndex(['gtin']);
            $table->dropColumn('gtin');
        });
    }
};
