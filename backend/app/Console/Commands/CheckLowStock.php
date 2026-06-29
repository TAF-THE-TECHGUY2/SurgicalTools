<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Low-stock check (Module 11). Flags any holding at or below its minimum
 * threshold (or the configured default when none is set).
 */
class CheckLowStock extends Command
{
    protected $signature = 'surgical:check-low-stock';

    protected $description = 'Notify staff of inventory at or below its minimum threshold.';

    public function handle(NotificationService $notifications): int
    {
        $items = InventoryItem::query()->lowStock()->get();

        foreach ($items as $item) {
            $notifications->inventoryAlert(
                $item,
                'low_stock',
                'low_stock',
                "{$item->ref_code} is low: {$item->quantity} on hand"
                    .($item->min_threshold ? " (threshold {$item->min_threshold})" : '').'.',
            );
        }

        $this->info('Low-stock check complete. '.$items->count().' item(s) flagged.');

        return self::SUCCESS;
    }
}
