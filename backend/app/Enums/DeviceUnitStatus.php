<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum DeviceUnitStatus: string
{
    use HasOptions;

    case Available       = 'available';
    case PendingTransfer = 'pending_transfer';
    case Missing         = 'missing';
    case Used            = 'used';
    case Expired         = 'expired';
    case Archived        = 'archived';
}
