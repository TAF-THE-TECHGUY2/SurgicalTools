<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StockLocation: string
{
    use HasOptions;

    case Ordered                = 'ordered';
    case Supplier               = 'supplier';
    case InTransit              = 'in_transit';
    case JhbMasterWarehouse     = 'jhb_master_warehouse';
    case DurbanMasterWarehouse  = 'durban_master_warehouse';
    case BootStock              = 'boot_stock';
    case HospitalStock          = 'hospital_stock';
    case LoanStock              = 'loan_stock';

    public function label(): string
    {
        return match ($this) {
            self::JhbMasterWarehouse    => 'JHB Master Warehouse',
            self::DurbanMasterWarehouse => 'Durban Master Warehouse',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }
}
