<?php

namespace App\Notifications;

use App\Models\Transfer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * In-app + email notification for any transfer lifecycle event
 * (submitted for approval, approved, rejected, awaiting signature, completed).
 */
class TransferStatusNotification extends Notification
{
    use Queueable;

    public function __construct(
        public Transfer $transfer,
        public string $event,   // e.g. "pending_approval", "approved", "completed"
        public string $message,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Transfer {$this->transfer->reference}: ".str_replace('_', ' ', $this->event))
            ->greeting("Hi {$notifiable->name},")
            ->line($this->message)
            ->action('Open transfer', config('app.frontend_url', env('FRONTEND_URL')).'/transfers/'.$this->transfer->id);
    }

    public function toArray(object $notifiable): array
    {
        return [
            'category'     => 'transfer',
            'event'        => $this->event,
            'transfer_id'  => $this->transfer->id,
            'reference'    => $this->transfer->reference,
            'type'         => $this->transfer->type->value,
            'message'      => $this->message,
            'link'         => "/transfers/{$this->transfer->id}",
        ];
    }
}
