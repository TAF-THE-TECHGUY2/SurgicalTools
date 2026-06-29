<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('age')->nullable();
            $table->string('specialty')->nullable(); // general_surgeon | gynaecologist | other
            $table->json('operating_days')->nullable(); // ["monday","wednesday"]
            $table->json('equipment_used')->nullable();
            $table->text('procedure_preferences')->nullable();
            $table->text('notes')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('specialty');
        });

        // Doctors practise at multiple hospitals.
        Schema::create('doctor_hospital', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_id')->constrained()->cascadeOnDelete();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['doctor_id', 'hospital_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_hospital');
        Schema::dropIfExists('doctors');
    }
};
