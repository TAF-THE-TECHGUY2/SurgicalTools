<?php

namespace App\Notifications;

use App\Models\StockCountItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Spec §4: a line flagged as a Lot/Stock Adjustment alerts admin staff the
 * moment it is created.
 *
 * In-app delivery is always immediate and per-line. Whether this also goes out
 * by email is decided by NotificationService — during a large count only the
 * first discrepancy mails immediately and the rest are digested, so a rep
 * working a shelf of mixed lots doesn't bury the admin inbox.
 */
class StockCountDiscrepancyNotification extends Notification
{
    use Queueable;

    public function __construct(
        public StockCountItem $line,
        public bool $withEmail = true,
    ) {}

    public function via(object $notifiable): array
    {
        return $this->withEmail ? ['database', 'mail'] : ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = $this->line->stockCount;

        return (new MailMessage)
            ->subject("Stock discrepancy on {$count->reference} at {$count->location}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->headline())
            ->line("Item: {$this->line->ref_code} — ".($this->line->description ?? 'unnamed'))
            ->line('Scanned lot: '.($this->line->lot_number ?? '—'))
            ->when($this->line->expected_lot_number !== null, fn (MailMessage $m) => $m
                ->line("Expected lot: {$this->line->expected_lot_number}"))
            ->action('Open count', config('app.frontend_url', env('FRONTEND_URL'))."/stock-counts/{$count->id}")
            ->line('This line is flagged for review and has not been applied to inventory.');
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->line->stockCount;

        return [
            'category'            => 'stock_count_discrepancy',
            'event'               => $this->line->adjustment_type?->value,
            'stock_count_id'      => $count->id,
            'stock_count_item_id' => $this->line->id,
            'reference'           => $count->reference,
            'location'            => $count->location,
            'ref_code'            => $this->line->ref_code,
            'lot_number'          => $this->line->lot_number,
            'expected_lot_number' => $this->line->expected_lot_number,
            'message'             => $this->headline(),
            'link'                => "/stock-counts/{$count->id}",
        ];
    }

    protected function headline(): string
    {
        $count = $this->line->stockCount;
        $label = $this->line->adjustment_type?->label() ?? 'Adjustment';

        return "{$label} on stock count {$count->reference} at {$count->location}.";
    }
}
