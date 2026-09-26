<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Only plain attributes of the ticket and message are used when sending, so the
 * queued job needs no tenant context to run.
 */
class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 600];

    public function __construct(public readonly Ticket $ticket, public readonly TicketMessage $message) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[#{$this->ticket->number}] {$this->ticket->subject}")
            ->line('There is a new reply on your ticket:')
            ->line(Str::limit($this->message->body, 500))
            ->line("Ticket status: {$this->ticket->status->value}");
    }
}
