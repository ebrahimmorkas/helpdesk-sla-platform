<?php

namespace App\Notifications;

use App\Enums\SlaMetric;
use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly Ticket $ticket, public readonly SlaMetric $metric) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $target = $this->metric === SlaMetric::FirstResponse ? 'first response' : 'resolution';

        return (new MailMessage)
            ->error()
            ->subject("SLA breached: #{$this->ticket->number} missed its {$target} target")
            ->line("{$this->ticket->subject} ({$this->ticket->priority->value} priority)")
            ->line("The {$target} target has passed without being met.");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'sla_breached',
            'ticket_number' => $this->ticket->number,
            'metric' => $this->metric->value,
            'priority' => $this->ticket->priority->value,
        ];
    }
}
