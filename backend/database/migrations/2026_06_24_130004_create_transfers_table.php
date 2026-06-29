<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // e.g. TR1-2026-000123

            // source_to_boot (Transfer 1) | boot_to_hospital (Transfer 2)
            $table->string('type');
            // draft | pending_approval | approved | awaiting_signature | signed
            // | awaiting_admin_review | completed | rejected
            $table->string('status')->default('draft');

            // Routing of the stock.
            $table->string('from_location')->nullable();
            $table->string('to_location')->nullable();
            $table->foreignId('from_holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_holder_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('hospital_id')->nullable()->constrained()->nullOnDelete(); // Transfer 2 target
            $table->foreignId('doctor_id')->nullable()->constrained()->nullOnDelete();    // optional case link

            // Transfer 2: stock type assigned at the hospital.
            $table->string('hospital_stock_type')->nullable(); // consignment | bought | loan

            // People & workflow.
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->boolean('admin_override')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete(); // admin final review
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('status');
        });

        Schema::create('transfer_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_item_id')->nullable()->constrained()->nullOnDelete();

            // Snapshot of what was requested (item could change after the fact).
            $table->string('ref_code');
            $table->string('description')->nullable();
            $table->string('lot_number')->nullable();
            $table->integer('quantity');
            $table->date('expiry_date')->nullable();
            $table->decimal('unit_price', 12, 2)->nullable();
            $table->timestamps();
        });

        // Digital signatures captured against a transfer (e.g. hospital stock
        // controller signing the delivery note). Image stored on the file disk.
        Schema::create('transfer_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transfer_id')->constrained()->cascadeOnDelete();
            $table->string('signer_name');
            $table->string('signer_role')->nullable(); // hospital_controller | rep | runner
            $table->foreignId('signed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('signature_path'); // PNG on the storage disk
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('signed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transfer_signatures');
        Schema::dropIfExists('transfer_items');
        Schema::dropIfExists('transfers');
    }
};
