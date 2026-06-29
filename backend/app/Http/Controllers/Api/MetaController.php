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
