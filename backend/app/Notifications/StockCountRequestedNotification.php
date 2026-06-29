<?php

namespace App\Notifications;

use App\Models\StockCount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class StockCountRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(public StockCount $count) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Stock count requested: {$this->count->reference}")
            ->greeting("Hi {$notifiable->name},")
            ->line('A stock count has been requested and assigned to you.')
            ->line('Location: '.($this->count->location ?? 'N/A'))
            ->action('Open count', config('app.frontend_url', env('FRONTEND_URL')).'/stock-counts/'.$this->count->id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category'        => 'stock_count',
            'event'           => 'requested',
            'stock_count_id'  => $this->count->id,
            'reference'       => $this->count->reference,
            'message'         => "Stock count {$this->count->reference} assigned to you.",
            'link'            => "/stock-counts/{$this->count->id}",
        ];
    }
}
