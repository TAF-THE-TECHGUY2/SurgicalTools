<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hospitals', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique()->nullable();
            $table->string('category'); // netcare | life | government | busamed | private
            $table->string('region')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('province')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            // Primary assigned rep / runner (denormalised for quick filtering;
            // the full many-to-many assignment lives in hospital_user).
            $table->foreignId('assigned_rep_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_runner_id')->nullable()->constrained('users')->nullOnDelete();

            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('category');
            $table->index('region');
        });

        // Key contacts for a hospital (stock controller, theatre manager, etc.)
        Schema::create('hospital_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('role')->nullable(); // e.g. Stock Controller, Theatre Manager
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        // Reps / runners assigned to a hospital, linked to login accounts.
        // `role` distinguishes rep vs runner; a user can serve both functions
        // across different hospitals.
        Schema::create('hospital_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('hospital_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('rep'); // rep | runner
            $table->timestamps();

            $table->unique(['hospital_id', 'user_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hospital_user');
        Schema::dropIfExists('hospital_contacts');
        Schema::dropIfExists('hospitals');
    }
};
