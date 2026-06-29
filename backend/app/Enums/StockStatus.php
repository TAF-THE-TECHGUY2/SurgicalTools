<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StockStatus: string
{
    use HasOptions;

    case Available   = 'available';
    case Reserved    = 'reserved';
    case Ordered     = 'ordered';
    case InTransit   = 'in_transit';
    case Delivered   = 'delivered';
    case Expired     = 'expired';
    case Damaged     = 'damaged';
    case Quarantined = 'quarantined';
}
