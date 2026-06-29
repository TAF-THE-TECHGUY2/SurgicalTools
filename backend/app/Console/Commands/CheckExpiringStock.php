<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Daily expiry check (Module 10). Escalates alerts as expiry approaches:
 *   ≤ 90 days → warning,  ≤ 60 days → high,  ≤ 30 days → critical.
 */
class CheckExpiringStock extends Command
{
    protected $signature = 'surgical:check-expiring-stock';

    protected $description = 'Notify staff of stock approaching expiry (90/60/30 day windows).';

    public function handle(NotificationService $notifications): int
    {
        $windows = config('surgical.expiry');

        $items = InventoryItem::query()
            ->whereNotNull('expiry_date')
            ->expiringWithin($windows['warning'])
            ->where('quantity', '>', 0)
            ->get();

        $sent = 0;
        foreach ($items as $item) {
            $days = $item->days_to_expiry;
            $severity = match (true) {
                $days <= $windows['critical'] => 'critical',
                $days <= $windows['high']     => 'high',
                default                       => 'warning',
            };

            $notifications->inventoryAlert(
                $item,
                'expiry',
                $severity,
                "{$item->ref_code} (lot {$item->lot_number}) expires in {$days} days — {$severity} priority.",
            );
            $sent++;
        }

        $this->info("Expiry check complete. {$sent} item(s) flagged.");

        return self::SUCCESS;
    }
}
