<?php

namespace App\Listeners;

use App\Enums\Role;
use App\Events\SlaBreached;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\SlaBreachedNotification;
use App\Tenancy\CurrentOrganization;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies the assignee of a breached ticket; unassigned tickets are escalated
 * to the organization's admins so nothing breaches silently.
 */
class EscalateSlaBreach implements ShouldQueue
{
    public int $tries = 3;

    public function __construct(private readonly CurrentOrganization $context) {}

    public function handle(SlaBreached $event): void
    {
        $this->context->run($event->organizationId, function () use ($event) {
            $ticket = Ticket::with('assignee')->find($event->ticketId);

            if ($ticket === null) {
                return;
            }

            $recipients = $ticket->assignee?->is_active
                ? collect([$ticket->assignee])
                : User::inCurrentOrganization()->where('role', Role::Admin)->where('is_active', true)->get();

            Notification::send($recipients, new SlaBreachedNotification($ticket, $event->metric));
        });
    }
}
