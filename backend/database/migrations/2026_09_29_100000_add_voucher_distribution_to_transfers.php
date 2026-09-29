<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional distribution for the completed voucher (dev-spec §5).
 *
 * The spec puts these behind a modal on "Finalize & Send". In this workflow
 * the rep finalises at hand-over — when the recipient is standing in front of
 * them and can give an address — while the PDF is produced later, on approval.
 * So the choices are captured at signing and honoured when the document is
 * actually sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            // "Send copy to Recipient Email" — typed in at hand-over.
            $table->string('recipient_email')->nullable()->after('recipient_name');
            // "Send copy to Representative Email" — the requesting rep. On by
            // default: the rep who raised the voucher normally wants the copy.
            $table->boolean('copy_to_rep')->default(true)->after('recipient_email');
        });
    }

    public function down(): void
    {
        Schema::table('transfers', function (Blueprint $table) {
            $table->dropColumn(['recipient_email', 'copy_to_rep']);
        });
    }
};
