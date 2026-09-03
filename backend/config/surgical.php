<?php

/*
|--------------------------------------------------------------------------
| Surgical Devices ERP — domain configuration
|--------------------------------------------------------------------------
|
| Central source of truth for the domain vocabularies (stock types,
| locations, statuses, roles) and operational thresholds. Enum classes in
| app/Enums reference these where helpful; keeping them here lets ops tune
| thresholds via env without a deploy.
*/

return [

    'roles' => [
        'super_admin'  => 'Super Admin',
        'admin'        => 'Admin User',
        'general_user' => 'General User',
    ],

    'stock_types' => [
        'consignment',
        'bought',
        'boot',
        'loan',
        'warehouse',
    ],

    // Stock types a rep may assign when delivering to a hospital (Transfer 2).
    'hospital_stock_types' => [
        'consignment',
        'bought',
        'loan',
    ],

    'locations' => [
        'ordered',
        'supplier',
        'in_transit',
        'jhb_master_warehouse',
        'durban_master_warehouse',
        'boot_stock',
        'hospital_stock',
        'loan_stock',
    ],

    'statuses' => [
        'available',
        'reserved',
        'ordered',
        'in_transit',
        'delivered',
        'expired',
        'damaged',
        'quarantined',
    ],

    'transfer_types' => [
        'source_to_boot'   => 'Source → Boot',   // Transfer 1
        'boot_to_hospital' => 'Boot → Hospital',  // Transfer 2
    ],

    'transfer_statuses' => [
        'draft',
        'pending_approval',
        'approved',
        'awaiting_signature',
        'signed',
        'awaiting_admin_review',
        'completed',
        'rejected',
    ],

    'stock_count_statuses' => [
        'requested',
        'in_progress',
        'submitted',
        'under_review',
        'approved',
        'investigating',
    ],

    'hospital_categories' => [
        'netcare',
        'life',
        'government',
        'busamed',
        'private',
    ],

    'doctor_specialties' => [
        'general_surgeon',
        'gynaecologist',
        'other',
    ],

    // Expiry alert windows, in days. Daily scheduled command compares against
    // these to escalate warning → high → critical.
    'expiry' => [
        'warning'  => (int) env('EXPIRY_WARNING_DAYS', 90),
        'high'     => (int) env('EXPIRY_HIGH_DAYS', 60),
        'critical' => (int) env('EXPIRY_CRITICAL_DAYS', 30),
    ],

    'low_stock_default_threshold' => (int) env('LOW_STOCK_DEFAULT_THRESHOLD', 5),

    'voucher' => [
        /*
        | Where the digital "Stock Movement / Delivery Voucher" sequence
        | starts. The paper pads run a bare six-digit serial (130101, 130118…),
        | so this must be set above every number still outstanding in a rep's
        | car — otherwise a digital voucher can duplicate a written one.
        |
        | CONFIRM WITH OPERATIONS BEFORE GOING LIVE.
        */
        'start_number' => (int) env('VOUCHER_START_NUMBER', 130119),
    ],

    'stock_count' => [
        /*
        | Spec §4 asks for an admin email the moment any line is flagged. Taken
        | literally that is one email per scan, which floods the inbox during a
        | large count. The first discrepancy on a count mails immediately and
        | the rest are coalesced into a digest sent this many minutes later.
        |
        | Set to 0 for the literal behaviour: every flagged line mails at once.
        | In-app notifications are always immediate and per-line either way.
        */
        'discrepancy_digest_minutes' => (int) env('STOCK_COUNT_DIGEST_MINUTES', 5),
    ],

    /*
    | Label scanning. Barcodes (GS1 DataMatrix / Code 128) are the primary
    | extraction path and need no configuration — they are decoded in the
    | browser and parsed deterministically. The vision fallback below is only
    | used for labels with no readable barcode; leave the key unset to disable
    | it without affecting barcode scanning.
    */
    'ocr' => [
        'api_key'        => env('ANTHROPIC_API_KEY'),
        'model'          => env('OCR_MODEL', 'claude-opus-5'),
        // Extractions at or below this are held for the runner to confirm.
        'min_confidence' => (float) env('OCR_MIN_CONFIDENCE', 0.8),
        'timeout'        => (int) env('OCR_TIMEOUT_SECONDS', 45),
    ],

    // Where transfer/delivery-note PDFs are emailed.
    'notifications' => [
        'office'               => env('MAIL_OFFICE_ADDRESS', 'office@surgicaldevices.example'),
        'stock_controller'     => env('MAIL_STOCK_CONTROLLER_ADDRESS', 'stock@surgicaldevices.example'),
        'inventory_controller' => env('MAIL_INVENTORY_CONTROLLER_ADDRESS', 'inventory@surgicaldevices.example'),
    ],
];
