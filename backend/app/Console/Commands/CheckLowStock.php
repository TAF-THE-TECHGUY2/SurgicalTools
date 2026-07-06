<?php

namespace App\Console\Commands;

use App\Models\StockItem;
use App\Services\InventoryService;
use Illuminate\Console\Command;

/**
 * Low-stock check (Module 11). Flags catalog items whose total on-hand unit
 * count is at or below their minimum threshold. (Real-time alerts also fire
 * on each movement; this daily sweep is the safety net.)
 */
class CheckLowStock extends Command
{
    protected $signature = 'surgical:check-low-stock';

    protected $description = 'Notify staff of stock items at or below their minimum threshold.';

    public function handle(InventoryService $inventory): int
    {
        $items = StockItem::whereNotNull('min_threshold')->where('is_active', true)->get();

        $flagged = 0;
        foreach ($items as $item) {
            $before = $flagged;
            $inventory->maybeAlertLowStock($item);
            // maybeAlertLowStock decides internally; count items checked instead.
            $flagged = $before + 1;
        }

        $this->info('Low-stock check complete. '.$items->count().' item(s) checked.');

        return self::SUCCESS;
    }
}
