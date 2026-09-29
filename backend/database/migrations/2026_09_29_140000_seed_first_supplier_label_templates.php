<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The first two supplier label templates, taken from sample labels. Seeded
 * by migration rather than seeder so production gets them; inserted only if
 * absent, so an admin's later edits survive a re-run.
 *
 * Waston (Changzhou Waston Medical, e.g. Endoscopic Linear Cutter II):
 *   Two linear barcodes, neither standard.
 *   - "(00) 6 9365944 012019101 3": an 18-digit SSCC field that in fact
 *     carries the supplier's product Code (12019101) at digits 10-17.
 *   - "(11) 260206 (21) HSDS2302020062": (11) is formally the production
 *     date but holds the EXPIRY printed beside the hourglass (2026-02-06);
 *     there is no lot AI — the lot HSDS230202 is the serial minus its last
 *     four digits (the SN, 0062).
 *   The runner reads both barcodes into one capture.
 *
 * Surgical Devices own brand (made by Hangzhou Tonglu Yida): no barcode at
 * all, so only photo-reader hints apply.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $templates = [
            [
                'supplier'       => 'WASTON',
                'name'           => 'Waston two-barcode label',
                'barcode_type'   => 'code128',
                'match_pattern'  => '^(?:\]C1)?\(?00\)?6936594|^(?:\]C1)?\(?11\)?\d{6}\(?21\)?[A-Z]{2,}\d{10,}$',
                'field_mappings' => json_encode([
                    'ref'           => ['ai' => '00', 'pattern' => '^\d{9}(\d{8})\d$'],
                    'expiry_date'   => ['ai' => '11', 'date_format' => 'YYMMDD'],
                    'lot_number'    => ['ai' => '21', 'pattern' => '^(.+?)\d{4}$'],
                    'serial_number' => ['ai' => '21', 'pattern' => '(\d{4})$'],
                ]),
                'ocr_hints'      => 'The boxed "Code" (e.g. 12019101) is the product code to report as ref; the "REF:" line (e.g. HSD-S) is a model name. '
                    .'The lot is beside the LOT box; the hourglass date is the expiry.',
            ],
            [
                'supplier'       => 'SURGICAL DEVICES',
                'name'           => 'Own-brand label (no barcode)',
                'barcode_type'   => 'none',
                'match_pattern'  => null,
                'field_mappings' => null,
                'ocr_hints'      => 'Blue SD label with no barcode. REF is printed as "REF: 533-005-925" — report it with its hyphens. '
                    .'The lot is beside the LOT box. The hourglass date is the expiry; the date beside the factory symbol is the manufacture date. Ignore SIZE and QTY.',
            ],
        ];

        foreach ($templates as $template) {
            $exists = DB::table('supplier_label_templates')
                ->where('supplier', $template['supplier'])
                ->where('name', $template['name'])
                ->exists();

            if (! $exists) {
                DB::table('supplier_label_templates')->insert([
                    ...$template, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('supplier_label_templates')
            ->whereIn('name', ['Waston two-barcode label', 'Own-brand label (no barcode)'])
            ->delete();
    }
};
