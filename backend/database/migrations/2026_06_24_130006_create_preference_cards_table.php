<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A doctor's preferred setup for a given procedure — printable, used to
        // prepare a surgical case.
        Schema::create('preference_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->string('procedure_name');
            $table->text('notes')->nullable();
            $table->json('preferred_sizes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('procedure_name');
        });

        Schema::create('preference_card_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('preference_card_id')->constrained()->cascadeOnDelete();
            $table->string('ref_code')->nullable();
            $table->string('description');     // required equipment
            $table->string('preferred_size')->nullable();
            $table->integer('quantity')->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preference_card_items');
        Schema::dropIfExists('preference_cards');
    }
};
