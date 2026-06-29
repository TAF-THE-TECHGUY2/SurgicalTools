<?php

namespace App\Notifications;

use App\Models\InventoryItem;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Low-stock and expiry alerts. `severity` is one of:
 * low_stock | warning | high | critical.
 */
class InventoryAlertNotification extends Notification
{
    use Queueable;

    public function __construct(
        public InventoryItem $item,
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
            ->subject(strtoupper($this->severity)." — {$this->item->ref_code}")
            ->greeting("Hi {$notifiable->name},")
            ->line($this->message)
            ->line("Ref: {$this->item->ref_code}  |  Lot: ".($this->item->lot_number ?? 'N/A'))
            ->line("Quantity on hand: {$this->item->quantity}")
            ->action('View item', config('app.frontend_url', env('FRONTEND_URL')).'/inventory/'.$this->item->id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category'           => 'inventory',
            'alert_type'         => $this->alertType,
            'severity'           => $this->severity,
            'inventory_item_id'  => $this->item->id,
            'ref_code'           => $this->item->ref_code,
            'message'            => $this->message,
            'link'               => "/inventory/{$this->item->id}",
        ];
    }
}
