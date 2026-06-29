<?php

namespace App\Notifications;

use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** In-app + email notification for stock-count lifecycle events (submitted, reviewed). */
class StockCountStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public StockCount $count,
        public string $event,
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Stock count {$this->count->reference}: ".str_replace('_', ' ', $this->event))
            ->greeting("Hi {$notifiable->name},")
            ->line($this->message)
            ->action('Review count', config('app.frontend_url', env('FRONTEND_URL')).'/stock-counts/'.$this->count->id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category'       => 'stock_count',
            'event'          => $this->event,
            'stock_count_id' => $this->count->id,
            'reference'      => $this->count->reference,
            'message'        => $this->message,
            'link'           => "/stock-counts/{$this->count->id}",
        ];
    }
}
