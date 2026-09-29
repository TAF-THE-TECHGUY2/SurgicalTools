<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things an admin manages without a code release:
 *
 * - `settings`: small key/value configuration, starting with the accounts
 *   department address the variance report is mailed to.
 * - `supplier_label_templates`: how to read one supplier's label. Suppliers
 *   do not agree on where a lot or an expiry lives — Waston encodes the expiry
 *   under GS1 AI (11), which is formally the production date, and hides the
 *   lot inside the serial (21). A template maps the barcode's elements onto
 *   the four captured fields and carries hints for the photo reader.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('supplier_label_templates', function (Blueprint $table) {
            $table->id();
            $table->string('supplier');
            $table->string('name');
            // gs1 | code128 | datamatrix | none — informational for the runner.
            $table->string('barcode_type')->nullable();
            // Regex tested against the raw barcode text to pick this template.
            $table->string('match_pattern')->nullable();
            // {field: {ai?: string, pattern?: string, date_format?: string}}
            $table->json('field_mappings')->nullable();
            // Free text handed to the photo reader for this supplier's labels.
            $table->text('ocr_hints')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['supplier', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_label_templates');
        Schema::dropIfExists('settings');
    }
};
