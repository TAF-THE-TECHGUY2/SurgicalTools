<?php

namespace App\Http\Controllers\Api;

use App\Enums\DoctorSpecialty;
use App\Enums\HospitalCategory;
use App\Enums\StockCountStatus;
use App\Enums\StockLocation;
use App\Enums\StockStatus;
use App\Enums\StockType;
use App\Enums\TransferStatus;
use App\Enums\TransferType;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Serves the domain vocabularies (enum options) so the SPA can render
 * dropdowns and badges without hard-coding values.
 */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'device_unit_statuses'  => \App\Enums\DeviceUnitStatus::options(),
            'location_types'        => [
                ['value' => 'hospital', 'label' => 'Hospital'],
                ['value' => 'boot', 'label' => 'Rep Boot'],
                ['value' => 'office', 'label' => 'Office'],
                ['value' => 'warehouse', 'label' => 'Warehouse'],
                ['value' => 'other', 'label' => 'Other'],
            ],
            'stock_types'           => StockType::options(),
            'hospital_stock_types'  => array_values(array_filter(
                StockType::options(),
                fn ($o) => in_array($o['value'], config('surgical.hospital_stock_types'), true),
            )),
            'locations'             => StockLocation::options(),
            'statuses'              => StockStatus::options(),
            'transfer_types'        => TransferType::options(),
            'transfer_statuses'     => TransferStatus::options(),
            'stock_count_statuses'  => StockCountStatus::options(),
            'hospital_categories'   => HospitalCategory::options(),
            'doctor_specialties'    => DoctorSpecialty::options(),
            'expiry_windows'        => config('surgical.expiry'),
        ]);
    }
}
