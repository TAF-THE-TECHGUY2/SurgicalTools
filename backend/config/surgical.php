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

    // Where transfer/delivery-note PDFs are emailed.
    'notifications' => [
        'office'               => env('MAIL_OFFICE_ADDRESS', 'office@surgicaldevices.example'),
        'stock_controller'     => env('MAIL_STOCK_CONTROLLER_ADDRESS', 'stock@surgicaldevices.example'),
        'inventory_controller' => env('MAIL_INVENTORY_CONTROLLER_ADDRESS', 'inventory@surgicaldevices.example'),
    ],
];
