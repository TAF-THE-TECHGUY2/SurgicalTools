<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Why a stock-count line was raised as an adjustment rather than counted
 * against an expected line. Every case renders with the orange highlight in
 * the UI and alerts admin staff.
 */
enum StockCountAdjustmentType: string
{
    use HasOptions;

    /** Known product at this location, but under a lot the site wasn't expecting. */
    case LotMismatch = 'lot_mismatch';

    /** Product isn't on this location's expected count at all. */
    case UnlistedItem = 'unlisted_item';

    /** Product and lot match, but the physical expiry differs from the record. */
    case ExpiryMismatch = 'expiry_mismatch';
}
