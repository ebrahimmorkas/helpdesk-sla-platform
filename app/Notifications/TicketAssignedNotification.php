<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly Ticket $ticket)
    {
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $due = $this->ticket->resolution_due_at?->toDayDateTimeString() ?? 'no SLA';

        return (new MailMessage)
            ->subject("Ticket #{$this->ticket->number} assigned to you")
            ->line("{$this->ticket->subject} ({$this->ticket->priority->value} priority)")
            ->line("Resolution due: {$due} UTC");
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'ticket_assigned',
            'ticket_number' => $this->ticket->number,
            'subject' => $this->ticket->subject,
            'priority' => $this->ticket->priority->value,
        ];
    }
}
