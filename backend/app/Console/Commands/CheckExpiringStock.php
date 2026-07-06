<?php

namespace App\Console\Commands;

use App\Enums\DeviceUnitStatus;
use App\Models\DeviceUnit;
use App\Services\NotificationService;
use Illuminate\Console\Command;

/**
 * Daily expiry check (Module 10). Escalates alerts as expiry approaches:
 *   ≤ 90 days → warning,  ≤ 60 days → high,  ≤ 30 days → critical.
 */
class CheckExpiringStock extends Command
{
    protected $signature = 'surgical:check-expiring-stock';

    protected $description = 'Notify staff of device units approaching expiry (90/60/30 day windows).';

    public function handle(NotificationService $notifications): int
    {
        $windows = config('surgical.expiry');

        // Group expiring units per stock item + lot so one alert covers a batch.
        $units = DeviceUnit::with(['stockItem', 'location'])
            ->whereIn('status', [DeviceUnitStatus::Available->value, DeviceUnitStatus::PendingTransfer->value])
            ->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', now()->addDays($windows['warning']))
            ->whereDate('expiry_date', '>=', now())
            ->get()
            ->groupBy(fn (DeviceUnit $u) => $u->stock_item_id.'|'.$u->lot_number);

        $sent = 0;
        foreach ($units as $group) {
            /** @var DeviceUnit $first */
            $first = $group->first();
            if (! $first->stockItem) {
                continue;
            }

            $days = $first->days_to_expiry;
            $severity = match (true) {
                $days <= $windows['critical'] => 'critical',
                $days <= $windows['high']     => 'high',
                default                       => 'warning',
            };

            $notifications->stockAlert(
                $first->stockItem,
                'expiry',
                $severity,
                "{$group->count()} unit(s) of {$first->stockItem->name} (lot ".($first->lot_number ?? '—').
                    ") expire in {$days} days — {$severity} priority.",
            );
            $sent++;
        }

        $this->info("Expiry check complete. {$sent} batch(es) flagged.");

        return self::SUCCESS;
    }
}
