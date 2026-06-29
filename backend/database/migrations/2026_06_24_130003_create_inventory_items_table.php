<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // An inventory_item is a *holding*: a specific ref + lot at a specific
        // location (warehouse / a rep's boot / a hospital) with a quantity.
        // Moving stock decrements one holding and increments/creates another,
        // while stock_movements records the immutable ledger of every move.
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->string('ref_code');
            $table->string('description');
            $table->string('lot_number')->nullable();
            $table->integer('quantity')->default(0);
            $table->date('expiry_date')->nullable();

            $table->string('stock_type'); // consignment | bought | boot | loan | warehouse
            $table->string('location');   // ordered | supplier | in_transit | jhb_master_warehouse | ...
            $table->string('status')->default('available'); // available | reserved | ...

            // Where / with whom the holding currently sits.
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete(); // boot owner

            $table->integer('min_threshold')->nullable(); // low-stock alert level
            $table->decimal('unit_price', 12, 2)->nullable(); // for Pastel export
            $table->string('barcode')->nullable();
            $table->string('uom')->nullable(); // unit of measure
            $table->json('meta')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('ref_code');
            $table->index('lot_number');
            $table->index('status');
            $table->index('location');
            $table->index('stock_type');
            $table->index('expiry_date');
        });

        // Immutable movement ledger — every stock move is logged here.
        // Supplier → JHB Warehouse → Mike Boot → Arwyp Hospital
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of the moved stock (survives even if the holding is deleted).
            $table->string('ref_code');
            $table->string('lot_number')->nullable();
            $table->integer('quantity');

            $table->string('movement_type'); // transfer | adjustment | receipt | count_correction
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->foreignId('from_holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('from_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();
            $table->foreignId('to_hospital_id')->nullable()->constrained('hospitals')->nullOnDelete();

            // Polymorphic link to the source document (transfer, stock_count...).
            $table->nullableMorphs('reference');

            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('moved_at')->nullable();
            $table->timestamps();

            $table->index('ref_code');
            $table->index('movement_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('inventory_items');
    }
};
