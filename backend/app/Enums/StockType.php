<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StockType: string
{
    use HasOptions;

    case Consignment = 'consignment';
    case Bought      = 'bought';
    case Boot        = 'boot';
    case Loan        = 'loan';
    case Warehouse   = 'warehouse';
}
