<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

enum StockCountStatus: string
{
    use HasOptions;

    case Requested     = 'requested';
    case InProgress    = 'in_progress';
    case Submitted     = 'submitted';
    case UnderReview   = 'under_review';
    case Approved      = 'approved';
    case Investigating = 'investigating';
}
