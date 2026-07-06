<?php

namespace App\Notifications;

use App\Models\StockItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Low-stock and expiry alerts for a catalog item. `severity` is one of:
 * low_stock | warning | high | critical.
 */
class InventoryAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public StockItem $item,
        public string $alertType, // low_stock | expiry
        public string $severity,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(strtoupper($this->severity)." — {$this->item->name}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->message)
            ->line('Catalogue no: '.($this->item->catalogue_number ?? '—'))
            ->action('View stock', config('app.frontend_url', env('FRONTEND_URL')).'/stock-items');
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category'      => 'inventory',
            'alert_type'    => $this->alertType,
            'severity'      => $this->severity,
            'stock_item_id' => $this->item->id,
            'ref_code'      => $this->item->catalogue_number,
            'message'       => $this->message,
            'link'          => '/stock-items',
        ];
    }
}
