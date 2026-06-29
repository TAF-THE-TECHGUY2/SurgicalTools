<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A batch of ERP transactions exported to a CSV/Excel file for import
        // into Pastel. Tracks who exported what and links the generated file.
        Schema::create('pastel_exports', function (Blueprint $table) {
            $table->id();
            $table->string('reference')->unique(); // PEX-2026-000007
            $table->string('type')->default('transfers'); // transfers | consignment | adjustments
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->string('file_path')->nullable();
            $table->string('status')->default('generated'); // generated | imported
            $table->foreignId('exported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        // Marks which transfers have been exported, so the next export only
        // picks up new transactions.
        Schema::create('pastel_export_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pastel_export_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['pastel_export_id', 'transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pastel_export_lines');
        Schema::dropIfExists('pastel_exports');
    }
};
