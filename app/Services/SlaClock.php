<?php

namespace App\Services;

use App\Models\Ticket;
use App\Support\BusinessCalendar;
use DateTimeInterface;

/**
 * Keeps a ticket's SLA due dates in line with its priority, the organization's
 * business hours and the time spent waiting on the customer.
 *
 *   first_response_due_at = created_at + first_response target   (business time)
 *   resolution_due_at     = created_at + resolution target + paused minutes
 *
 * The first-response clock never pauses: a ticket can only wait on the customer
 * after an agent has replied.
 */
class SlaClock
{
    /** Set both due dates from the current priority. Call on create and on priority change. */
    public function schedule(Ticket $ticket): void
    {
        $organization = $ticket->organization;
        $policy = $organization->slaPolicies->firstWhere('priority', $ticket->priority);

        if ($policy === null) {
            $ticket->first_response_due_at = null;
            $ticket->resolution_due_at = null;

            return;
        }

        $calendar = BusinessCalendar::forOrganization($organization);

        $ticket->first_response_due_at = $calendar->addMinutes($ticket->created_at, $policy->first_response_minutes);
        $ticket->resolution_due_at = $calendar->addMinutes(
            $ticket->created_at,
            $policy->resolution_minutes + $ticket->sla_paused_minutes,
        );
    }

    public function pause(Ticket $ticket, DateTimeInterface $at): void
    {
        $ticket->sla_paused_at ??= $at;
    }

    /** Add the business time spent waiting to the total and move the due date. */
    public function resume(Ticket $ticket, DateTimeInterface $at): void
    {
        if ($ticket->sla_paused_at === null) {
            return;
        }

        $calendar = BusinessCalendar::forOrganization($ticket->organization);
        $ticket->sla_paused_minutes += $calendar->minutesBetween($ticket->sla_paused_at, $at);
        $ticket->sla_paused_at = null;

        $this->schedule($ticket);
    }
}
