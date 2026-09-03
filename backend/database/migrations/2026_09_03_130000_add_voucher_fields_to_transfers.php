<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Digitises the paper "Stock Movement / Delivery Voucher".
 *
 * `reference` (TR-2026-000001) stays the internal key. `voucher_number` is the
 * customer-facing one and continues the physical pad's bare six-digit serial,
 * so a delivery can be traced across paper and digital records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->string('voucher_number')->nullable()->unique()->after('reference');
            $table->date('transfer_date')->nullable()->after('voucher_number');
            $table->string('invoice_reference')->nullable()->after('transfer_date');

            // Snapshotted from the destination hospital at request time: the
            // paper voucher records where the stock actually went, and a later
            // edit to the hospital record must not rewrite history.
            $table->text('delivery_address')->nullable()->after('invoice_reference');
            $table->string('contact_person_name')->nullable()->after('delivery_address');

            // Captured at handover, alongside the recipient's signature.
            $table->string('recipient_name')->nullable()->after('contact_person_name');
            $table->timestamp('delivery_timestamp')->nullable()->after('recipient_name');
        });

        Schema::table('transfer_items', function (Blueprint $table) {
            // A scanned item the dispatch list did not authorise. Carries no
            // device_unit_id — there is no reserved unit behind it.
            $table->boolean('is_transfer_adjustment')->default(false);
            $table->string('adjustment_type')->nullable();
            $table->string('expected_lot_number')->nullable();

            $table->index(['transfer_id', 'is_transfer_adjustment']);
        });
    }

    public function down(): void
    {
        Schema::table('transfer_items', function (Blueprint $table) {
            $table->dropIndex(['transfer_id', 'is_transfer_adjustment']);
            $table->dropColumn(['is_transfer_adjustment', 'adjustment_type', 'expected_lot_number']);
        });

        Schema::table('transfers', function (Blueprint $table) {
            $table->dropUnique(['voucher_number']);
            $table->dropColumn([
                'voucher_number', 'transfer_date', 'invoice_reference',
                'delivery_address', 'contact_person_name', 'recipient_name',
                'delivery_timestamp',
            ]);
        });
    }
};
