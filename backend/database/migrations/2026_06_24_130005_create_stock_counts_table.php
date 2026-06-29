<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // SC-2026-000045
            // requested | in_progress | submitted | under_review | approved | investigating
            $table->string('status')->default('requested');

            // Scope of the count.
            $table->string('location')->nullable();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('holder_user_id')->nullable()->constrained('users')->nullOnDelete(); // boot owner

            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete(); // admin
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete(); // rep
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('ref_code');
            $table->string('description')->nullable();
            $table->string('lot_number')->nullable();
            $table->integer('expected_quantity')->default(0);
            $table->integer('counted_quantity')->nullable();
            // Generated when counted_quantity is set: counted - expected.
            $table->integer('variance')->nullable();
            $table->string('photo_path')->nullable(); // optional evidence photo
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
